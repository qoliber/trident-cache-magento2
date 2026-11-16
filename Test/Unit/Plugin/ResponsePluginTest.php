<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\Plugin;

use Magento\Framework\App\Response\Http as HttpResponse;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\TagBudget;
use Qoliber\TridentCache\Plugin\ResponsePlugin;

/**
 * Review I10: X-Magento-Tags is passed through untouched, except when it
 * carries more tags than Trident stores per entry.
 */
class ResponsePluginTest extends TestCase
{
    /** @var array<string, string> Headers the plugin set on the response. */
    private array $set = [];

    private function config(int $maxTags, bool $enabled = true): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('isTridentEnabled')->willReturn($enabled);
        $config->method('getTtl')->willReturn(86400);
        $config->method('getGracePeriod')->willReturn(0);
        $config->method('isEsiEnabled')->willReturn(false);
        $config->method('getMaxTagsPerResponse')->willReturn($maxTags);

        return $config;
    }

    /**
     * @param array<string, string> $headers
     */
    private function response(array $headers): HttpResponse
    {
        $this->set = [];
        $response = $this->createMock(HttpResponse::class);
        $response->method('getHeader')->willReturnCallback(
            static fn ($name) => isset($headers[$name]) ? new class ($headers[$name]) {
                public function __construct(private readonly string $value)
                {
                }

                public function getFieldValue(): string
                {
                    return $this->value;
                }
            } : false
        );
        $response->method('setHeader')->willReturnCallback(
            function ($name, $value) use ($response) {
                $this->set[(string) $name] = (string) $value;
                return $response;
            }
        );

        return $response;
    }

    /**
     * @return list<string>
     */
    private static function listing(int $products): array
    {
        return ['cat_c_5', 'cat_c_p_5', ...array_map(static fn (int $i): string => 'cat_p_' . $i, range(1, $products))];
    }

    public function testAResponseOverTheLimitGetsABoundedTagHeader(): void
    {
        // 201 tags: what Trident answered PASS, "cache tag count limit".
        $tags = self::listing(199);
        $response = $this->response([
            'Cache-Control' => 'public, max-age=86400',
            'X-Magento-Tags' => implode(',', $tags),
        ]);

        (new ResponsePlugin($this->config(200)))->beforeSendResponse($response);

        $this->assertArrayHasKey('X-Magento-Tags', $this->set);
        $sent = explode(',', $this->set['X-Magento-Tags']);
        $this->assertLessThanOrEqual(200, count($sent));
        $this->assertSame(['cat_c_5', 'cat_c_p_5'], array_slice($sent, 0, 2), 'the page keeps its own tags');
        $this->assertSame(TagBudget::OVERFLOW_PRODUCTS, end($sent));
    }

    public function testAResponseWithinTheLimitKeepsItsTagHeaderUntouched(): void
    {
        $response = $this->response([
            'Cache-Control' => 'public, max-age=86400',
            'X-Magento-Tags' => implode(',', self::listing(198)),
        ]);

        (new ResponsePlugin($this->config(200)))->beforeSendResponse($response);

        $this->assertArrayNotHasKey('X-Magento-Tags', $this->set);
        $this->assertArrayHasKey('Cache-Control', $this->set, 'the cache lifetime is still set');
    }

    public function testTheConfiguredLimitIsTheOneApplied(): void
    {
        $response = $this->response([
            'Cache-Control' => 'public, max-age=86400',
            'X-Magento-Tags' => implode(',', self::listing(100)),
        ]);

        (new ResponsePlugin($this->config(50)))->beforeSendResponse($response);

        $this->assertCount(49, explode(',', $this->set['X-Magento-Tags']));
    }

    public function testALimitOfZeroLeavesAnyNumberOfTags(): void
    {
        $response = $this->response([
            'Cache-Control' => 'public, max-age=86400',
            'X-Magento-Tags' => implode(',', self::listing(1000)),
        ]);

        (new ResponsePlugin($this->config(0)))->beforeSendResponse($response);

        $this->assertArrayNotHasKey('X-Magento-Tags', $this->set);
    }

    public function testAPrivateResponseIsNotTouched(): void
    {
        $response = $this->response([
            'Cache-Control' => 'private, no-store',
            'X-Magento-Tags' => implode(',', self::listing(1000)),
        ]);

        (new ResponsePlugin($this->config(200)))->beforeSendResponse($response);

        $this->assertSame([], $this->set);
    }

    public function testNothingIsTouchedWhenTridentIsNotTheCachingApplication(): void
    {
        $response = $this->response([
            'Cache-Control' => 'public, max-age=86400',
            'X-Magento-Tags' => implode(',', self::listing(1000)),
        ]);

        (new ResponsePlugin($this->config(200, false)))->beforeSendResponse($response);

        $this->assertSame([], $this->set);
    }
}
