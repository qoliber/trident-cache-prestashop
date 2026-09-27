<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Tags;

/**
 * Which pages a change affects (unprefixed tags; see {@see PageTags}).
 */
final class ChangeTags
{
    /**
     * A product: its page and every page showing it (`p_<id>`), plus its
     * categories — a new or moved product changes what their listings contain.
     *
     * @return list<string>
     */
    public static function product(mixed $product, bool $listings = false): array
    {
        $id = (int) (\is_object($product) ? ($product->id ?? 0) : 0);

        return self::productId($id, $listings);
    }

    /**
     * @return list<string>
     */
    /**
     * @param bool $listings also the listings that pick products by price or
     *                       date (prices-drop, new-products): only when the
     *                       product was created, (de)activated or repriced —
     *                       a product that just became discounted or new is
     *                       not shown there yet, so it carries no p_ tag there
     */
    public static function productId(int $id, bool $listings = false): array
    {
        if ($id <= 0) {
            return [];
        }
        $tags = ['p_' . $id];
        if ($listings) {
            $tags[] = 'listing_prices-drop';
            $tags[] = 'listing_new-products';
        }
        foreach (\Product::getProductCategories($id) as $category) {
            $tags[] = 'c_' . (int) $category;
        }

        return array_values(array_unique($tags));
    }

