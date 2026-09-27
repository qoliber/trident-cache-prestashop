<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Admin;

use Qoliber\Trident\Admin\AdminContext;
use Qoliber\Trident\Admin\AdminService;
use Qoliber\Trident\Admin\PurgeOutbox;
use Qoliber\Trident\Http\TransportFactory;
use Qoliber\TridentPrestaShop\Config\SettingsProvider;
use Qoliber\TridentPrestaShop\Delivery\DeliveryFactory;
use Qoliber\TridentPrestaShop\Delivery\PsLogger;

/**
 * This request's configuration for the shared admin screens.
 */
final class PsAdminContext implements AdminContext
{
    public static function service(): AdminService
    {
        return new AdminService(static fn (): AdminContext => new self(), new PsShopAdapter(), new TransportFactory(), new PsLogger());
    }

    public function instances(): array
    {
        return SettingsProvider::get()->instances;
    }

    public function mode(): string
    {
        return SettingsProvider::get()->mode;
    }

    public function view(): array
    {
        return SettingsProvider::view();
    }

    public function outbox(): PurgeOutbox
    {
        return DeliveryFactory::instance()->delivery();
    }
}
