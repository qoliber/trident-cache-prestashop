<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Delivery;

use Qoliber\Trident\Delivery\Backoff;
use Qoliber\Trident\Delivery\OutboxEntry;
use Qoliber\Trident\Delivery\NotBeforeStore;

/**
 * The purge outbox on PrestaShop's database (`PREFIX_trident_purge_outbox`):
 * the library's store contract. A row is removed only when its instance
 * acknowledged the purge; a failure keeps it with a backoff.
 *
 * PrestaShop saves objects without a surrounding transaction, so rows are
 * written right after the change, in the same request (the hooks run
 * straight after the save). Instance names are compared case-sensitively
 * (a binary collation), as on every other platform.
 */
class PsOutboxStore implements NotBeforeStore
{
    public const TABLE = 'trident_purge_outbox';
    public const CHUNK = 1000;

    public function __construct(private readonly \Db $db)
    {
    }

    public static function table(): string
    {
        return _DB_PREFIX_ . self::TABLE;
    }

    public static function install(): bool
    {
        return \Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . self::table() . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `instance` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            `tags` MEDIUMTEXT NOT NULL,
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` INT UNSIGNED NOT NULL,
            `next_attempt_at` INT UNSIGNED NOT NULL,
            `scheduled` TINYINT(1) NOT NULL DEFAULT 0,
            `not_before` TINYINT(1) NOT NULL DEFAULT 0,
            `last_error` TEXT NULL,
            `last_error_at` INT UNSIGNED NULL,
            PRIMARY KEY (`id`),
            KEY `due` (`next_attempt_at`),
            KEY `instance` (`instance`, `scheduled`, `next_attempt_at`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /**
     * Bring an older table up to date (module upgrade): the `not_before`
     * column of 1.0.1. Portable across MySQL and MariaDB.
     */
    public static function ensureSchema(): bool
    {
        $db = \Db::getInstance();
        $has = $db->executeS('SHOW COLUMNS FROM `' . self::table() . '` LIKE \'not_before\'');
        if (\is_array($has) && $has !== []) {
            return true;
        }

        return $db->execute('ALTER TABLE `' . self::table() . '` ADD COLUMN `not_before` TINYINT(1) NOT NULL DEFAULT 0 AFTER `scheduled`');
    }

    public function recordNotBefore(string $instance, array $tags, int $now, int $notBefore): int
    {
        $json = (string) json_encode(array_values($tags), \JSON_THROW_ON_ERROR);
        if ($this->db->getValue('SELECT id FROM `' . self::table() . '` WHERE instance = \'' . pSQL($instance) . '\''
            . ' AND not_before = 1 AND next_attempt_at = ' . $notBefore . ' AND tags = \'' . pSQL($json) . '\'')) {
            return 0;
        }
        $ok = $this->db->insert(self::TABLE, [
            'instance' => pSQL($instance),
            'tags' => pSQL($json),
            'attempts' => 0,
            'created_at' => $now,
            'next_attempt_at' => $notBefore,
            'scheduled' => 1,
            'not_before' => 1,
        ]);
        if (!$ok) {
            throw new \RuntimeException('outbox insert failed: ' . $this->db->getMsgError());
        }

        return 1;
    }

    public function dueEarly(int $limit, int $now, array $instances): array
    {
        if ($instances === []) {
            return [];
        }
        $in = implode(',', array_map(static fn (string $i): string => '\'' . pSQL($i) . '\'', array_values($instances)));

        return $this->entries($this->rows(
            'SELECT id, instance, tags, attempts FROM `' . self::table() . '` WHERE instance IN (' . $in . ')'
            . ' AND NOT (not_before = 1 AND next_attempt_at > ' . $now . ') ORDER BY id ASC LIMIT ' . max(1, $limit)
        ));
    }

    public static function uninstall(): bool
    {
        return \Db::getInstance()->execute('DROP TABLE IF EXISTS `' . self::table() . '`');
    }

    public function record(string $instance, array $tags, int $now, int $dueAt): int
    {
        $ok = $this->db->insert(self::TABLE, [
            'instance' => pSQL($instance),
            'tags' => pSQL((string) json_encode(array_values($tags), \JSON_THROW_ON_ERROR)),
            'attempts' => 0,
            'created_at' => $now,
            'next_attempt_at' => $dueAt,
        ]);
        if (!$ok) {
            throw new \RuntimeException('outbox insert failed: ' . $this->db->getMsgError());
        }

        return (int) $this->db->Insert_ID();
    }

    /**
     * @param int|null $coversFrom the earliest moment the purge must happen
     *                             (the save for a backstop: $now; the start of
     *                             a scheduled price: $dueAt). An identical row
     *                             already due within [$coversFrom, $dueAt]
     *                             does the same job, so none is added.
     */
    public function recordScheduled(string $instance, array $tags, int $now, int $dueAt, ?int $coversFrom = null): int
    {
        $json = (string) json_encode(array_values($tags), \JSON_THROW_ON_ERROR);
        $from = max($now, $coversFrom ?? $now);
        $exists = $this->db->getValue('SELECT id FROM `' . self::table() . '` WHERE instance = \'' . pSQL($instance) . '\''
            . ' AND scheduled = 1 AND attempts = 0 AND next_attempt_at >= ' . $from . ' AND next_attempt_at <= ' . $dueAt
            . ' AND tags = \'' . pSQL($json) . '\'');
        if ($exists) {
            return 0;
        }
        $ok = $this->db->insert(self::TABLE, [
            'instance' => pSQL($instance),
            'tags' => pSQL($json),
            'attempts' => 0,
            'created_at' => $now,
            'next_attempt_at' => $dueAt,
            'scheduled' => 1,
        ]);
        if (!$ok) {
            throw new \RuntimeException('outbox insert failed: ' . $this->db->getMsgError());
        }

        return 1;
    }

    public function byIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            array_push($out, ...$this->entries($this->rows(
                'SELECT id, instance, tags, attempts FROM `' . self::table() . '` WHERE id IN (' . implode(',', $chunk) . ') ORDER BY id ASC'
            )));
        }

        return $out;
    }

    public function due(int $limit, int $now, bool $ignoreBackoff, array $instances): array
    {
        if ($instances === []) {
            return [];
        }
        $in = implode(',', array_map(static fn (string $i): string => '\'' . pSQL($i) . '\'', array_values($instances)));
        $where = $ignoreBackoff ? '(next_attempt_at <= ' . $now . ' OR attempts > 0)' : 'next_attempt_at <= ' . $now;

        return $this->entries($this->rows(
            'SELECT id, instance, tags, attempts FROM `' . self::table() . '` WHERE instance IN (' . $in . ') AND ' . $where
            . ' ORDER BY id ASC LIMIT ' . max(1, $limit)
        ));
    }

    public function remove(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $this->db->execute('DELETE FROM `' . self::table() . '` WHERE id IN (' . implode(',', $chunk) . ')');
        }
    }

    public function fail(array $entries, string $reason, int $now): void
    {
        $byAttempts = [];
        foreach ($entries as $entry) {
            $byAttempts[$entry->attempts][] = (int) $entry->id;
        }
        foreach ($byAttempts as $attempts => $ids) {
            $failures = (int) $attempts + 1;
            $this->db->execute('UPDATE `' . self::table() . '` SET attempts = ' . $failures
                . ', next_attempt_at = ' . Backoff::nextAttemptAt($failures, $now)
                . ', last_error = \'' . pSQL(mb_substr($reason, 0, 1000)) . '\', last_error_at = ' . $now
                . ' WHERE id IN (' . implode(',', $ids) . ')');
        }
    }

    public function forget(string $instance): int
    {
        $this->db->execute('DELETE FROM `' . self::table() . '` WHERE instance = \'' . pSQL($instance) . '\'');

        return (int) $this->db->Affected_Rows();
    }

    public function stats(int $now): array
    {
        $scheduled = 'scheduled = 1 AND attempts = 0 AND next_attempt_at > ' . $now;
        $row = $this->db->getRow('SELECT COUNT(*) AS pending, MIN(created_at) AS oldest FROM `' . self::table() . '` WHERE NOT (' . $scheduled . ')') ?: [];
        $scheduledCount = (int) $this->db->getValue('SELECT COUNT(*) FROM `' . self::table() . '` WHERE ' . $scheduled);
        $failure = $this->db->getRow('SELECT last_error, last_error_at FROM `' . self::table() . '` WHERE last_error IS NOT NULL ORDER BY last_error_at DESC, id DESC') ?: [];
        $byInstance = [];
        foreach ($this->rows('SELECT instance, COUNT(*) AS n FROM `' . self::table() . '` WHERE NOT (' . $scheduled . ') GROUP BY instance') as $group) {
            $byInstance[(string) $group['instance']] = (int) $group['n'];
        }
        ksort($byInstance);

        return [
            'pending' => (int) ($row['pending'] ?? 0),
            'scheduled' => $scheduledCount,
            'oldest_age' => isset($row['oldest']) ? max(0, $now - (int) $row['oldest']) : null,
            'last_error' => isset($failure['last_error']) ? (string) $failure['last_error'] : null,
            'last_error_at' => isset($failure['last_error_at']) ? (int) $failure['last_error_at'] : null,
            'by_instance' => $byInstance,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        $rows = $this->db->executeS($sql);

        return \is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<OutboxEntry>
     */
    private function entries(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $tags = json_decode((string) $row['tags'], true);
            $entries[] = new OutboxEntry(
                (int) $row['id'],
                (string) $row['instance'],
                \is_array($tags) ? array_values(array_map('strval', $tags)) : [],
                (int) $row['attempts'],
            );
        }

        return $entries;
    }
}
