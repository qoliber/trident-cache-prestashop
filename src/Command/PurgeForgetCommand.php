<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Command;

use Qoliber\TridentPrestaShop\Delivery\DeliveryFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drop the rows owed to an instance that is no longer configured. Refuses a
 * configured one: those purges are still owed.
 */
final class PurgeForgetCommand extends Command
{
    protected static $defaultName = 'trident:purge:forget';

    protected function configure(): void
    {
        $this->setName('trident:purge:forget')
            ->setDescription('Forget the purges owed to a removed instance')
            ->addArgument('instance', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $delivery = DeliveryFactory::instance()->delivery();
        $name = (string) $input->getArgument('instance');
        if (\in_array($name, $delivery->settings()->instanceNames(), true)) {
            $output->writeln(sprintf('<error>"%s" is configured: its purges are still owed.</error>', $name));

            return self::FAILURE;
        }
        $output->writeln(sprintf('Forgot %d row(s) owed to "%s".', $delivery->forget($name), $name));

        return self::SUCCESS;
    }
}
