<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration\Controller\Reflect;

use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Controller\Adminhtml\Reflect\Index;

class IndexTest extends TestCase
{
    public function testAdminResourceMatchesReflectAcl(): void
    {
        $this->assertSame(
            'Qoliber_TridentCache::reflect',
            Index::ADMIN_RESOURCE
        );
    }

    public function testExecuteRendersResultPageWithReflectMenu(): void
    {
        if (!class_exists(Bootstrap::class)) {
            $this->markTestSkipped('Magento integration test framework is not available.');
        }

        $objectManager = Bootstrap::getObjectManager();

        /** @var PageFactory $pageFactory */
        $pageFactory = $objectManager->get(PageFactory::class);

        /** @var Index $controller */
        $controller = $objectManager->create(
            Index::class,
            ['resultPageFactory' => $pageFactory]
        );

        $result = $controller->execute();

        $this->assertInstanceOf(Page::class, $result);
    }
}
