<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Tags;

/**
 * The cache tags of a storefront page (unprefixed; the shared TagPolicy
 * prefixes, bounds and adds `all`):
 *
 * - the page itself: `pp_<id>` product page, `c_<id>` category, `cms_<id>`,
 *   `m_<id>` brand, `s_<id>` supplier, `home` (+ the home category);
 * - every product shown on it: `p_<id>` — read from the FINAL HTML
 *   (`data-id-product`, the miniature attribute every theme keeps), so the
 *   home page's featured products, a listing, "related" blocks and a product's
 *   own page are all purged when that product changes;
 * - `menu`: every page shows the main menu and the CMS links in the footer.
 */
final class PageTags
{
    /**
     * @return list<string>
     */
    public static function forPage(\Context $context, string $html): array
    {
        $controller = $context->controller;
        $page = (string) ($controller->php_self ?? '');
        $tags = ['menu'];
        switch ($page) {
            case 'index':
                $tags[] = 'home';
                $tags[] = 'c_' . (int) \Configuration::get('PS_HOME_CATEGORY');
                break;
            case 'product':
                $id = (int) \Tools::getValue('id_product');
                if ($id > 0) {
                    $tags[] = 'pp_' . $id;
                    $tags[] = 'p_' . $id;
                }
                break;
            case 'category':
                $tags[] = 'c_' . (int) \Tools::getValue('id_category');
                break;
            case 'cms':
                if ((int) \Tools::getValue('id_cms') > 0) {
                    $tags[] = 'cms_' . (int) \Tools::getValue('id_cms');
                } else {
                    $tags[] = 'cmsc_' . (int) \Tools::getValue('id_cms_category');
                }
                break;
            case 'manufacturer':
                $tags[] = (int) \Tools::getValue('id_manufacturer') > 0 ? 'm_' . (int) \Tools::getValue('id_manufacturer') : 'm_list';
                break;
            case 'supplier':
                $tags[] = (int) \Tools::getValue('id_supplier') > 0 ? 's_' . (int) \Tools::getValue('id_supplier') : 's_list';
                break;
            default:
                $tags[] = 'listing_' . $page;
        }

        return array_values(array_unique(array_merge($tags, self::products($html))));
    }

    /**
     * @return list<string>
     */
    public static function products(string $html): array
    {
        if (preg_match_all('/data-id-product="(\d+)"/', $html, $m) === 0) {
            return [];
        }
        $ids = array_unique(array_map('intval', $m[1]));
        sort($ids);

        return array_map(static fn (int $id): string => 'p_' . $id, array_values(array_filter($ids)));
    }
}
