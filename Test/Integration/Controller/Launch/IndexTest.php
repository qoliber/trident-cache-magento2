<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration\Controller\Launch;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Controller\Adminhtml\Launch\Index;

class IndexTest extends TestCase
{
    private Index $controller;
    private PageFactory&MockObject $pageFactoryMock;

    protected function setUp(): void
    {
        $contextMock = $this->createMock(Context::class);
        $this->pageFactoryMock = $this->createMock(PageFactory::class);

        $this->controller = new Index(
            $contextMock,
            $this->pageFactoryMock
        );
    }

    public function testAdminResourceMatchesLaunchAclResource(): void
    {
        $this->assertEquals('Qoliber_TridentCache::launch', Index::ADMIN_RESOURCE);
    }

    public function testExecuteReturnsPageWithActiveMenuAndTitle(): void
    {
        $titleMock = $this->createMock(Title::class);
        $titleMock->expects($this->once())
            ->method('prepend')
            ->with('Trident Launch Mode');

        $pageConfigMock = $this->createMock(PageConfig::class);
        $pageConfigMock->method('getTitle')->willReturn($titleMock);

        $pageMock = $this->createMock(Page::class);
        $pageMock->expects($this->once())
            ->method('setActiveMenu')
            ->with('Qoliber_TridentCache::launch');
        $pageMock->method('getConfig')->willReturn($pageConfigMock);

        $this->pageFactoryMock->method('create')->willReturn($pageMock);

        $this->assertSame($pageMock, $this->controller->execute());
    }
}
