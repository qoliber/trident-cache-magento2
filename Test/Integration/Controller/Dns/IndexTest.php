<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration\Controller\Dns;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Controller\Adminhtml\Dns\Index;

class IndexTest extends TestCase
{
    private PageFactory&MockObject $resultPageFactory;
    private Index $controller;

    protected function setUp(): void
    {
        /** @var Context&MockObject $context */
        $context = $this->createMock(Context::class);
        $context->method('getAuthorization')
            ->willReturn($this->createMock(\Magento\Framework\AuthorizationInterface::class));

        $this->resultPageFactory = $this->createMock(PageFactory::class);

        $this->controller = new Index($context, $this->resultPageFactory);
    }

    public function testAdminResourceMatchesDnsAclResource(): void
    {
        $this->assertEquals('Qoliber_TridentCache::dns', Index::ADMIN_RESOURCE);
    }

    public function testExecuteRendersDnsPageWithActiveMenuAndTitle(): void
    {
        $title = $this->createMock(Title::class);
        $title->expects($this->once())
            ->method('prepend')
            ->with('Trident DNS Discovery');

        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);

        $page = $this->createMock(Page::class);
        $page->expects($this->once())
            ->method('setActiveMenu')
            ->with('Qoliber_TridentCache::dns');
        $page->method('getConfig')->willReturn($pageConfig);

        $this->resultPageFactory->method('create')->willReturn($page);

        $this->assertSame($page, $this->controller->execute());
    }
}
