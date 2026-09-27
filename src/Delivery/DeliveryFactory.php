<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Delivery;

use Qoliber\Trident\Config\Settings;
use Qoliber\Trident\Delivery\OutboxDelivery;
use Qoliber\Trident\Delivery\Packer;
use Qoliber\Trident\Http\TransportFactory;
use Qoliber\Trident\Tags\TagPolicy;
use Qoliber\TridentPrestaShop\Config\SettingsProvider;

/**
 * One {@see OutboxDelivery} per request (or CLI run). What a request recorded
 * is delivered after the response has gone to the visitor
 * (`fastcgi_finish_request`), and a throttled drain of anything still owed
 * follows — PrestaShop has no scheduler, so back-office and storefront
 * traffic plus the cron URL / CLI command deliver the backlog.
 */
final class DeliveryFactory
{
    /** Seconds between opportunistic end-of-request drains. */
    public const DRAIN_EVERY = 30;
    public const DRAIN_LIMIT = 50;

    private static ?self $instance = null;
    private ?OutboxDelivery $delivery = null;
    private bool $flushRegistered = false;
    private bool $drainRegistered = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Settings changed (the back office saved them): start over. */
    public static function reset(): void
    {
        self::$instance = null;
        SettingsProvider::reset();
    }

    public static function policy(): TagPolicy
    {
        return new TagPolicy(SettingsProvider::get()->tagPrefix, [
            '/^p_/' => 'p_overflow',
        ], ['pp_', 'c_', 'cms_', 'm_', 's_', 'home']);
    }

    public function delivery(): OutboxDelivery
    {
        return $this->delivery ??= new OutboxDelivery(
            new PsOutboxStore(\Db::getInstance()),
            SettingsProvider::get(),
            self::policy(),
            (new TransportFactory())->transport(),
            null,
            null,
            new PsLogger(),
        );
    }

    /**
     * A purge due LATER, for every instance: the durability backstop (a save
     * whose After hook never ran) and scheduled prices. Deduplicated by the
     * store; not owed until due.
     *
     * @param list<string> $tags unprefixed
     */
    public function schedule(array $tags, int $dueAt, ?int $coversFrom = null): int
    {
        $settings = SettingsProvider::get();
        $final = $this->delivery()->purgeTags($tags);
        if ($final === [] || !$settings->enabled()) {
            return 0;
        }
        $store = new PsOutboxStore(\Db::getInstance());
        $n = 0;
        foreach ($settings->instanceNames() as $name) {
            try {
                foreach (Packer::chunk($final) as $chunk) {
                    $n += $store->recordScheduled($name, $chunk, time(), $dueAt, $coversFrom);
                }
            } catch (\Throwable $e) {
                (new PsLogger())->error('could not schedule a purge', ['error' => $e->getMessage()]);
            }
        }

        return $n;
    }

    /**
     * A purge that must not happen before $at (a scheduled price's start or
     * end): never delivered early, not even by "deliver now".
     *
     * @param list<string> $tags unprefixed
     */
    public function notBefore(array $tags, int $at): int
    {
        $settings = SettingsProvider::get();
        $final = $this->delivery()->purgeTags($tags);
        if ($final === [] || !$settings->enabled()) {
            return 0;
        }
        $store = new PsOutboxStore(\Db::getInstance());
        $n = 0;
        foreach ($settings->instanceNames() as $name) {
            foreach (Packer::chunk($final) as $chunk) {
                $n += $store->recordNotBefore($name, $chunk, time(), $at);
            }
        }

        return $n;
    }

    /** Backstop tags waiting to be written for this request. @var array<string, true> */
    private array $backstop = [];
    private bool $backstopWritten = false;
    private bool $backstopRegistered = false;
    public const BACKSTOP_AFTER = 120;
    /** Tags buffered before a backstop row set is written mid-request (a bulk import). */
    public const BACKSTOP_BATCH = 1000;

