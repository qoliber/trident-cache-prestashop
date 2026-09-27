<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Admin;

use Qoliber\TridentPrestaShop\Cache\ConfigWatcher;
use Qoliber\TridentPrestaShop\Delivery\DeliveryFactory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * PrestaShop 9's back office is Symfony (product and category saves, the
 * maintenance and preferences forms); the legacy admin hook does not run
 * there. After each such response has been sent (`kernel.terminate`): the
 * settings watcher, and the throttled drain of what the outbox owes.
 */
final class KernelSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => ['onTerminate', -1024]];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        try {
            if (!\defined('_PS_ADMIN_DIR_')) {
                return;
            }
            DeliveryFactory::markResponseSent();
            ConfigWatcher::check();
            DeliveryFactory::instance()->flushNow();
            DeliveryFactory::instance()->drainNow();
        } catch (\Throwable) {
            // Never break the back office for a purge: the outbox keeps it.
        }
    }
}
