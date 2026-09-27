<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Config;

/**
 * The shop-specific part of trident.toml: which paths Trident must never
 * store. PrestaShop TRANSLATES the cart, checkout and account slugs per
 * language (and the default language has no /iso/ prefix), so they are read
 * from the shop's own routes (`meta`/`meta_lang`), never hard-coded.
 */
final class TridentConfig
{
    /** Pages that are personal by nature (meta.page values). */
    public const PRIVATE_PAGES = [
        'cart', 'order', 'order-confirmation', 'history', 'order-follow', 'order-slip', 'order-detail',
        'authentication', 'registration', 'password', 'my-account', 'identity', 'address', 'addresses',
        'discount', 'guest-tracking',
    ];

    /**
     * @param list<string> $slugs    translated slugs of the private pages, every language
     * @param list<string> $isos     active language ISO codes
     * @return list<string> regular expressions (Trident pass_patterns)
     */
    public static function passPatterns(array $slugs, array $isos, string $adminDir): array
    {
        $quote = static fn (string $s): string => preg_quote($s, '/');
        $slugs = array_values(array_unique(array_filter(array_map('trim', $slugs), static fn (string $s): bool => $s !== '')));
        sort($slugs);
        $isos = array_values(array_unique(array_filter(array_map('strtolower', $isos))));
        sort($isos);
        $patterns = [
            '^/' . $quote($adminDir) . '(/|$)',
            '^/api(/|$)',
            // Module front controllers (/module/…, also under a language
            // prefix), never their static assets (/modules/…).
            '^/' . ($isos !== [] ? '((' . implode('|', array_map($quote, $isos)) . ')/)?' : '') . 'module/',
            '^/index\\.php',
        ];
        if ($slugs !== []) {
            $patterns[] = '^/(' . ($isos !== [] ? '(' . implode('|', array_map($quote, $isos)) . ')/' : '') . ')?('
                . implode('|', array_map($quote, $slugs)) . ')(/|$|\\?)';
        }

        return $patterns;
    }

    /**
     * @return list<string>
     */
    public static function forShop(): array
    {
        $db = \Db::getInstance();
        $in = implode(',', array_map(static fn (string $p): string => '\'' . pSQL($p) . '\'', self::PRIVATE_PAGES));
        $slugs = array_column($db->executeS('SELECT DISTINCT ml.url_rewrite FROM `' . _DB_PREFIX_ . 'meta` m JOIN `' . _DB_PREFIX_ . 'meta_lang` ml ON ml.id_meta = m.id_meta WHERE m.page IN (' . $in . ')') ?: [], 'url_rewrite');
        $isos = array_column(\Language::getLanguages(true), 'iso_code');
        $admin = \defined('_PS_ADMIN_DIR_') ? basename(_PS_ADMIN_DIR_) : self::adminFolder();

        return self::passPatterns(array_map('strval', $slugs), array_map('strval', $isos), $admin);
    }

    public static function passPatternsToml(): string
    {
        $lines = array_map(static fn (string $p): string => '    ' . json_encode($p, \JSON_UNESCAPED_SLASHES) . ',', self::forShop());

        return "pass_patterns = [\n" . implode("\n", $lines) . "\n]";
    }

    private static function adminFolder(): string
    {
        foreach (glob(_PS_ROOT_DIR_ . '/*/index.php') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), "'_PS_ADMIN_DIR_'")) {
                return basename(\dirname($file));
            }
        }

        return 'admin';
    }
}