    /**
     * The durability backstop, coalesced per request: the FIRST save of a
     * request is written at once (a single save — the common case — is
     * covered even if the process dies right after it); later saves are
     * buffered and written as one deduplicated, chunked row set every
     * BACKSTOP_BATCH tags and at the end of the request. A 10k-product import
     * writes a handful of rows, not 10k.
     *
     * @param list<string> $tags unprefixed
     */
    public function backstop(array $tags): void
    {
        if ($tags === []) {
            return;
        }
        if (!$this->backstopWritten) {
            $this->backstopWritten = true;
            $this->schedule($tags, time() + self::BACKSTOP_AFTER);

            return;
        }
        foreach ($tags as $tag) {
            $this->backstop[$tag] = true;
        }
        if (\count($this->backstop) >= self::BACKSTOP_BATCH) {
            $this->writeBackstop();
        }
        if (!$this->backstopRegistered) {
            $this->backstopRegistered = true;
            register_shutdown_function(fn () => $this->writeBackstop());
        }
    }

    public function writeBackstop(): void
    {
        if ($this->backstop === []) {
            return;
        }
        $tags = array_keys($this->backstop);
        $this->backstop = [];
        $this->schedule(array_map('strval', $tags), time() + self::BACKSTOP_AFTER);
    }

    /**
     * An immediate HARD purge of the whole shop (a settings change: maintenance,
     * catalogue mode…). Soft would keep the old pages and serve them stale
     * while the refresh gets e.g. the maintenance 503 — which Trident's
     * stale_if_error then answers with the stale page for hours.
     */
    public function purgeEverythingHard(): void
    {
        $s = SettingsProvider::get();
        if (!$s->enabled()) {
            return;
        }
        $hard = new Settings($s->instances, $s->errors, $s->source, Settings::MODE_HARD, $s->tagPrefix, $s->debugHeaders, $s->readFailed);
        $delivery = new OutboxDelivery(new PsOutboxStore(\Db::getInstance()), $hard, self::policy(), (new TransportFactory())->transport(), null, null, new PsLogger(), 0);
        $delivery->recordAll();
        register_shutdown_function(static function () use ($delivery): void {
            self::finishResponse();
            $delivery->flush();
        });
    }

    public function flushAfterResponse(): void
    {
        if ($this->flushRegistered) {
            return;
        }
        $this->flushRegistered = true;
        register_shutdown_function(function (): void {
            self::finishResponse();
            $this->flushNow();
        });
    }

    /** Deliver what this request recorded (after the response). */
    public function flushNow(): void
    {
        if ($this->delivery !== null) {
            $this->delivery->flush();
        }
    }

    public function drainAfterResponse(): void
    {
        if ($this->drainRegistered || !SettingsProvider::get()->enabled()) {
            return;
        }
        $this->drainRegistered = true;
        register_shutdown_function(function (): void {
            self::finishResponse();
            $this->drainNow();
        });
    }

    /**
     * The throttled drain of the backlog. Only once the response is out: under
     * php-fpm the visitor already has it; anywhere else (CLI, a blocking SAPI)
     * nothing is drained here — the cron does it.
     */
    public function drainNow(): void
    {
        if (!SettingsProvider::get()->enabled() || !self::responseSent()) {
            return;
        }
        $last = (int) \Configuration::getGlobalValue('TRIDENT_LAST_DRAIN');
        if (time() - $last < self::DRAIN_EVERY) {
            return;
        }
        \Configuration::updateGlobalValue('TRIDENT_LAST_DRAIN', time());
        $this->delivery()->drain(self::DRAIN_LIMIT);
    }

    private static bool $finished = false;

    private static function responseSent(): bool
    {
        return self::$finished;
    }

    private static function finishResponse(): void
    {
        if (self::$finished) {
            return;
        }
        // Release the session lock before the slow part, so the visitor's next
        // request does not wait for Trident.
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (\function_exists('fastcgi_finish_request') && \PHP_SAPI !== 'cli') {
            fastcgi_finish_request();
            self::$finished = true;
        }
    }

    /** kernel.terminate runs after the response was sent (php-fpm). */
    public static function markResponseSent(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        self::$finished = \PHP_SAPI === 'fpm-fcgi';
    }
}
