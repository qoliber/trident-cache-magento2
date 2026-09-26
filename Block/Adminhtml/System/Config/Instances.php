<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Qoliber\TridentCache\Model\Config;

/**
 * X03: which Trident instances purges go to — read-only. With `instances` in
 * app/etc/env.php those are listed (and take precedence over the API URL
 * above); without them it is the API URL above.
 */
class Instances extends Field
{
    /**
     * @param Context $context
     * @param Config $config
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $rows = '';
        foreach ($this->config->getInstances() as $instance) {
            $rows .= sprintf(
                '<li><strong>%s</strong> — %s%s</li>',
                $this->escapeHtml($instance->name),
                $this->escapeHtml($instance->apiUrl),
                $instance->apiToken === '' ? ' <em>(no API token)</em>' : ''
            );
        }
        foreach ($this->config->getInstanceErrors() as $error) {
            $rows .= '<li style="color:#e22626">' . $this->escapeHtml(__('Skipped: %1', $error)) . '</li>';
        }
        $source = $this->config->getInstanceErrors() !== [] || count($this->config->getInstances()) > 1
            || ($this->config->getInstances()[0]->name ?? Config::DEFAULT_INSTANCE) !== Config::DEFAULT_INSTANCE
            ? __('From app/etc/env.php (system/default/system/full_page_cache/trident/instances) — takes precedence over the API URL above. An instance without its own api_token uses the API Token above.')
            : __('The API URL above. To purge several Trident servers, list them in app/etc/env.php under system/default/system/full_page_cache/trident/instances, then run bin/magento app:config:import.');

        return '<ul style="margin:0 0 .5em 1.2em;list-style:disc">' . $rows . '</ul>'
            . '<p class="note"><span>' . $this->escapeHtml($source) . '</span></p>';
    }

    /**
     * Nothing to save or inherit: this row only reports.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _renderInheritCheckbox(AbstractElement $element): string
    {
        return '';
    }
}
