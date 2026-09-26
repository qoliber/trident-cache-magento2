<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Qoliber\Trident\Admin\Fleet;
use Qoliber\Trident\Client\TridentClient as AdminClient;
use Qoliber\Trident\Delivery\Instance;
use Qoliber\Trident\Delivery\Transport;
use Qoliber\TridentCache\Model\Clock;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\Outbox\PurgeOutboxInterface;
use Qoliber\TridentCache\Model\TridentClient;

/**
 * X03 on the Trident screens: which instances there are, which one the screen
 * is showing (the switcher), and every instance side by side (the overview
 * on Statistics and Cache Management). An instance that is down shows as
 * such; it never blanks the others.
 *
 * The overview is for stores with several instances — one instance is what
 * the page around it already shows — and reads through the library's Fleet
 * on a short-timeout transport, so a dead edge costs the Cache Management
 * page a second or two, not the admin request timeout.
 */
class Instances implements ArgumentInterface
{
    /**
     * @param TridentClient $tridentClient
     * @param Config $config
     * @param PurgeOutboxInterface $outbox
     * @param Clock $clock
     * @param Transport $transport A short-timeout transport (di.xml).
     */
    public function __construct(
        private readonly TridentClient $tridentClient,
        private readonly Config $config,
        private readonly PurgeOutboxInterface $outbox,
        private readonly Clock $clock,
        private readonly Transport $transport
    ) {
    }

    /**
     * @return bool
     */
    public function isTridentConfigured(): bool
    {
        return $this->config->isTridentEnabled();
    }

    /**
     * @return list<Instance>
     */
    public function all(): array
    {
        return $this->config->getInstances();
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->config->getInstanceErrors();
    }

    /**
     * Whether there is more than one instance to compare.
     *
     * @return bool
     */
    public function hasSeveral(): bool
    {
        return count($this->all()) > 1;
    }

    /**
     * The instance this screen shows.
     *
     * @return Instance|null
     */
    public function current(): ?Instance
    {
        return $this->tridentClient->target();
    }

    /**
     * One row per instance: what it answers, or why it does not. Empty with a
     * single instance.
     *
     * @return list<array{name: string, url: string, current: bool, ok: bool, reason: string,
     *     version: string, license: string, mode: string, entries: int|null, hit_ratio: float|null,
     *     pending: int}>
     */
    public function overview(): array
    {
        if (!$this->hasSeveral()) {
            return [];
        }
        $current = $this->current()?->name;
        try {
            $pending = $this->outbox->stats($this->clock->now())['by_instance'];
        } catch (\Throwable $e) {
            $pending = [];
        }
        $fleet = new Fleet($this->all(), $this->transport);
        $rows = [];
        foreach ($fleet->each(fn (AdminClient $client): array => [
            'status' => $client->status(),
            'stats' => Fleet::attempt(fn () => $client->stats()),
        ]) as $result) {
            $status = $result->value['status'] ?? null;
            $stats = $result->value['stats'] ?? null;
            $instance = $result->instance;
            $rows[] = [
                'name' => $instance->name,
                'url' => $instance->apiUrl,
                'current' => $instance->name === $current,
                'ok' => $result->isOk(),
                'reason' => match (true) {
                    $result->isOk() => '',
                    $instance->apiToken === '' => (string) __('no API token'),
                    default => $result->reason(),
                },
                'version' => $status?->string('version') ?? '',
                'license' => $status?->string('license') ?? '',
                'mode' => $status?->string('mode') ?? '',
                'entries' => $stats?->getEntries(),
                'hit_ratio' => $stats?->getHitRatioPercent(),
                'pending' => (int) ($pending[$instance->name] ?? 0),
            ];
        }
        return $rows;
    }
}
