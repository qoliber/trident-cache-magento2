<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration\Observer;

use Magento\Framework\App\Cache\Tag\Resolver;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\PurgeStrategy;
use Qoliber\TridentCache\Model\TridentClient;
use Qoliber\TridentCache\Observer\FlushCacheByTagsObserver;

class FlushCacheByTagsObserverTest extends TestCase
{
    private FlushCacheByTagsObserver $observer;
    private TridentClient&MockObject $clientMock;
    private Config&MockObject $configMock;
    private Resolver&MockObject $tagResolverMock;
    private PurgeStrategy&MockObject $purgeStrategyMock;

    protected function setUp(): void
    {
        $this->clientMock = $this->createMock(TridentClient::class);
        $this->configMock = $this->createMock(Config::class);
        $this->tagResolverMock = $this->createMock(Resolver::class);
        $this->purgeStrategyMock = $this->createMock(PurgeStrategy::class);

        $this->observer = new FlushCacheByTagsObserver(
            $this->clientMock,
            $this->configMock,
            $this->tagResolverMock,
            $this->purgeStrategyMock
        );
    }

    public function testProductSaveTriggersPurgeWithCorrectTags(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);

        $entity = new DataObject();
        $tags = ['cat_p', 'cat_p_42', 'CAT_C_P_42'];

        $this->tagResolverMock->method('getTags')->with($entity)->willReturn($tags);
        $this->purgeStrategyMock->method('filterTags')->with($entity, $tags)->willReturn($tags);

        $this->clientMock->expects($this->once())
            ->method('purgeTags')
            ->with($this->callback(function (array $normalizedTags): bool {
                return $normalizedTags === ['cat_p', 'cat_p_42', 'cat_c_p_42'];
            }));

        $observer = $this->createObserver($entity);
        $this->observer->execute($observer);
    }

    public function testTagsAreNormalizedToLowercase(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);

        $entity = new DataObject();
        $tags = ['CAT_P_1', 'Cat_C_2', 'cms_p_3'];

        $this->tagResolverMock->method('getTags')->with($entity)->willReturn($tags);
        $this->purgeStrategyMock->method('filterTags')->willReturnArgument(1);

        $this->clientMock->expects($this->once())
            ->method('purgeTags')
            ->with(['cat_p_1', 'cat_c_2', 'cms_p_3']);

        $this->observer->execute($this->createObserver($entity));
    }

    public function testObserverDoesNothingWhenTridentDisabled(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(false);

        $this->clientMock->expects($this->never())->method('purgeTags');
        $this->tagResolverMock->expects($this->never())->method('getTags');

        $this->observer->execute($this->createObserver(new DataObject()));
    }

    public function testObserverDoesNothingWhenNoObject(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);

        $this->clientMock->expects($this->never())->method('purgeTags');

        $event = $this->createMock(Event::class);
        $event->method('getObject')->willReturn(null);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $this->observer->execute($observer);
    }

    public function testObserverDoesNothingWhenNoTags(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);

        $entity = new DataObject();
        $this->tagResolverMock->method('getTags')->with($entity)->willReturn([]);

        $this->clientMock->expects($this->never())->method('purgeTags');

        $this->observer->execute($this->createObserver($entity));
    }

    private function createObserver(object $entity): Observer
    {
        $event = $this->createMock(Event::class);
        $event->method('getObject')->willReturn($entity);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }
}
