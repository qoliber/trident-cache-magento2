<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Controller\Adminhtml\Instance;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Qoliber\TridentCache\Model\Admin\InstanceSelection;
use Qoliber\TridentCache\Model\Config;

/**
 * X03: choose the instance the Trident screens show and act on. POST only
 * (form key checked by Magento); an unknown name is refused, never stored.
 */
class Select extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Qoliber_TridentCache::trident';

    /**
     * @param Context $context
     * @param InstanceSelection $selection
     * @param Config $config
     */
    public function __construct(
        Context $context,
        private readonly InstanceSelection $selection,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $name = (string) $this->getRequest()->getParam('instance', '');
        $known = false;
        foreach ($this->config->getInstances() as $instance) {
            $known = $known || $instance->name === $name;
        }
        if ($known) {
            $this->selection->select($name);
            $this->messageManager->addSuccessMessage(__('Showing Trident instance "%1".', $name));
        } else {
            $this->messageManager->addErrorMessage(__('There is no Trident instance named "%1".', $name));
        }

        return $this->resultRedirectFactory->create()->setRefererOrBaseUrl();
    }
}
