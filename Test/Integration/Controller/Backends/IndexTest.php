<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration\Controller\Backends;

use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Controller\Adminhtml\Backends\Index;

class IndexTest extends TestCase
{
    public function testAdminResourceMatchesBackendsAcl(): void
    {
        $this->assertSame(
            'Qoliber_TridentCache::backends',
            Index::ADMIN_RESOURCE
        );
    }

    public function testExecuteRendersPageWithActiveMenuAndTitle(): void
    {
        $titleMock = $this->createMock(Title::class);
        $titleMock->expects($this->once())->method('prepend');

        $configMock = $this->createMock(PageConfig::class);
        $configMock->method('getTitle')->willReturn($titleMock);

        $pageMock = $this->createMock(Page::class);
        $pageMock->expects($this->once())
            ->method('setActiveMenu')
            ->with('Qoliber_TridentCache::backends');
        $pageMock->method('getConfig')->willReturn($configMock);

        $pageFactoryMock = $this->createMock(PageFactory::class);
        $pageFactoryMock->method('create')->willReturn($pageMock);

        // The backend Context has no getScopeConfig(); the controller needs
        // nothing from it beyond construction.
        $contextMock = $this->createMock(Context::class);

        $controller = new Index($contextMock, $pageFactoryMock);

        $this->assertInstanceOf(Page::class, $controller->execute());
    }
}
