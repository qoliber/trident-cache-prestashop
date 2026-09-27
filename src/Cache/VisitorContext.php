<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Cache;

/**
 * What about a visitor changes the SHARED page — and the cookies Trident and
 * the browser read.
 *
 * `trident_ctx` (the price context): currency, tax country and, only when it
 * actually has prices of its own, the customer group. Anonymous visitors in the
 * shop's defaults carry none; anyone else carries a short hash, and Trident
 * keys pages by it (`vary_cookies`). A page is shared only when the marker the
 * visitor SENT matches the one computed for the render, so a forged marker can
 * never store one context's prices under another's key.
 *
 * `trident_pv` (the version): rotated whenever the visitor's personal data
 * changes (cart, login, logout); the browser refreshes its cart count and
 * greeting only then. No cookie, no request.
 */
final class VisitorContext
{
    public const CONTEXT_COOKIE = 'trident_ctx';
    public const VERSION_COOKIE = 'trident_pv';
    /** The default context's marker: set once a visitor chose (and came back to) it. */
    public const DEFAULT = 'd';

    public static function marker(\Context $context): string
    {
        $parts = [];
        $currency = (int) ($context->currency->id ?? 0);
        if ($currency !== (int) \Configuration::get('PS_CURRENCY_DEFAULT')) {
            $parts[] = 'cur:' . $currency;
        }
        $country = (int) ($context->country->id ?? 0);
        if ($country !== 0 && $country !== (int) \Configuration::get('PS_COUNTRY_DEFAULT')) {
            $parts[] = 'cty:' . $country;
        }
        $group = self::priceGroup($context);
        if ($group !== null) {
            $parts[] = 'grp:' . $group;
        }

        return $parts === [] ? self::DEFAULT : substr(hash('sha256', implode('|', $parts)), 0, 16);
    }

    /**
     * A visitor who sends no marker gets what the default context renders:
     * both mean "default", so neither makes the page private.
     */
    public static function matches(string $sent, string $computed): bool
    {
        return $sent === $computed || ($sent === '' && $computed === self::DEFAULT);
    }

    public static function sentMarker(): string
    {
        return isset($_COOKIE[self::CONTEXT_COOKIE]) && \is_string($_COOKIE[self::CONTEXT_COOKIE]) ? $_COOKIE[self::CONTEXT_COOKIE] : '';
    }

    /**
     * Make the browser send the marker computed for this render — for as long
     * as PrestaShop keeps its own cookie (which holds the currency the marker
     * describes), HttpOnly (no script needs it), Secure on https.
     */
    public static function sendMarker(string $marker): void
    {
        if (headers_sent()) {
            return;
        }
        $hours = (int) \Configuration::get('PS_COOKIE_LIFETIME_FO');
        setcookie(self::CONTEXT_COOKIE, $marker !== '' ? $marker : self::DEFAULT, [
            'expires' => time() + max(1, $hours) * 3600,
            'path' => '/',
            'samesite' => 'Lax',
            'httponly' => true,
            'secure' => \Tools::usingSecureMode(),
        ]);
    }

    public static function rotateVersion(): void
    {
        if (headers_sent()) {
            return;
        }
        $value = bin2hex(random_bytes(8));
        $_COOKIE[self::VERSION_COOKIE] = $value;
        setcookie(self::VERSION_COOKIE, $value, ['expires' => 0, 'path' => '/', 'samesite' => 'Lax', 'httponly' => false]);
    }

    public static function hasVersion(): bool
    {
        return isset($_COOKIE[self::VERSION_COOKIE]) && $_COOKIE[self::VERSION_COOKIE] !== '';
    }

    /**
     * The visitor's group when its pages differ from the shop's default
     * (unidentified visitor) group: its own reduction, its own price display
     * (tax incl./excl.), specific prices / price rules for it, or a different
     * set of categories it may see (category group access) — a customer who
     * may see more must not be served the visitor's listings.
     */
    private static function priceGroup(\Context $context): ?int
    {
        // The group PrestaShop prices this render with (Group::getCurrent():
        // the customer's default group whenever a customer is loaded, logged
        // in or not), not a guess from isLogged().
        $group = (int) \Group::getCurrent()->id;
        $default = (int) \Configuration::get('PS_UNIDENTIFIED_GROUP');
        if ($group === 0 || $group === $default) {
            return null;
        }
        static $cache = [];
        if (!array_key_exists($group, $cache)) {
            $own = \Group::getReductionByIdGroup($group) > 0
                || \Group::getPriceDisplayMethod($group) !== \Group::getPriceDisplayMethod($default)
                || (bool) \Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'specific_price` WHERE id_group = ' . $group)
                || (bool) \Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'specific_price_rule` WHERE id_group = ' . $group)
                || self::categoryAccess($group) !== self::categoryAccess($default);
            $cache[$group] = $own;
        }

        return $cache[$group] ? $group : null;
    }

    private static function categoryAccess(int $group): string
    {
        $ids = array_map('intval', array_column(\Db::getInstance()->executeS(
            'SELECT id_category FROM `' . _DB_PREFIX_ . 'category_group` WHERE id_group = ' . $group . ' ORDER BY id_category'
        ) ?: [], 'id_category'));

        return implode(',', $ids);
    }
}
