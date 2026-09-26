<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\ViewModel;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\TridentClient;
use Qoliber\TridentCache\ViewModel\Denoisers;

/**
 * The pin forms default to the store's host as Trident sees it — a request's
 * Host header, with the port unless it is the scheme's default — because
 * Trident matches a pin by exactly that string.
 */
class DenoisersTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function baseUrls(): array
    {
        return [
            'port kept' => ['http://localhost:8380/', 'localhost:8380'],
            'default http port dropped' => ['http://shop.example:80/', 'shop.example'],
            'default https port dropped' => ['https://shop.example/', 'shop.example'],
            'non-default https port kept' => ['https://shop.example:8443/', 'shop.example:8443'],
        ];
    }

    #[DataProvider('baseUrls')]
    public function testThePinFormsDefaultToTheHostTridentSees(string $baseUrl, string $host): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn($baseUrl);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getDefaultStoreView')->willReturn($store);

        $viewModel = new Denoisers(
            $this->createMock(TridentClient::class),
            $this->createMock(Config::class),
            $stores
        );

        $this->assertSame($host, $viewModel->getStoreHost());
    }
}
