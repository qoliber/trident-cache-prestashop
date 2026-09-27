<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Admin;

use Qoliber\Trident\Admin\ShopAdapter;

/**
 * The shop, as the shared admin screens need it: its hosts (every shop URL of
 * this PrestaShop), its catalogue URLs for coverage and warming, and which
 * tags purge a product, category, CMS page or brand.
 */
final class PsShopAdapter implements ShopAdapter
{
    public function hosts(): array
    {
        $hosts = [];
        foreach (\Db::getInstance()->executeS('SELECT domain, domain_ssl FROM `' . _DB_PREFIX_ . 'shop_url` WHERE active = 1') ?: [] as $row) {
            foreach (['domain', 'domain_ssl'] as $key) {
                $host = strtolower(trim((string) $row[$key]));
                if ($host !== '') {
                    $hosts[] = $host;
                }
            }
        }

        return array_values(array_unique($hosts));
    }

    public function scheme(string $host): string
    {
        return \Configuration::get('PS_SSL_ENABLED') ? 'https' : 'http';
    }

    public function catalogUrls(int $limit): array
    {
        $link = \Context::getContext()->link;
        $lang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $urls = [\Context::getContext()->link->getPageLink('index', null, $lang)];
        foreach (\Category::getSimpleCategories($lang) as $category) {
            if (\count($urls) >= $limit) {
                break;
            }
            if ((int) $category['id_category'] > (int) \Configuration::get('PS_HOME_CATEGORY')) {
                $urls[] = $link->getCategoryLink((int) $category['id_category'], null, $lang);
            }
        }
        foreach (\Product::getSimpleProducts($lang) as $product) {
            if (\count($urls) >= $limit) {
                break;
            }
            // As the storefront links it: with the default combination
            // (`/1-1-name.html`), or the warmed entry is not the one visitors ask for.
            $id = (int) $product['id_product'];
            $urls[] = $link->getProductLink($id, null, null, null, $lang, null, (int) \Product::getDefaultAttribute($id));
        }

        return array_slice(array_values(array_unique($urls)), 0, $limit);
    }

    public function entityKinds(): array
    {
        return ['product' => 'Product ids', 'category' => 'Category ids', 'cms' => 'CMS page ids', 'manufacturer' => 'Brand ids'];
    }

    public function entityTags(string $kind, array $ids): array
    {
        $prefix = ['product' => 'p_', 'category' => 'c_', 'cms' => 'cms_', 'manufacturer' => 'm_'][$kind] ?? null;
        if ($prefix === null) {
            throw new \InvalidArgumentException('unknown kind: ' . $kind);
        }
        $tags = [];
        foreach ($ids as $id) {
            if (!ctype_digit((string) $id) || (int) $id <= 0) {
                throw new \InvalidArgumentException('not an id: ' . $id);
            }
            $tags[] = $prefix . (int) $id;
        }

        return $tags;
    }
}
