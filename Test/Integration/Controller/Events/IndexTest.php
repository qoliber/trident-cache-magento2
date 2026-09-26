<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration\Controller\Events;

use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Controller\Adminhtml\Events\Index;

class IndexTest extends TestCase
{
    private Index $controller;
    private PageFactory&MockObject $pageFactoryMock;
    private Page&MockObject $pageMock;

    protected function setUp(): void
    {
        $contextMock = $this->createMock(Context::class);
        $this->pageFactoryMock = $this->createMock(PageFactory::class);
        $this->pageMock = $this->createMock(Page::class);

        $this->controller = new Index($contextMock, $this->pageFactoryMock);
    }

    public function testAdminResourceMatchesEventsAcl(): void
    {
        $this->assertSame('Qoliber_TridentCache::events', Index::ADMIN_RESOURCE);
    }

    public function testExecuteSetsActiveMenuAndTitle(): void
    {
        $titleMock = $this->createMock(Title::class);
        $titleMock->expects($this->once())
            ->method('prepend')
            ->with('Trident Live Events');

        $pageConfigMock = $this->createMock(PageConfig::class);
        $pageConfigMock->method('getTitle')->willReturn($titleMock);

        $this->pageMock->expects($this->once())
            ->method('setActiveMenu')
            ->with('Qoliber_TridentCache::events');
        $this->pageMock->method('getConfig')->willReturn($pageConfigMock);

        $this->pageFactoryMock->method('create')->willReturn($this->pageMock);

        $this->assertSame($this->pageMock, $this->controller->execute());
    }
}
