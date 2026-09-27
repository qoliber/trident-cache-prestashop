<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Cache;

/**
 * Other modules that print THIS visitor's data into the page — detected on the
 * server, whatever the theme:
 *
 * - ps_viewedproduct renders the visitor's history (cookie `viewed`) on a
 *   product page, minus the product itself;
 * - ps_googleanalytics keeps the visitor's pending cart events
 *   (`ganalytics_data`, by cart id) and prints them into their next render;
 * - a notification (a one-off flash message) PrestaShop assigns to smarty.
 */
final class PersonalContent
{
    /**
     * The products a viewed-products block would list: the history minus the
     * current product.
     *
     * @return list<int>
     */
    public static function viewedIds(string $viewed, int $currentProduct): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $viewed)), static fn (int $id): bool => $id > 0)));

        return array_values(array_filter($ids, static fn (int $id): bool => $id !== $currentProduct));
    }

    /**
     * @param mixed $notifications smarty's `notifications` (error/warning/success/info lists)
     */
    public static function hasNotification(mixed $notifications): bool
    {
        if (!\is_array($notifications)) {
            return false;
        }
        foreach (['error', 'warning', 'success', 'info'] as $kind) {
            if (!empty($notifications[$kind])) {
                return true;
            }
        }

        return false;
    }

    public static function reason(\Context $context): ?string
    {
        $controller = $context->controller;
        if (self::hasNotification($context->smarty->getTemplateVars('notifications'))) {
            return 'notification';
        }
        if (($controller->php_self ?? '') === 'product' && \Module::isEnabled('ps_viewedproduct')
            && self::viewedIds((string) ($context->cookie->viewed ?? ''), (int) \Tools::getValue('id_product')) !== []) {
            return 'viewed products';
        }
        $cart = (int) ($context->cookie->id_cart ?? 0);
        if ($cart > 0 && \Module::isEnabled('ps_googleanalytics')) {
            try {
                if (\Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'ganalytics_data` WHERE id_cart = ' . $cart)) {
                    return 'pending analytics events';
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
