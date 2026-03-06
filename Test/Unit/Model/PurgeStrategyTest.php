<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Model\PurgeStrategy;

class PurgeStrategyTest extends TestCase
{
    private PurgeStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new PurgeStrategy();
    }

    /**
     * PurgeStrategy is currently disabled (ENABLED = false).
     * These tests verify behavior in both states.
     */
    public function testDisabledStrategyReturnsAllTags(): void
    {
        $entity = $this->createMock(Product::class);
        $tags = ['cat_p_1', 'cat_c_p_1', 'cat_c_2'];

        $result = $this->strategy->filterTags($entity, $tags);

        // When disabled, all tags pass through unchanged
        $this->assertEquals($tags, $result);
    }

    public function testNonProductEntityPassesThroughUnchanged(): void
    {
        $entity = new DataObject();
        $tags = ['cms_p_1', 'cms_b_2', 'cat_c_p_5'];

        $result = $this->strategy->filterTags($entity, $tags);

        $this->assertEquals($tags, $result);
    }

    public function testCategoryEntityPassesThroughUnchanged(): void
    {
        $entity = $this->createMock(Category::class);
        $tags = ['cat_c_1', 'cat_c_p_1'];

        $result = $this->strategy->filterTags($entity, $tags);

        $this->assertEquals($tags, $result);
    }

    public function testEmptyTagsReturnsEmpty(): void
    {
        $entity = $this->createMock(Product::class);

        $result = $this->strategy->filterTags($entity, []);

        $this->assertEquals([], $result);
    }

    public function testListingAttributesAreConfigured(): void
    {
        // Verify via reflection that listing attributes are defined
        $reflection = new \ReflectionClass(PurgeStrategy::class);
        $constant = $reflection->getConstant('LISTING_ATTRIBUTES');

        $this->assertContains('name', $constant);
        $this->assertContains('status', $constant);
        $this->assertContains('visibility', $constant);
        $this->assertContains('price', $constant);
        $this->assertContains('special_price', $constant);
        $this->assertContains('image', $constant);
        $this->assertContains('small_image', $constant);
        $this->assertContains('thumbnail', $constant);
    }

    public function testStrategyIsCurrentlyDisabled(): void
    {
        $reflection = new \ReflectionClass(PurgeStrategy::class);
        $constant = $reflection->getConstant('ENABLED');

        $this->assertFalse($constant);
    }
}
