<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration\Observer;

use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Model\TridentClient;
use Qoliber\TridentCache\Observer\CacheFlushObserver;

class CacheFlushObserverTest extends TestCase
{
    private CacheFlushObserver $observer;
    private TridentClient&MockObject $clientMock;

    protected function setUp(): void
    {
        $this->clientMock = $this->createMock(TridentClient::class);
        $this->observer = new CacheFlushObserver($this->clientMock);
    }

    public function testCacheFlushTriggersPurgeAll(): void
    {
        $this->clientMock->method('isEnabled')->willReturn(true);

        $this->clientMock->expects($this->once())
            ->method('purgeAll')
            ->willReturn(['cleared' => true]);

        $observerMock = $this->createMock(Observer::class);
        $this->observer->execute($observerMock);
    }

    public function testObserverDoesNothingWhenDisabled(): void
    {
        $this->clientMock->method('isEnabled')->willReturn(false);

        $this->clientMock->expects($this->never())->method('purgeAll');

        $observerMock = $this->createMock(Observer::class);
        $this->observer->execute($observerMock);
    }
}
