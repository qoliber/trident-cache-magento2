<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Controller\Adminhtml\Denoisers;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\TridentClient;

class ExportWaf extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Qoliber_TridentCache::denoisers';

    public function __construct(
        Context $context,
        private readonly TridentClient $tridentClient,
        private readonly FileFactory $fileFactory,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface
     */
    public function execute(): ResponseInterface|ResultInterface
    {
        try {
            $export = $this->tridentClient->getDenoiserWafExport();

            if ($export === null) {
                $this->messageManager->addErrorMessage(
                    __('No WAF export is available. The denoisers may be disabled or unreachable.')
                );

                /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
                $resultRedirect = $this->resultRedirectFactory->create();
                return $resultRedirect->setPath('trident/denoisers/index');
            }

            $content = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return $this->fileFactory->create(
                'trident-waf-export.json',
                $content,
                \Magento\Framework\App\Filesystem\DirectoryList::VAR_DIR,
                'application/json'
            );
        } catch (\Exception $e) {
            $this->logger->error('Trident denoiser WAF export error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Error exporting WAF rules: %1', $e->getMessage()));

            /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
            $resultRedirect = $this->resultRedirectFactory->create();
            return $resultRedirect->setPath('trident/denoisers/index');
        }
    }
}