    /**
     * Stock: an order changes the quantity of what it sold, and that must NOT
     * purge listings or the home page — only a change of AVAILABILITY (in
     * stock ↔ out of stock) is visible, and only on the product's own tag.
     * Without a delta (an unknown old value) the product is purged.
     *
     * @param array<string, mixed> $params the actionUpdateQuantity parameters
     * @return list<string>
     */
    public static function quantity(array $params): array
    {
        $id = (int) ($params['id_product'] ?? 0);
        if ($id <= 0) {
            return [];
        }
        $quantity = (int) ($params['quantity'] ?? 0);
        $delta = $params['delta_quantity'] ?? null;
        if ($delta === null) {
            return ['p_' . $id];
        }
        $before = $quantity - (int) $delta;

        return ($before > 0) !== ($quantity > 0) ? ['p_' . $id] : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function categorySnapshot(mixed $category): ?array
    {
        if (!\is_object($category) || (int) ($category->id ?? 0) <= 0) {
            return null;
        }
        $stored = new \Category((int) $category->id);

        return self::menuFields($stored->name ?? null, $stored->link_rewrite ?? null, $stored->active ?? null, $stored->id_parent ?? null, $stored->position ?? null);
    }

    /**
     * A category's own page and its parent's listing; `menu` (every page)
     * only when something the menu shows changed.
     *
     * @param array<string, mixed>|null $before
     * @return list<string>
     */
    public static function category(mixed $category, ?array $before): array
    {
        $id = (int) (\is_object($category) ? ($category->id ?? 0) : 0);
        if ($id <= 0) {
            return [];
        }
        $tags = ['c_' . $id];
        if ((int) ($category->id_parent ?? 0) > 0) {
            $tags[] = 'c_' . (int) $category->id_parent;
        }
        $after = self::menuFields($category->name ?? null, $category->link_rewrite ?? null, $category->active ?? null, $category->id_parent ?? null, $category->position ?? null);
        if ($before === null || $before !== $after) {
            $tags[] = 'menu';
        }

        return $tags;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function cmsSnapshot(mixed $cms): ?array
    {
        if (!\is_object($cms) || (int) ($cms->id ?? 0) <= 0) {
            return null;
        }
        $stored = new \CMS((int) $cms->id);

        return self::menuFields($stored->meta_title ?? null, $stored->link_rewrite ?? null, $stored->active ?? null, $stored->id_cms_category ?? null, $stored->position ?? null);
    }

    /**
     * @param array<string, mixed>|null $before
     * @return list<string>
     */
    public static function cms(mixed $cms, ?array $before): array
    {
        $id = (int) (\is_object($cms) ? ($cms->id ?? 0) : 0);
        if ($id <= 0) {
            return [];
        }
        $tags = ['cms_' . $id, 'cmsc_' . (int) ($cms->id_cms_category ?? 0)];
        $after = self::menuFields($cms->meta_title ?? null, $cms->link_rewrite ?? null, $cms->active ?? null, $cms->id_cms_category ?? null, $cms->position ?? null);
        if ($before === null || $before !== $after) {
            $tags[] = 'menu';
        }

        return $tags;
    }

    /**
     * A specific price for one product purges it; one for every product
     * (id_product 0) purges the shop.
     *
     * @return list<string>
     */
    public static function specificPrice(mixed $price): array
    {
        if (!\is_object($price)) {
            return [];
        }
        $id = (int) ($price->id_product ?? 0);

        return $id > 0 ? self::productId($id, true) : ['all'];
    }

    /** Classes whose change reprices or relabels the whole catalogue. */
    public const SHOP_WIDE = [
        'Feature', 'FeatureValue', 'ProductAttribute', 'Attribute', 'AttributeGroup',
        'Tax', 'TaxRule', 'TaxRulesGroup', 'Group', 'Currency', 'SpecificPriceRule',
        'Country', 'LinkBlock',
    ];

    /**
     * Any ObjectModel the generic actionObject* hooks report: its tags, or []
     * for objects that do not affect a cached page. For an object being ADDED
     * (no id yet) only what can already be named — its category, its listing.
     *
     * @return list<string>
     */
    public static function forObject(object $o): array
    {
        $class = (new \ReflectionClass($o))->getShortName();
        $id = (int) ($o->id ?? 0);
        switch ($class) {
            case 'Product':
                return $id > 0 ? self::productId($id) : array_values(array_filter(['c_' . (int) ($o->id_category_default ?? 0), 'listing_new-products'], static fn (string $t): bool => $t !== 'c_0'));
            case 'Combination':
            case 'Image':
                return self::productId((int) ($o->id_product ?? 0));
            case 'SpecificPrice':
                return self::productId((int) ($o->id_product ?? 0), true) ?: ['all'];
            // Called BEFORE the save too (the backstop), when the database still
            // holds the old values: purge `menu` only if a menu field changes.
            case 'Category':
                return self::category($o, $id > 0 ? self::categorySnapshot($o) : null);
            case 'CMS':
                return self::cms($o, $id > 0 ? self::cmsSnapshot($o) : null);
            case 'Manufacturer':
                return $id > 0 ? ['m_' . $id, 'm_list'] : ['m_list'];
            case 'Supplier':
                return $id > 0 ? ['s_' . $id, 's_list'] : ['s_list'];
            case 'Ps_HomeSlide':
            case 'CustomText':
                return ['home'];
        }

        return \in_array($class, self::SHOP_WIDE, true) ? ['all'] : [];
    }

    /**
     * When a scheduled price starts or ends, as Unix times still in the future.
     *
     * @return list<int>
     */
    public static function priceSchedule(object $o, int $now): array
    {
        // PrestaShop compares prices' dates with the current MINUTE
        // (date('Y-m-d H:i:00')): a price "from 10:15:30" applies from 10:16,
        // one "to 10:20:30" still applies at 10:20 and ends at 10:21.
        $out = [];
        foreach (['from', 'to'] as $field) {
            $when = (string) ($o->{$field} ?? '');
            if ($when === '' || str_starts_with($when, '0000-00-00')) {
                continue;
            }
            $t = strtotime($when);
            if ($t === false) {
                continue;
            }
            $minute = intdiv($t, 60) * 60;
            $effective = $field === 'from' ? ($t === $minute ? $t : $minute + 60) : $minute + 60;
            if ($effective + 1 > $now) {
                $out[] = $effective + 1;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function menuFields(mixed $name, mixed $link, mixed $active, mixed $parent, mixed $position): array
    {
        return ['name' => $name, 'link' => $link, 'active' => (bool) $active, 'parent' => (int) $parent, 'position' => (int) $position];
    }
}
