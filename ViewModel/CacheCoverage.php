<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\TridentClient;

class CacheCoverage implements ArgumentInterface
{
    /** @var array<string, mixed>|null */
    private ?array $result = null;

    private bool $resolved = false;

    public function __construct(
        private readonly TridentClient $tridentClient,
        private readonly Config $config,
        private readonly RequestInterface $request,
        private readonly FormKey $formKey
    ) {
    }

    public function isTridentConfigured(): bool
    {
        return $this->config->isTridentEnabled();
    }

    public function getApiUrl(): string
    {
        // X03: the instance this screen shows.
        return $this->tridentClient->target()?->apiUrl ?? $this->config->getApiUrl();
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    public function getSubmittedUrls(): string
    {
        return (string) $this->request->getParam('urls', '');
    }

    public function getSubmittedHost(): string
    {
        return (string) $this->request->getParam('host', '');
    }

    public function getSubmittedScheme(): string
    {
        $scheme = (string) $this->request->getParam('scheme', 'https');

        return in_array($scheme, ['http', 'https'], true) ? $scheme : 'https';
    }

    /**
     * Run the coverage check when the form was submitted; null otherwise.
     *
     * @return array<string, mixed>|null
     */
    public function getResult(): ?array
    {
        if ($this->resolved) {
            return $this->result;
        }

        $this->resolved = true;

        $raw = trim($this->getSubmittedUrls());
        if ($raw === '') {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $urls = array_values(array_filter(array_map('trim', $lines), static fn (string $u): bool => $u !== ''));
        if ($urls === []) {
            return null;
        }

        $host = $this->getSubmittedHost();
        $this->result = $this->tridentClient->cacheCoverage(
            $urls,
            $host !== '' ? $host : null,
            $this->getSubmittedScheme()
        );

        return $this->result;
    }

    public function formatNumber(int|float $number): string
    {
        return number_format($number, 0, '.', ',');
    }
}
