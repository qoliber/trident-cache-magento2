<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Console\Command;

use Qoliber\TridentCache\Cron\DrainPurgeOutbox;
use Qoliber\TridentCache\Model\Clock;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\Outbox\PurgeOutboxInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento trident:purge:status` — purges committed but not yet
 * acknowledged by Trident. Exits 1 when the oldest has waited longer than
 * the stale threshold, so a monitoring check can alert on it — or (X03)
 * when purges are owed to an instance that is no longer configured, or an
 * `instances` entry in env.php was skipped.
 */
class PurgeStatusCommand extends Command
{
    /**
     * @param PurgeOutboxInterface $outbox
     * @param Config $config
     * @param Clock $clock
     */
    public function __construct(
        private readonly PurgeOutboxInterface $outbox,
        private readonly Config $config,
        private readonly Clock $clock
    ) {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('trident:purge:status')
            ->setDescription('Purges not yet acknowledged by Trident (exit 1 when stuck)');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stats = $this->outbox->stats($this->clock->now());
        $output->writeln(sprintf('pending:      %d', $stats['pending']));
        $output->writeln(sprintf(
            'oldest age:   %s',
            $stats['oldest_age'] === null ? '-' : $stats['oldest_age'] . 's'
        ));
        $output->writeln(sprintf(
            'last failure: %s',
            $stats['last_error'] === null
                ? '-'
                : $stats['last_error'] . ' (' . gmdate('Y-m-d H:i:s', (int) $stats['last_error_at']) . ' UTC)'
        ));
        $healthy = true;
        $configured = [];
        $output->writeln('instances:');
        foreach ($this->config->getInstances() as $instance) {
            $configured[$instance->name] = true;
            $output->writeln(sprintf(
                '  %-20s %-40s pending %d',
                $instance->name,
                $instance->apiUrl,
                $stats['by_instance'][$instance->name] ?? 0
            ));
        }
        if (($stats['by_instance'][''] ?? 0) > 0) {
            $output->writeln(sprintf(
                '  %-20s %-40s pending %d',
                '(all)',
                'written before X03; split on the next drain',
                $stats['by_instance']['']
            ));
        }
        foreach ($this->config->getInstanceErrors() as $error) {
            $healthy = false;
            $output->writeln('<error>env.php instance skipped — ' . $error . '</error>');
        }
        foreach ($stats['by_instance'] as $name => $count) {
            if ($name !== '' && !isset($configured[$name])) {
                $healthy = false;
                $output->writeln(sprintf(
                    '<error>%d purge(s) owed to "%s", which is no longer configured. They are kept, not sent. '
                    . 'If it comes back, they are delivered; if it is gone for good: '
                    . 'bin/magento trident:purge:drain --forget=%s</error>',
                    $count,
                    $name,
                    $name
                ));
            }
        }
        if (($stats['oldest_age'] ?? 0) > DrainPurgeOutbox::STALE_AFTER) {
            $healthy = false;
            $output->writeln(
                '<error>Purges have waited longer than ' . DrainPurgeOutbox::STALE_AFTER . 's. '
                . 'Check that cron runs and that Trident accepts the api_token.</error>'
            );
        }
        return $healthy ? Command::SUCCESS : Command::FAILURE;
    }
}
