<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentPrestaShop\Cache\ConfigWatcher;
use Qoliber\TridentPrestaShop\Cache\PersonalContent;
use Qoliber\TridentPrestaShop\Config\TridentConfig;
use Qoliber\TridentPrestaShop\Tags\ChangeTags;
use Qoliber\TridentPrestaShop\Tags\PageTags;

/**
 * The module's PrestaShop-free rules: when stock purges, which paths Trident
 * must never store, and which products a page shows.
 */
final class RulesTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function stock(): array
    {
        return [
            'a sale that leaves stock' => [['id_product' => 1, 'quantity' => 4, 'delta_quantity' => -1], []],
            'restock of an available product' => [['id_product' => 1, 'quantity' => 9, 'delta_quantity' => 5], []],
            'the last item sold' => [['id_product' => 1, 'quantity' => 0, 'delta_quantity' => -1], ['p_1']],
            'back in stock' => [['id_product' => 1, 'quantity' => 3, 'delta_quantity' => 3], ['p_1']],
            'oversold (negative)' => [['id_product' => 1, 'quantity' => -2, 'delta_quantity' => -3], ['p_1']],
            'no delta: purge to be safe' => [['id_product' => 1, 'quantity' => 5, 'delta_quantity' => null], ['p_1']],
            'no product' => [['id_product' => 0, 'quantity' => 0, 'delta_quantity' => -1], []],
        ];
    }

    /**
     * An order must not purge listings or the home page — only a change of
     * AVAILABILITY is visible, and only on the product's own tag.
     *
     * @param array<string, mixed> $params
     * @param list<string>         $tags
     */
    #[DataProvider('stock')]
    public function testStockPurgesOnlyWhenAvailabilityFlips(array $params, array $tags): void
    {
        self::assertSame($tags, ChangeTags::quantity($params));
    }

    public function testPassPatternsAreReadFromTheTranslatedRoutes(): void
    {
        $patterns = TridentConfig::passPatterns(['cart', 'basket', 'my-account', 'your-account', 'order', ''], ['en', 'gb'], 'admin-dev');

        self::assertContains('^/admin\-dev(/|$)', $patterns);
        self::assertContains('^/((en|gb)/)?module/', $patterns, 'module front controllers are never stored, under any language');
        self::assertMatchesRegularExpression('#' . $patterns[2] . '#', '/gb/module/tridentcache/sections');
        self::assertNotContains('^/modules/tridentcache/', $patterns, 'static assets under /modules/ are cached');
        $slugs = end($patterns);
        foreach (['/cart', '/gb/basket', '/en/my-account', '/your-account?x=1', '/order/'] as $path) {
            self::assertMatchesRegularExpression('#' . $slugs . '#', $path, $path);
        }
        foreach (['/1-1-hummingbird.html', '/4-men', '/cartoon-mug.html', '/gb/'] as $path) {
            self::assertDoesNotMatchRegularExpression('#' . $slugs . '#', $path, $path);
        }
    }

    public function testTheProductsAPageShowsAreItsTags(): void
    {
        $html = '<article data-id-product="3"></article><div data-id-product="11"></div><article data-id-product="3">';

        self::assertSame(['p_3', 'p_11'], PageTags::products($html));
        self::assertSame([], PageTags::products('<main>no products</main>'));
    }

    public function testAViewedListIsTheHistoryWithoutTheProductItself(): void
    {
        self::assertSame([], PersonalContent::viewedIds('', 3));
        self::assertSame([], PersonalContent::viewedIds('3', 3), 'first view of a product: nothing personal on it');
        self::assertSame([1, 7], PersonalContent::viewedIds('1,3,7,3,x', 3));
    }

    public function testNotificationsAreReadFromPrestaShopsOwnVariable(): void
    {
        self::assertFalse(PersonalContent::hasNotification(['error' => [], 'warning' => [], 'success' => [], 'info' => []]));
        self::assertFalse(PersonalContent::hasNotification(null));
        self::assertTrue(PersonalContent::hasNotification(['error' => [], 'success' => ['Account created']]));
    }

    public function testAScheduledPriceIsPurgedWhenPrestaShopAppliesIt(): void
    {
        $now = strtotime('2026-09-27 10:00:00');
        $sp = (object) ['from' => '2026-09-27 10:15:30', 'to' => '2026-09-27 10:20:30'];
        self::assertSame([strtotime('2026-09-27 10:16:00') + 1, strtotime('2026-09-27 10:21:00') + 1], ChangeTags::priceSchedule($sp, $now));
        $open = (object) ['from' => '0000-00-00 00:00:00', 'to' => '2026-09-27 09:00:00'];
        self::assertSame([], ChangeTags::priceSchedule($open, $now), 'no start, an end already past');
    }

    /**
     * Two shops with different values: reading them with explicit ids gives the
     * same fingerprints whichever shop's context the request runs in — so
     * alternating shops is never a "change" (it purged everything per request).
     */
    public function testEachShopHasItsOwnFingerprintWhateverTheContext(): void
    {
        $values = [1 => 'Shop A', 2 => 'Shop B'];
        $get = static function (string $key, int $lang, int $shop) use (&$values): string {
            return $key === 'PS_SHOP_NAME' ? $values[$shop] : '';
        };
        $struct = static fn (int $shop): string => 'hooks' . $shop;
        $first = ConfigWatcher::fingerprints($get, $struct, [1, 2], [1]);
        self::assertNotSame($first[1], $first[2]);
        self::assertSame($first, ConfigWatcher::fingerprints($get, $struct, [2, 1], [1]), 'order/context independent');
        self::assertSame([], ConfigWatcher::changed($first, $first));

        $values[2] = 'Shop B renamed';
        self::assertSame([2], ConfigWatcher::changed($first, ConfigWatcher::fingerprints($get, $struct, [1, 2], [1])));
        self::assertSame([], ConfigWatcher::changed([], $first), 'first sight is not a change');
    }

    public function testAPerLanguageValueAndAModuleMoveAreChanges(): void
    {
        $text = [1 => 'EN', 2 => 'FR'];
        $get = static function (string $key, int $lang, int $shop) use (&$text): string {
            return $key === 'BANNER_DESC' ? $text[$lang] : '';
        };
        $hooks = 'a';
        $struct = static function (int $shop) use (&$hooks): string {
            return $hooks;
        };
        $a = ConfigWatcher::fingerprints($get, $struct, [1], [1, 2]);
        $text[2] = 'FR changed';
        self::assertSame([1], ConfigWatcher::changed($a, ConfigWatcher::fingerprints($get, $struct, [1], [1, 2])));
        $text[2] = 'FR';
        $hooks = 'b';
        self::assertSame([1], ConfigWatcher::changed($a, ConfigWatcher::fingerprints($get, $struct, [1], [1, 2])));
    }
}
