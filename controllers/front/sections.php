<?php
/**
 * The visitor's personal sections for the browser (trident-sections.js):
 * the cart count and the greeting. Never cached; asked for only when the
 * visitor's version cookie changed.
 *
 * @author    qoliber
 * @license   MIT
 */

declare(strict_types=1);

use Qoliber\TridentPrestaShop\Cache\VisitorContext;

class TridentCacheSectionsModuleFrontController extends ModuleFrontController
{
    /**
     * Answers and ends the request itself: a JSON endpoint, never a page
     * (PrestaShop would otherwise go on to render a template).
     */
    public function initContent(): void
    {
        $customer = $this->context->customer;
        $logged = $customer->isLogged();
        $cart = $this->context->cart;
        $count = $cart instanceof Cart && (int) $cart->id > 0 ? (int) $cart->nbProducts() : 0;

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        $this->context->cookie->disallowWriting();
        echo json_encode([
            'version' => (string) ($_COOKIE[VisitorContext::VERSION_COOKIE] ?? ''),
            'sections' => [
                'logged_in' => $logged,
                'customer_name' => $logged ? trim($customer->firstname . ' ' . $customer->lastname) : '',
                'cart_count' => $count,
                'cart_count_label' => '(' . $count . ')',
                // The shared page carries the GUEST token; PrestaShop's cart
                // ignores a logged-in (or known) customer's update without
                // their own (PS_TOKEN_ENABLE). The browser patches it in.
                'static_token' => Tools::getToken(false),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
