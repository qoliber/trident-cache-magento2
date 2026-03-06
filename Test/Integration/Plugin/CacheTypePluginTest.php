<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration\Plugin;

use Magento\PageCache\Model\Cache\Type as PageCacheType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Model\TridentClient;
use Qoliber\TridentCache\Plugin\CacheTypePlugin;
use Zend_Cache;

class CacheTypePluginTest extends TestCase
{
    private CacheTypePlugin $plugin;
    private TridentClient&MockObject $clientMock;
    private PageCacheType&MockObject $subjectMock;

    protected function setUp(): void
    {
        $this->clientMock = $this->createMock(TridentClient::class);
        $this->plugin = new CacheTypePlugin($this->clientMock);
        $this->subjectMock = $this->createMock(PageCacheType::class);
    }

    public function testCleanAllTriggersPurgeAll(): void
    {
        $this->clientMock->method('isEnabled')->willReturn(true);

        $this->clientMock->expects($this->once())->method('purgeAll');
        $this->clientMock->expects($this->never())->method('purgeTags');

        $this->plugin->afterClean($this->subjectMock, true, Zend_Cache::CLEANING_MODE_ALL);
    }

    public function testCleanWithTagsTriggersPurgeTags(): void
    {
        $this->clientMock->method('isEnabled')->willReturn(true);

        $this->clientMock->expects($this->never())->method('purgeAll');
        $this->clientMock->expects($this->once())
            ->method('purgeTags')
            ->with(['cat_p_1', 'cat_c_2']);

        $this->plugin->afterClean(
            $this->subjectMock,
            true,
            Zend_Cache::CLEANING_MODE_MATCHING_TAG,
            ['cat_p_1', 'cat_c_2']
        );
    }

    public function testInternalFpcTagFilteredOut(): void
    {
        $this->clientMock->method('isEnabled')->willReturn(true);

        $this->clientMock->expects($this->once())
            ->method('purgeTags')
            ->with(['cat_p_1']);

        $this->plugin->afterClean(
            $this->subjectMock,
            true,
            Zend_Cache::CLEANING_MODE_MATCHING_TAG,
            ['cat_p_1', 'FPC']
        );
    }

    public function testOnlyFpcTagResultsInNoPurge(): void
    {
        $this->clientMock->method('isEnabled')->willReturn(true);

        $this->clientMock->expects($this->never())->method('purgeTags');
        $this->clientMock->expects($this->never())->method('purgeAll');

        $this->plugin->afterClean(
            $this->subjectMock,
            true,
            Zend_Cache::CLEANING_MODE_MATCHING_TAG,
            ['FPC']
        );
    }

    public function testDisabledClientDoesNothing(): void
    {
        $this->clientMock->method('isEnabled')->willReturn(false);

        $this->clientMock->expects($this->never())->method('purgeAll');
        $this->clientMock->expects($this->never())->method('purgeTags');

        $result = $this->plugin->afterClean($this->subjectMock, true, Zend_Cache::CLEANING_MODE_ALL);

        $this->assertTrue($result);
    }

    public function testReturnValuePassedThrough(): void
    {
        $this->clientMock->method('isEnabled')->willReturn(true);

        $result = $this->plugin->afterClean($this->subjectMock, true, Zend_Cache::CLEANING_MODE_ALL);

        $this->assertTrue($result);
    }
}
