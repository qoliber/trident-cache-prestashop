<?php
/**
 * Deliver what the purge outbox owes — PrestaShop has no scheduler, so a
 * system cron calls this URL (or runs `bin/console trident:purge:drain`):
 *
 *   * * * * * curl -fsS 'https://shop.example/module/tridentcache/cron?token=…'
 *
 * The token is shown in the back office (Trident Cache → Settings, edit
 * permission); it is compared in constant time. Send it as the header
 * `X-Trident-Cron-Token` (or POST `token`) to keep it out of access logs.
 * `now=1` delivers every row whatever its due time — at most once a minute.
 * Works while the shop is in maintenance.
 *
 * @author    qoliber
 * @license   MIT
 */

declare(strict_types=1);

use Qoliber\TridentPrestaShop\Delivery\DeliveryFactory;

class TridentCacheCronModuleFrontController extends ModuleFrontController
{
    /**
     * At most one "deliver now" a minute, atomically: one UPDATE claims the
     * slot, so two concurrent calls cannot both pass.
     */
    private static function claimDrainNow(): bool
    {
        if (Configuration::getGlobalValue('TRIDENT_LAST_DRAIN_NOW') === false) {
            Configuration::updateGlobalValue('TRIDENT_LAST_DRAIN_NOW', '0');
        }
        $now = time();
        Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'configuration` SET value = \'' . $now . '\' WHERE name = \'TRIDENT_LAST_DRAIN_NOW\''
            . ' AND CAST(value AS UNSIGNED) <= ' . ($now - 60));

        return Db::getInstance()->Affected_Rows() === 1;
    }

    /** Purges are owed during maintenance too: never the maintenance page. */
    protected function displayMaintenancePage()
    {
    }

    /**
     * Answers and ends the request itself: a JSON endpoint, never a page
     * (PrestaShop would otherwise go on to render a template).
     */
    public function initContent(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        $this->context->cookie->disallowWriting();
        $expected = (string) Configuration::getGlobalValue('TRIDENT_CRON_TOKEN');
        // The token only in the header or a POST body: never in the URL
        // (access logs).
        $header = $_SERVER['HTTP_X_TRIDENT_CRON_TOKEN'] ?? '';
        $given = is_string($header) && $header !== '' ? $header : (string) ($_POST['token'] ?? '');
        if ($expected === '' || !hash_equals($expected, $given)) {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            exit;
        }
        $delivery = DeliveryFactory::instance()->delivery();
        $now = (bool) Tools::getValue('now') && self::claimDrainNow();
        $report = $now ? $delivery->drainAll() : $delivery->drain();
        echo json_encode([
            'delivered' => $report->delivered,
            'failed' => $report->failed,
            'purged' => $report->purged,
            'pending' => $delivery->status()['pending'] ?? null,
        ]);
        exit;
    }
}
