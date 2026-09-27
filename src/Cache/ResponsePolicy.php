<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Cache;

use Qoliber\TridentPrestaShop\Config\SettingsProvider;
use Qoliber\TridentPrestaShop\Delivery\DeliveryFactory;
use Qoliber\TridentPrestaShop\Tags\PageTags;

/**
 * Whether this storefront render may be shared, decided on the FINAL HTML
 * (`actionOutputHTMLBefore`, before anything is sent).
 *
 * Shared — `public, s-maxage`, the page's cache tags, and NO cookie — only
 * when it is exactly what any visitor in the same price context would get:
 * a GET of a catalogue page, answered 200, for a visitor who is not logged
 * in and has an empty cart, with no one-off notification on the page, and
 * whose price-context marker matches the one computed for the render.
 *
 * PrestaShop writes its visitor cookie (`PrestaShop-<hash>`) and starts a PHP
 * session (`FrontController::prepareNotifications`) on EVERY render, a guest's
 * first included; stored with the page, either would hand one visitor's
 * session to the next. A shared render therefore drops every Set-Cookie and
 * stops the cookie from being written — the guest gets a cookie as soon as
 * they do something (add to cart, log in), on a response that is never shared.
 *
 * Everything else goes out `private, no-store`: Trident then serves the
 * visitor the shared page of their context when it has one, and never stores
 * their render.
 */
final class ResponsePolicy
{
    /** Front controllers whose pages are the same for every visitor in a context. */
    public const CACHEABLE = [
        'index', 'category', 'product', 'cms', 'manufacturer', 'supplier',
        'best-sales', 'new-products', 'prices-drop', 'search', 'sitemap', 'stores',
    ];
    public const S_MAXAGE = 3600;
    public const STALE_WHILE_REVALIDATE = 86400;

    public function __construct(private readonly \Context $context)
    {
    }

    public static function fromContext(\Context $context): self
    {
        return new self($context);
    }

    public function apply(string $html): void
    {
        if (headers_sent()) {
            return;
        }
        $settings = SettingsProvider::get();
        $reason = $this->refusal($html);
        $marker = VisitorContext::marker($this->context);
        $matches = VisitorContext::matches(VisitorContext::sentMarker(), $marker);
        if ($reason === null && !$matches) {
            $reason = 'price context marker';
        }
        // Renew the marker whenever the response is not shared (PrestaShop
        // renews its own cookie then too), so it never expires mid-visit.
        if (!$matches || ($reason !== null && VisitorContext::sentMarker() !== '')) {
            VisitorContext::sendMarker($marker);
        }
        if ($reason === null && !$settings->enabled()) {
            $reason = 'no Trident instance configured';
        }

        if ($reason !== null) {
            if ($this->personal() && !VisitorContext::hasVersion()) {
                // A logged-in visitor / cart holder without a version cookie
                // (logged in before the module): the browser must fetch theirs.
                VisitorContext::rotateVersion();
            }
            header('Cache-Control: private, no-store');
            header_remove('Expires');
            if ($settings->debugHeaders) {
                header('X-Trident-Decision: no-store; ' . $reason);
            }

            return;
        }

        $this->context->cookie->disallowWriting();
        header_remove('Set-Cookie');
        header_remove('Pragma');
        header_remove('Expires');
        header('Cache-Control: public, max-age=0, s-maxage=' . self::S_MAXAGE . ', stale-while-revalidate=' . self::STALE_WHILE_REVALIDATE);
        header('X-Cache-Tags: ' . DeliveryFactory::policy()->headerValue(PageTags::forPage($this->context, $html)));
        if ($settings->debugHeaders) {
            header('X-Trident-Decision: cacheable');
        }
    }

    /**
     * Why this render must not be shared, or null.
     */
    public function refusal(string $html): ?string
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return 'method';
        }
        $status = (int) http_response_code();
        if ($status !== 200) {
            return 'status ' . $status;
        }
        $controller = $this->context->controller;
        $page = (string) ($controller->php_self ?? '');
        if (!\in_array($page, self::CACHEABLE, true)) {
            return 'page ' . ($page !== '' ? $page : \get_class($controller));
        }
        // Maintenance: employees and PS_MAINTENANCE_IP get real pages while
        // the shop is closed — never stored.
        if (!(int) \Configuration::get('PS_SHOP_ENABLE')) {
            return 'maintenance';
        }
        // Geolocation: a visitor from a restricted country gets a different
        // page (catalogue only, or blocked) — never shared.
        if (\Configuration::get('PS_GEOLOCATION_ENABLED') && method_exists($controller, 'getRestrictedCountry')
            && $controller->getRestrictedCountry() !== \Country::GEOLOC_ALLOWED) {
            return 'geolocation';
        }
        if (isset($_GET['trident-nocache'])) {
            return 'query trident-nocache';
        }
        // Any customer the render KNOWS — logged in, a guest who went through
        // checkout, or one whose session is no longer alive (isLogged() is
        // false for the last two, yet PrestaShop loads them from the cookie and
        // the page carries their e-mail, name, addresses, token and prices).
        if ((int) $this->context->customer->id > 0) {
            return $this->context->customer->isLogged() ? 'logged-in' : 'customer';
        }
        if ($this->cartHasProducts()) {
            return 'cart';
        }
        $personal = PersonalContent::reason($this->context);
        if ($personal !== null) {
            return $personal;
        }

        return null;
    }

    private function personal(): bool
    {
        return (int) $this->context->customer->id > 0 || $this->cartHasProducts();
    }

    private function cartHasProducts(): bool
    {
        $cart = $this->context->cart;

        return $cart instanceof \Cart && (int) $cart->id > 0 && $cart->nbProducts() > 0;
    }
}
