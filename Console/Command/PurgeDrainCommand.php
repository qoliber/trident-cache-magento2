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

use Qoliber\TridentCache\Model\Clock;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\Outbox\PurgeOutboxInterface;
use Qoliber\TridentCache\Model\PurgeAfterCommit;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento trident:purge:drain` — deliver pending purges now (what the
 * cron job does every minute).
 */
class PurgeDrainCommand extends Command
{
    /**
     * @param PurgeAfterCommit $purgeAfterCommit
     * @param PurgeOutboxInterface $outbox
     * @param Config $config
     * @param Clock $clock
     */
    public function __construct(
        private readonly PurgeAfterCommit $purgeAfterCommit,
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
        $this->setName('trident:purge:drain')
            ->setDescription('Deliver purges not yet acknowledged by Trident')
            ->addOption(
                'forget',
                null,
                InputOption::VALUE_REQUIRED,
                'X03: first drop the purges owed to this instance — only for one removed for good'
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $forget = (string) $input->getOption('forget');
        if ($forget !== '') {
            foreach ($this->config->getInstances() as $instance) {
                if ($instance->name === $forget) {
                    $output->writeln(sprintf(
                        '<error>"%s" is still configured: forgetting its purges would leave it serving '
                        . 'stale pages. Remove it from env.php (and run app:config:import) first.</error>',
                        $forget
                    ));
                    return Command::FAILURE;
                }
            }
            $output->writeln(sprintf(
                'forgot %d purge(s) owed to "%s"',
                $this->outbox->forget($forget),
                $forget
            ));
        }
        // Now, not on the retry schedule: the operator is here because the
        // cause was fixed.
        $delivered = $this->purgeAfterCommit->drain(5000, true);
        $pending = $this->outbox->stats($this->clock->now())['pending'];
        $output->writeln(sprintf(
            'delivered: %d, cache entries purged: %d, still pending: %d',
            $delivered,
            $this->purgeAfterCommit->purgedByLastDrain(),
            $pending
        ));
        return $pending === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
