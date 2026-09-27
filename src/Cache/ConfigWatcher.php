<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Cache;

use Qoliber\TridentPrestaShop\Delivery\DeliveryFactory;

/**
 * Settings that change what EVERY page shows are saved through Configuration
 * (no ObjectModel hook), and modules or themes are moved and switched without
 * one either. The module keeps a fingerprint of them PER SHOP — read with
 * explicit shop and language ids, so the back office's "all shops" context or
 * alternating shops never look like a change — and purges the whole shop,
 * HARD, when one changes. Hard, because a soft purge would keep the old pages
 * and serve them stale while their refresh gets, e.g., the maintenance 503
 * (which Trident's stale_if_error answers with the stale page).
 *
 * Checked on every front and back-office request that reaches PHP: a change
 * made from the CLI, the database or the webservice is noticed on the next
 * one — with a warm cache that can be long, which is why the cron (drain) is
 * required.
 */
final class ConfigWatcher
{
    public const KEYS = [
        'PS_SHOP_ENABLE', 'PS_CATALOG_MODE', 'PS_CATALOG_MODE_WITH_PRICES',
        'PS_TAX', 'PS_TAX_DISPLAY', 'PS_PRICE_ROUND_MODE', 'PS_ROUND_TYPE',
        'PS_DISPLAY_QTIES', 'PS_LAST_QTIES', 'PS_STOCK_MANAGEMENT', 'PS_ORDER_OUT_OF_STOCK',
        'PS_DISPLAY_DISCOUNT_PRICE', 'PS_NB_DAYS_NEW_PRODUCT', 'PS_PRODUCTS_PER_PAGE',
        'PS_PRODUCTS_ORDER_BY', 'PS_PRODUCTS_ORDER_WAY', 'PS_CURRENCY_DEFAULT',
        'PS_COUNTRY_DEFAULT', 'PS_LANG_DEFAULT', 'PS_SHOP_NAME', 'PS_LOGO',
        'PS_REWRITING_SETTINGS', 'PS_GUEST_CHECKOUT_ENABLED', 'PS_DISPLAY_BEST_SELLERS',
        // Contact details (footer, contact page).
        'PS_SHOP_EMAIL', 'PS_SHOP_PHONE', 'PS_SHOP_FAX', 'PS_SHOP_ADDR1', 'PS_SHOP_ADDR2',
        'PS_SHOP_CODE', 'PS_SHOP_CITY', 'PS_SHOP_COUNTRY_ID', 'PS_SHOP_STATE_ID', 'PS_SHOP_DETAILS',
        // Module content kept in Configuration.
        'MOD_BLOCKTOPMENU_ITEMS', 'HOME_FEATURED_NBR', 'HOME_FEATURED_CAT', 'HOME_FEATURED_RANDOMIZE',
        'BLOCK_CATEG_ROOT_CATEGORY', 'NEW_PRODUCTS_NBR', 'PS_BLOCK_BESTSELLERS_TO_DISPLAY',
        'HOMESLIDER_SPEED', 'HOMESLIDER_PAUSE_ON_HOVER', 'HOMESLIDER_WRAP',
        'BLOCKSOCIAL_FACEBOOK', 'BLOCKSOCIAL_TWITTER', 'BLOCKSOCIAL_RSS', 'BLOCKSOCIAL_YOUTUBE',
        'BLOCKSOCIAL_PINTEREST', 'BLOCKSOCIAL_VIMEO', 'BLOCKSOCIAL_INSTAGRAM', 'BLOCKSOCIAL_LINKEDIN',
        'BANNER_IMG', 'BANNER_LINK', 'BANNER_DESC',
    ];
    public const STATE = 'TRIDENT_CONFIG_FINGERPRINT';

    /**
     * One fingerprint per shop, from explicit ids.
     *
     * @param callable(string $key, int $lang, int $shop): string $get
     * @param callable(int $shop): string                          $structure modules, positions, theme
     * @param list<int> $shops
     * @param list<int> $langs
     * @return array<int, string> shop id => fingerprint
     */
    public static function fingerprints(callable $get, callable $structure, array $shops, array $langs): array
    {
        $out = [];
        foreach ($shops as $shop) {
            $values = [];
            foreach (self::KEYS as $key) {
                foreach ($langs as $lang) {
                    $values[$key][$lang] = $get($key, $lang, $shop);
                }
            }
            $values['__structure'] = $structure($shop);
            $out[$shop] = hash('sha256', (string) json_encode($values));
        }
        ksort($out);

        return $out;
    }

    /**
     * Which shops changed since $seen (first sight of a shop is not a change).
     *
     * @param array<int, string> $seen
     * @param array<int, string> $now
     * @return list<int>
     */
    public static function changed(array $seen, array $now): array
    {
        $changed = [];
        foreach ($now as $shop => $hash) {
            if (isset($seen[$shop]) && $seen[$shop] !== $hash) {
                $changed[] = (int) $shop;
            }
        }

        return $changed;
    }

    public static function check(): bool
    {
        $shops = array_map('intval', \Shop::getShops(false, null, true));
        $langs = array_map('intval', \Language::getLanguages(false, false, true));
        $db = \Db::getInstance();
        $now = self::fingerprints(
            static fn (string $key, int $lang, int $shop): string => (string) \Configuration::get($key, $lang, null, $shop),
            static function (int $shop) use ($db): string {
                return (string) $db->getValue('SELECT CONCAT_WS(\'|\','
                    . ' (SELECT BIT_XOR(CRC32(CONCAT(id_module, \':\', id_hook, \':\', position))) FROM `' . _DB_PREFIX_ . 'hook_module` WHERE id_shop = ' . $shop . '),'
                    . ' (SELECT BIT_XOR(CRC32(CONCAT(id_module, \':\', enable_device))) FROM `' . _DB_PREFIX_ . 'module_shop` WHERE id_shop = ' . $shop . '),'
                    . ' (SELECT theme_name FROM `' . _DB_PREFIX_ . 'shop` WHERE id_shop = ' . $shop . '))');
            },
            $shops,
            $langs
        );
        $seen = json_decode((string) \Configuration::getGlobalValue(self::STATE), true);
        $seen = \is_array($seen) ? $seen : [];
        if ($seen == $now) {
            return false;
        }
        \Configuration::updateGlobalValue(self::STATE, (string) json_encode($now));
        if (self::changed($seen, $now) === []) {
            return false;
        }
        DeliveryFactory::instance()->purgeEverythingHard();

        return true;
    }
}
