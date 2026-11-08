<?php
/**
 * Trident Cache for PrestaShop.
 *
 * Full-page caching behind the Trident HTTP cache: a render is shared only
 * when it is an anonymous catalogue page, every page carries its cache tags,
 * every change is purged durably (an outbox, retried until Trident
 * acknowledges), and the visitor's own bits (cart, greeting) are filled in by
 * the browser — so a cached page costs the shop nothing.
 *
 * @author    qoliber
 * @license   MIT
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

use Qoliber\TridentPrestaShop\Cache\ConfigWatcher;
use Qoliber\TridentPrestaShop\Cache\ResponsePolicy;
use Qoliber\TridentPrestaShop\Cache\VisitorContext;
use Qoliber\TridentPrestaShop\Delivery\DeliveryFactory;
use Qoliber\TridentPrestaShop\Delivery\PsOutboxStore;
use Qoliber\TridentPrestaShop\Tags\ChangeTags;

class TridentCache extends Module
{
    /** Front hooks and change hooks. */
    public const HOOKS = [
        'actionDispatcherBefore',
        'actionFrontControllerInitBefore',
        'actionFrontControllerDetectContextCountryAfter',
        'actionFrontControllerInitAfter',
        'actionOutputHTMLBefore',
        'actionFrontControllerSetMedia',
        'displayNav2',
        'actionAdminControllerInitBefore',
        // Version cookie for the browser's personal sections.
        'actionCartSave',
        'actionAuthentication',
        'actionCustomerAccountAdd',
        'actionCustomerLogoutAfter',
        // Catalogue changes.
        'actionObjectProductAddAfter',
        'actionObjectProductUpdateAfter',
        'actionObjectProductDeleteAfter',
        'actionUpdateQuantity',
        'actionObjectCategoryUpdateBefore',
        'actionObjectCategoryAddAfter',
        'actionObjectCategoryUpdateAfter',
        'actionObjectCategoryDeleteAfter',
        'actionObjectCMSUpdateBefore',
        'actionObjectCMSAddAfter',
        'actionObjectCMSUpdateAfter',
        'actionObjectCMSDeleteAfter',
        'actionObjectManufacturerUpdateAfter',
        'actionObjectManufacturerDeleteAfter',
        'actionObjectSupplierUpdateAfter',
        'actionObjectSupplierDeleteAfter',
        'actionObjectSpecificPriceAddAfter',
        'actionObjectSpecificPriceUpdateAfter',
        'actionObjectSpecificPriceDeleteAfter',
        'actionObjectSpecificPriceRuleAddAfter',
        'actionObjectSpecificPriceRuleUpdateAfter',
        'actionObjectSpecificPriceRuleDeleteAfter',
        'actionObjectCurrencyUpdateAfter',
        'actionObjectCombinationUpdateAfter',
        'actionObjectCombinationDeleteAfter',
        'actionProductUpdate',
        // Every other ObjectModel (combinations, images, features, attributes,
        // taxes, groups, currencies, brands, suppliers, module content), and
        // the durability backstop before any save.
        'actionObjectAddBefore',
        'actionObjectUpdateBefore',
        'actionObjectDeleteBefore',
        'actionObjectAddAfter',
        'actionObjectUpdateAfter',
        'actionObjectDeleteAfter',
    ];

    /** Did the visitor's cookie carry a chosen country or currency before PrestaShop's detection ran? */
    private bool $contextChosen = false;

    /** Classes the specific hooks below already purge immediately. */
    private const SPECIFIC = ['Product', 'Category', 'CMS', 'SpecificPrice', 'SpecificPriceRule', 'Currency', 'Manufacturer', 'Supplier', 'Combination'];

    /** @var array<string, array<string, mixed>> snapshots taken in *UpdateBefore hooks */
    private array $before = [];

    public function __construct()
    {
        $this->name = 'tridentcache';
        $this->tab = 'administration';
        $this->version = '1.8.0';
        $this->author = 'qoliber';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.3', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = 'Trident Cache';
        $this->description = 'Full-page caching behind the Trident HTTP cache: cache tags, durable purges, personal content from the browser.';
    }

    public function install(): bool
    {
        return parent::install()
            && PsOutboxStore::install()
            && PsOutboxStore::ensureSchema()
            && $this->registerHook(self::HOOKS)
            && $this->installTab();
    }

    public function uninstall(): bool
    {
        // Every setting of this module (TRIDENT_*), in every shop and language.
        $names = Db::getInstance()->executeS('SELECT DISTINCT name FROM `' . _DB_PREFIX_ . 'configuration` WHERE name LIKE \'TRIDENT\\_%\'') ?: [];
        foreach (array_column($names, 'name') as $name) {
            Configuration::deleteByName((string) $name);
        }
        $tabId = (int) Tab::getIdFromClassName('AdminTridentCache');
        if ($tabId > 0) {
            (new Tab($tabId))->delete();
        }

        return PsOutboxStore::uninstall() && parent::uninstall();
    }

    private function installTab(): bool
    {
        if ((int) Tab::getIdFromClassName('AdminTridentCache') > 0) {
            return true;
        }
        $tab = new Tab();
        $tab->class_name = 'AdminTridentCache';
        $tab->module = $this->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminAdvancedParameters');
        $tab->active = true;
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = 'Trident Cache';
        }

        return (bool) $tab->add();
    }

    public function getContent(): void
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminTridentCache'));
    }

    // ── front ────────────────────────────────────────────────────────────

    /**
     * Before any controller runs: a client-supplied Surrogate-Capability must
     * never make the shop emit ESI for a stored page.
     */
    public function hookActionDispatcherBefore(array $params): void
    {
        unset($_SERVER['HTTP_SURROGATE_CAPABILITY']);
        try {
            ConfigWatcher::check();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('Trident: settings watcher failed: ' . $e->getMessage(), 3, null, 'TridentCache');
        }
    }

    /**
     * The decision: an anonymous catalogue render is shared (public,
     * s-maxage, its tags, no cookies); anything else is private.
     *
     * @param array{html: string} $params
     */
    /**
     * Before PrestaShop detects the country: remember whether the visitor's
     * cookie already carried a country or currency they chose.
     */
    public function hookActionFrontControllerInitBefore(array $params): void
    {
        $cookie = $this->context->cookie;
        $this->contextChosen = (isset($cookie->id_currency) && (int) $cookie->id_currency > 0)
            || (isset($cookie->iso_code_country) && (string) $cookie->iso_code_country !== '');
    }

    /**
     * PS_DETECT_COUNTRY picks a country (and its currency) from
     * Accept-Language on a visitor's first request — but Trident serves the
     * default context's page to every visitor without a marker. So a visitor
     * who has not chosen (a currency, a country), is not a known customer and
     * has no address on their cart keeps the shop's DEFAULT country: the
     * render is exactly what Trident serves them. Choosing, logging in or
     * entering an address changes it, with the marker.
     */
    public function hookActionFrontControllerDetectContextCountryAfter(array $params): void
    {
        if ($this->contextChosen || (int) $this->context->customer->id > 0) {
            return;
        }
        $cart = $this->context->cart;
        if ($cart instanceof Cart && (int) $cart->id_address_delivery > 0) {
            return;
        }
        $default = (int) Configuration::get('PS_COUNTRY_DEFAULT');
        if ((int) $this->context->country->id !== $default) {
            $this->context->country = new Country($default, (int) $this->context->language->id);
        }
        unset($this->context->cookie->iso_code_country, $this->context->cookie->id_currency);
    }

    /**
     * Right after PrestaShop applied the visitor's context (a currency switch
     * answers with a redirect, not a page): the browser must carry the
     * matching price-context marker from its next request on, or Trident
     * would serve it the default context's pages.
     */
    public function hookActionFrontControllerInitAfter(array $params): void
    {
        $marker = VisitorContext::marker($this->context);
        if (!VisitorContext::matches(VisitorContext::sentMarker(), $marker)) {
            VisitorContext::sendMarker($marker);
        }
    }

    public function hookActionOutputHTMLBefore(array $params): void
    {
        try {
            ResponsePolicy::fromContext($this->context)->apply((string) ($params['html'] ?? ''));
        } catch (\Throwable $e) {
            // A failure here must never break the page: it is simply not shared.
            if (!headers_sent()) {
                header('Cache-Control: private, no-store');
            }
            PrestaShopLogger::addLog('Trident: response policy failed: ' . $e->getMessage(), 3, null, 'TridentCache');
        }
    }

    public function hookActionFrontControllerSetMedia(array $params): void
    {
        // The binding first: trident-sections.js fires `trident:sections`
        // synchronously when it renders from localStorage.
        $this->context->controller->registerJavascript(
            'trident-prestashop',
            'modules/' . $this->name . '/views/js/trident-prestashop.js',
            ['position' => 'bottom', 'priority' => 199, 'attributes' => 'defer']
        );
        $this->context->controller->registerJavascript(
            'trident-sections',
            'modules/' . $this->name . '/views/js/trident-sections.js',
            ['position' => 'bottom', 'priority' => 200, 'attributes' => 'defer']
        );
        Media::addJsDef(['tridentSections' => [
            'endpoint' => $this->context->link->getModuleLink($this->name, 'sections', [], null, null, null, true),
            'versionCookies' => [VisitorContext::VERSION_COOKIE],
            'storageKey' => 'trident_sections_' . (int) $this->context->shop->id,
        ]]);
    }

    /**
     * The greeting placeholder next to the theme's sign-in link: empty and
     * hidden in the shared HTML, filled in by the browser.
     */
    public function hookDisplayNav2(array $params): string
    {
        return '<span class="trident-greeting" data-trident-if="logged_in" hidden>'
            . '<span data-trident-section="customer_name" data-trident-before="' . htmlspecialchars($this->trans('Hello, ', [], 'Modules.Tridentcache.Shop'), ENT_QUOTES) . '"></span>'
            . '</span>';
    }

    // ── the visitor's version cookie ─────────────────────────────────────

    public function hookActionCartSave(array $params): void
    {
        VisitorContext::rotateVersion();
    }

    public function hookActionAuthentication(array $params): void
    {
        VisitorContext::rotateVersion();
    }

    public function hookActionCustomerAccountAdd(array $params): void
    {
        VisitorContext::rotateVersion();
    }

    public function hookActionCustomerLogoutAfter(array $params): void
    {
        VisitorContext::rotateVersion();
    }

    // ── back office ──────────────────────────────────────────────────────

    /**
     * PrestaShop has no scheduler of its own: every back-office request also
     * delivers what is owed (throttled), after the response.
     */
    public function hookActionAdminControllerInitBefore(array $params): void
    {
        DeliveryFactory::instance()->drainAfterResponse();
    }

    // ── any ObjectModel: backstop before, purge after ────────────────────

    /**
     * Before the save: a purge due in BACKSTOP_AFTER seconds. PrestaShop saves
     * are not transactional — if the process dies between the save and its
     * After hook, this row still purges the change (a duplicate is harmless).
     */
    public function hookActionObjectAddBefore(array $params): void
    {
        $this->backstop($params['object'] ?? null);
    }

    public function hookActionObjectUpdateBefore(array $params): void
    {
        $this->backstop($params['object'] ?? null);
    }

    public function hookActionObjectDeleteBefore(array $params): void
    {
        $this->backstop($params['object'] ?? null);
    }

    public function hookActionObjectAddAfter(array $params): void
    {
        $this->afterObject($params['object'] ?? null);
    }

    public function hookActionObjectUpdateAfter(array $params): void
    {
        $this->afterObject($params['object'] ?? null);
    }

    public function hookActionObjectDeleteAfter(array $params): void
    {
        $this->afterObject($params['object'] ?? null);
    }

    private function backstop(mixed $object): void
    {
        if (!is_object($object)) {
            return;
        }
        try {
            if ($object instanceof Product && (int) $object->id > 0 && !isset($this->before['product'][(int) $object->id])) {
                $row = Db::getInstance()->getRow('SELECT price, active FROM `' . _DB_PREFIX_ . 'product_shop` WHERE id_product = ' . (int) $object->id . ' AND id_shop = ' . (int) $this->context->shop->id);
                $this->before['product'][(int) $object->id] = $row ?: ['price' => null, 'active' => null];
            }
            DeliveryFactory::instance()->backstop(ChangeTags::forObject($object));
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('Trident: backstop failed: ' . $e->getMessage(), 3, null, 'TridentCache');
        }
    }

    private function afterObject(mixed $object): void
    {
        if (!is_object($object)) {
            return;
        }
        $class = (new \ReflectionClass($object))->getShortName();
        // Scheduled prices: purge again when they start and end.
        if ($class === 'SpecificPrice' || $class === 'SpecificPriceRule') {
            $tags = $class === 'SpecificPrice' ? ChangeTags::specificPrice($object) : ['all'];
            foreach (ChangeTags::priceSchedule($object, time()) as $when) {
                DeliveryFactory::instance()->notBefore($tags, $when);
            }
        }
        if (in_array($class, self::SPECIFIC, true)) {
            return;
        }
        $this->record(ChangeTags::forObject($object));
    }

    // ── catalogue changes → purges ───────────────────────────────────────

    public function hookActionObjectProductAddAfter(array $params): void
    {
        $this->record(ChangeTags::product($params['object'] ?? null, true));
    }

    public function hookActionObjectProductUpdateAfter(array $params): void
    {
        $product = $params['object'] ?? null;
        $this->record(ChangeTags::product($product, $this->listingsChanged($product)));
    }

    public function hookActionObjectProductDeleteAfter(array $params): void
    {
        $this->record(ChangeTags::product($params['object'] ?? null, true));
    }

    /** The PS 9 product form saves through CQRS commands and ends here. */
    public function hookActionProductUpdate(array $params): void
    {
        $id = (int) ($params['id_product'] ?? 0);
        $this->record(ChangeTags::productId($id, $this->listingsChanged($params['product'] ?? null)));
    }

    /**
     * Did this save change what the price/date listings show — its price or
     * whether it is active (compared with the row read before the save)?
     */
    private function listingsChanged(mixed $product): bool
    {
        if (!is_object($product) || (int) ($product->id ?? 0) <= 0) {
            return false;
        }
        $before = $this->before['product'][(int) $product->id] ?? null;
        if ($before === null) {
            return true;
        }

        return (float) $before['price'] !== (float) ($product->price ?? 0) || (bool) $before['active'] !== (bool) ($product->active ?? false);
    }

    public function hookActionObjectCombinationUpdateAfter(array $params): void
    {
        $this->record(ChangeTags::productId((int) ($params['object']->id_product ?? 0)));
    }

    public function hookActionObjectCombinationDeleteAfter(array $params): void
    {
        $this->record(ChangeTags::productId((int) ($params['object']->id_product ?? 0)));
    }

    /** Stock: only when availability flips (an order does not purge listings). */
    public function hookActionUpdateQuantity(array $params): void
    {
        $this->record(ChangeTags::quantity($params));
    }

    public function hookActionObjectCategoryUpdateBefore(array $params): void
    {
        $this->before['category'] = ChangeTags::categorySnapshot($params['object'] ?? null);
    }

    public function hookActionObjectCategoryAddAfter(array $params): void
    {
        $this->record(ChangeTags::category($params['object'] ?? null, null));
    }

    public function hookActionObjectCategoryUpdateAfter(array $params): void
    {
        $this->record(ChangeTags::category($params['object'] ?? null, $this->before['category'] ?? null));
    }

    public function hookActionObjectCategoryDeleteAfter(array $params): void
    {
        $this->record(ChangeTags::category($params['object'] ?? null, null));
    }

    public function hookActionObjectCMSUpdateBefore(array $params): void
    {
        $this->before['cms'] = ChangeTags::cmsSnapshot($params['object'] ?? null);
    }

    public function hookActionObjectCMSAddAfter(array $params): void
    {
        $this->record(ChangeTags::cms($params['object'] ?? null, null));
    }

    public function hookActionObjectCMSUpdateAfter(array $params): void
    {
        $this->record(ChangeTags::cms($params['object'] ?? null, $this->before['cms'] ?? null));
    }

    public function hookActionObjectCMSDeleteAfter(array $params): void
    {
        $this->record(ChangeTags::cms($params['object'] ?? null, null));
    }

    public function hookActionObjectManufacturerUpdateAfter(array $params): void
    {
        $this->record(['m_' . (int) ($params['object']->id ?? 0)]);
    }

    public function hookActionObjectManufacturerDeleteAfter(array $params): void
    {
        $this->record(['m_' . (int) ($params['object']->id ?? 0)]);
    }

    public function hookActionObjectSupplierUpdateAfter(array $params): void
    {
        $this->record(['s_' . (int) ($params['object']->id ?? 0)]);
    }

    public function hookActionObjectSupplierDeleteAfter(array $params): void
    {
        $this->record(['s_' . (int) ($params['object']->id ?? 0)]);
    }

    public function hookActionObjectSpecificPriceAddAfter(array $params): void
    {
        $this->record(ChangeTags::specificPrice($params['object'] ?? null));
    }

    public function hookActionObjectSpecificPriceUpdateAfter(array $params): void
    {
        $this->record(ChangeTags::specificPrice($params['object'] ?? null));
    }

    public function hookActionObjectSpecificPriceDeleteAfter(array $params): void
    {
        $this->record(ChangeTags::specificPrice($params['object'] ?? null));
    }

    /** Catalogue price rules and exchange rates reprice everything. */
    public function hookActionObjectSpecificPriceRuleAddAfter(array $params): void
    {
        $this->recordAll();
    }

    public function hookActionObjectSpecificPriceRuleUpdateAfter(array $params): void
    {
        $this->recordAll();
    }

    public function hookActionObjectSpecificPriceRuleDeleteAfter(array $params): void
    {
        $this->recordAll();
    }

    public function hookActionObjectCurrencyUpdateAfter(array $params): void
    {
        $this->recordAll();
    }

    /**
     * @param list<string> $tags unprefixed
     */
    private function record(array $tags): void
    {
        if ($tags === []) {
            return;
        }
        $delivery = DeliveryFactory::instance();
        $delivery->delivery()->recordTags($tags);
        $delivery->flushAfterResponse();
    }

    private function recordAll(): void
    {
        $delivery = DeliveryFactory::instance();
        $delivery->delivery()->recordAll();
        $delivery->flushAfterResponse();
    }
}
