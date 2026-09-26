<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\ViewModel;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qoliber\Trident\Delivery\Instance;
use Qoliber\Trident\Testing\FakeTransport;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\TridentClient;
use Qoliber\TridentCache\Test\Unit\Model\Fake\FixedClock;
use Qoliber\TridentCache\Test\Unit\Model\Fake\TransactionalOutbox;
use Qoliber\TridentCache\ViewModel\Instances;

/**
 * Review #8: the overview on Cache Management asked every instance twice on
 * each page load — with one instance too, where the page already shows it.
 */
class InstancesTest extends TestCase
{
    private FakeTransport $http;

    /** @var list<Instance> */
    private array $instances;

    private function viewModel(): Instances
    {
        $this->http ??= new FakeTransport();
        $config = $this->createMock(Config::class);
        $config->method('isTridentEnabled')->willReturn(true);
        $config->method('getInstances')->willReturnCallback(fn (): array => $this->instances);
        $client = new TridentClient($this->http, new NullLogger(), $config);
        $outbox = new TransactionalOutbox(fn (): bool => false);
        $outbox->seed('tags', ['cat_p_1'], 'edge-2');

        return new Instances($client, $config, $outbox, new FixedClock(), $this->http);
    }

    public function testWithOneInstanceNothingIsAsked(): void
    {
        $this->instances = [new Instance('default', 'http://edge-1:9301', 'token')];
        $viewModel = $this->viewModel();

        $this->assertFalse($viewModel->hasSeveral());
        $this->assertSame([], $viewModel->overview());
        $this->assertSame([], $this->http->requests);
    }

    public function testEveryInstanceIsShownAndADeadOneSaysWhy(): void
    {
        $this->instances = [
            new Instance('edge-1', 'http://edge-1:9301', 't1'),
            new Instance('edge-2', 'http://edge-2:9301', 't2'),
        ];
        $this->http = (new FakeTransport())
            ->answer('edge-1', 200, '{"status":"ok","version":"1.8.0","license":"valid","mode":"licensed","entries":7,"hits":3,"misses":1}')
            ->down('edge-2');

        [$one, $two] = $this->viewModel()->overview();

        $this->assertTrue($one['ok']);
        $this->assertTrue($one['current']);
        $this->assertSame(['1.8.0', 'valid', 7, 75.0], [$one['version'], $one['license'], $one['entries'], $one['hit_ratio']]);
        $this->assertFalse($two['ok']);
        $this->assertStringContainsString('Failed to connect', $two['reason']);
        $this->assertSame(1, $two['pending']);
    }
}
