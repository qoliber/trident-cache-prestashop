<?php
/**
 * 1.0.1: the outbox gains `not_before` (scheduled prices are never delivered
 * early); the module registers its new hooks.
 *
 * @author    qoliber
 * @license   MIT
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param TridentCache $module
 */
function upgrade_module_1_0_1($module): bool
{
    return Qoliber\TridentPrestaShop\Delivery\PsOutboxStore::ensureSchema()
        && $module->registerHook(TridentCache::HOOKS);
}
