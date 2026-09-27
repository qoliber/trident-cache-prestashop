<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Command;

use Qoliber\TridentPrestaShop\Config\TridentConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The trident.toml `pass_patterns` for this shop: the cart, checkout and
 * account slugs are TRANSLATED per language, so they are read from the shop's
 * own routes rather than hard-coded.
 */
final class ConfigCommand extends Command
{
    protected static $defaultName = 'trident:config';

    protected function configure(): void
    {
        $this->setName('trident:config')->setDescription('Print the trident.toml pass_patterns for this shop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(TridentConfig::passPatternsToml());

        return self::SUCCESS;
    }
}
