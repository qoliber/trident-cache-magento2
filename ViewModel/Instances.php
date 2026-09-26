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
use Qoliber\Trident\Delivery\Instance;
use Qoliber\TridentCache\Model\Clock;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\Outbox\PurgeOutboxInterface;
use Qoliber\TridentCache\Model\TridentClient;

/**
 * X03 on the Trident screens: which instances there are, which one the screen
 * is showing (the switcher), and every instance side by side (the overview
 * on Statistics and Cache Management). An instance that is down shows as
 * such; it never blanks the others.
 */
class Instances implements ArgumentInterface
{
    /**
     * @param TridentClient $tridentClient
     * @param Config $config
     * @param PurgeOutboxInterface $outbox
     * @param Clock $clock
     */
    public function __construct(
        private readonly TridentClient $tridentClient,
        private readonly Config $config,
        private readonly PurgeOutboxInterface $outbox,
        private readonly Clock $clock
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
     * The instance this screen shows.
     *
     * @return Instance|null
     */
    public function current(): ?Instance
    {
        return $this->tridentClient->target();
    }

    /**
     * One row per instance: what it answers, or why it does not.
     *
     * @return list<array{name: string, url: string, current: bool, ok: bool, reason: string,
     *     version: string, license: string, mode: string, entries: int|null, hit_ratio: float|null,
     *     pending: int}>
     */
    public function overview(): array
    {
        $current = $this->current()?->name;
        try {
            $pending = $this->outbox->stats($this->clock->now())['by_instance'];
        } catch (\Throwable $e) {
            $pending = [];
        }
        $rows = [];
        foreach ($this->all() as $instance) {
            $client = $this->tridentClient->forInstance($instance);
            $status = $client->getStatus();
            $stats = $status !== null ? $client->getStats() : null;
            $rows[] = [
                'name' => $instance->name,
                'url' => $instance->apiUrl,
                'current' => $instance->name === $current,
                'ok' => $status !== null,
                'reason' => $status === null
                    ? ($instance->apiToken === '' ? (string) __('no API token') : (string) $client->lastFailure())
                    : '',
                'version' => is_scalar($status['version'] ?? null) ? (string) $status['version'] : '',
                'license' => is_scalar($status['license'] ?? null) ? (string) $status['license'] : '',
                'mode' => is_scalar($status['mode'] ?? null) ? (string) $status['mode'] : '',
                'entries' => is_numeric($stats['entries'] ?? null) ? (int) $stats['entries'] : null,
                'hit_ratio' => is_numeric($stats['hit_ratio'] ?? null) ? (float) $stats['hit_ratio'] : null,
                'pending' => (int) ($pending[$instance->name] ?? 0),
            ];
        }
        return $rows;
    }
}
