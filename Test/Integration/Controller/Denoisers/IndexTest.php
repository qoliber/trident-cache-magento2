<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration\Controller\Denoisers;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Controller\Adminhtml\Denoisers\Index;

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

    public function testAdminResourceMatchesDenoisersAcl(): void
    {
        $this->assertSame('Qoliber_TridentCache::denoisers', Index::ADMIN_RESOURCE);
    }

    public function testExecuteRendersDenoisersPageWithMenuAndTitle(): void
    {
        $titleMock = $this->createMock(Title::class);
        $titleMock->expects($this->once())
            ->method('prepend')
            ->with(__('Trident Denoisers'));

        $pageConfigMock = $this->createMock(PageConfig::class);
        $pageConfigMock->method('getTitle')->willReturn($titleMock);

        $this->pageMock->expects($this->once())
            ->method('setActiveMenu')
            ->with('Qoliber_TridentCache::denoisers');
        $this->pageMock->method('getConfig')->willReturn($pageConfigMock);

        $this->pageFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->pageMock);

        $result = $this->controller->execute();

        $this->assertSame($this->pageMock, $result);
    }
}
