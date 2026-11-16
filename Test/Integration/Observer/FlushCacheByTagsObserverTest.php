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
use Qoliber\TridentCache\Model\PurgeAfterCommit;
use Qoliber\TridentCache\Model\PurgeStrategy;
use Qoliber\TridentCache\Observer\FlushCacheByTagsObserver;

class FlushCacheByTagsObserverTest extends TestCase
{
    private FlushCacheByTagsObserver $observer;
    private PurgeAfterCommit&MockObject $purgeMock;
    private Config&MockObject $configMock;
    private Resolver&MockObject $tagResolverMock;
    private PurgeStrategy&MockObject $purgeStrategyMock;

    protected function setUp(): void
    {
        $this->purgeMock = $this->createMock(PurgeAfterCommit::class);
        $this->configMock = $this->createMock(Config::class);
        $this->tagResolverMock = $this->createMock(Resolver::class);
        $this->purgeStrategyMock = $this->createMock(PurgeStrategy::class);

        $this->observer = new FlushCacheByTagsObserver(
            $this->purgeMock,
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

        $this->purgeMock->expects($this->once())
            ->method('purgeTags')
            ->with($this->callback(function (array $purgedTags): bool {
                return $purgedTags === ['cat_p', 'cat_p_42', 'CAT_C_P_42'];
            }));

        $observer = $this->createObserver($entity);
        $this->observer->execute($observer);
    }

    /**
     * Trident matches tags case-sensitively and stores them as X-Magento-Tags
     * carries them, which the module passes through untouched. A purge has to
     * name the tag in the same case or it matches nothing.
     */
    public function testTagsKeepTheCaseTheResponseHeaderCarries(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);

        $entity = new DataObject();
        $tags = ['CAT_P_1', 'Cat_C_2', 'cms_p_3'];

        $this->tagResolverMock->method('getTags')->with($entity)->willReturn($tags);
        $this->purgeStrategyMock->method('filterTags')->willReturnArgument(1);

        $this->purgeMock->expects($this->once())
            ->method('purgeTags')
            ->with(['CAT_P_1', 'Cat_C_2', 'cms_p_3']);

        $this->observer->execute($this->createObserver($entity));
    }

    public function testDuplicateTagsAreSentOnceAndCaseVariantsStayDistinct(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);

        $entity = new DataObject();
        $tags = ['cat_p_1', 'cat_p_1', 'CAT_P_1'];

        $this->tagResolverMock->method('getTags')->with($entity)->willReturn($tags);
        $this->purgeStrategyMock->method('filterTags')->willReturnArgument(1);

        $this->purgeMock->expects($this->once())
            ->method('purgeTags')
            ->with(['cat_p_1', 'CAT_P_1']);

        $this->observer->execute($this->createObserver($entity));
    }

    public function testObserverDoesNothingWhenTridentDisabled(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(false);

        $this->purgeMock->expects($this->never())->method('purgeTags');
        $this->tagResolverMock->expects($this->never())->method('getTags');

        $this->observer->execute($this->createObserver(new DataObject()));
    }

    public function testObserverDoesNothingWhenNoObject(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);

        $this->purgeMock->expects($this->never())->method('purgeTags');

        $this->observer->execute(new Observer(['event' => new Event(['object' => null])]));
    }

    public function testObserverDoesNothingWhenNoTags(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);

        $entity = new DataObject();
        $this->tagResolverMock->method('getTags')->with($entity)->willReturn([]);

        $this->purgeMock->expects($this->never())->method('purgeTags');

        $this->observer->execute($this->createObserver($entity));
    }

    /**
     * Real objects, not mocks: Event::getObject() is a magic DataObject
     * accessor, which PHPUnit refuses to configure — every test here used to
     * error out before reaching the observer.
     */
    private function createObserver(object $entity): Observer
    {
        return new Observer(['event' => new Event(['object' => $entity])]);
    }
}
