<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Command;

use Qoliber\TridentPrestaShop\Delivery\DeliveryFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The outbox for monitoring: exit 1 when a purge has been owed longer than
 * `--stale-after` seconds (default 900), or when the settings have errors.
 */
final class PurgeStatusCommand extends Command
{
    protected static $defaultName = 'trident:purge:status';

    protected function configure(): void
    {
        $this->setName('trident:purge:status')
            ->setDescription('Pending purges; exit 1 when one is owed too long')
            ->addOption('stale-after', null, InputOption::VALUE_REQUIRED, 'Seconds a purge may be owed', '900')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $delivery = DeliveryFactory::instance()->delivery();
        $status = $delivery->status();
        $errors = $delivery->settings()->errors;
        $stale = $status['oldest_age'] !== null && (int) $status['oldest_age'] > max(0, (int) $input->getOption('stale-after'));
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($status + ['errors' => $errors, 'instances' => $delivery->settings()->instanceNames()]));
        } else {
            $output->writeln(sprintf('Instances: %s', implode(', ', $delivery->settings()->instanceNames()) ?: '(none)'));
            $output->writeln(sprintf('Pending: %d, scheduled: %d, oldest: %s s', $status['pending'], $status['scheduled'], $status['oldest_age'] ?? '-'));
            foreach ($status['by_instance'] as $name => $count) {
                $output->writeln(sprintf('  %s: %d', $name, $count));
            }
            foreach ($status['orphaned'] ?? [] as $name => $count) {
                $output->writeln(sprintf('  <error>%s: %d owed to an instance no longer configured (trident:purge:forget %s)</error>', $name, $count, $name));
            }
            if ($status['last_error'] !== null) {
                $output->writeln('Last failure: ' . $status['last_error']);
            }
            foreach ($errors as $error) {
                $output->writeln('<error>' . $error . '</error>');
            }
        }

        return $stale || $errors !== [] || ($status['orphaned'] ?? []) !== [] ? self::FAILURE : self::SUCCESS;
    }
}
