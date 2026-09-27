<?php

declare(strict_types=1);

namespace Qoliber\TridentPrestaShop\Command;

use Qoliber\TridentPrestaShop\Delivery\DeliveryFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deliver what the outbox owes (cron: every minute). `--now` delivers every
 * row whatever its due time — second deliveries and rows in backoff too.
 */
final class PurgeDrainCommand extends Command
{
    protected static $defaultName = 'trident:purge:drain';

    protected function configure(): void
    {
        $this->setName('trident:purge:drain')
            ->setDescription('Deliver the purges Trident has not acknowledged yet')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows per run', '200')
            ->addOption('now', null, InputOption::VALUE_NONE, 'Every row now, whatever its due time');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $delivery = DeliveryFactory::instance()->delivery();
        $limit = max(1, (int) $input->getOption('limit'));
        $report = $input->getOption('now') ? $delivery->drainAll($limit) : $delivery->drain($limit);
        $output->writeln(sprintf('Delivered %d purge(s), %d failed, %d cache entries purged; %d pending.', $report->delivered, $report->failed, $report->purged, (int) ($delivery->status()['pending'] ?? 0)));

        return $report->failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
