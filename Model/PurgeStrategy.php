<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;

/**
 * Purge strategy for optimizing cache invalidation tags.
 *
 * When enabled, filters out category listing tags (cat_c_p_{id}) for product saves
 * where only detail-level attributes changed (price, description, etc.) — these don't
 * affect category listing pages so purging them is unnecessary.
 *
 * TODO: Enable via admin config when performance testing confirms the optimization
 *       is safe for all Magento configurations (anchored categories, flat catalog, etc.)
 */
class PurgeStrategy
{
    /** @var bool */
    private const ENABLED = false; // TODO: wire to admin config when ready

    /** @var string[] Attributes that affect category listing pages */
    private const LISTING_ATTRIBUTES = [
        'name',
        'status',
        'visibility',
        'price',
        'special_price',
        'special_from_date',
        'special_to_date',
        'image',
        'small_image',
        'thumbnail',
    ];

    /**
     * Filter tags based on what actually changed.
     *
     * For product saves where only detail attributes changed, removes category
     * tags (cat_c_p_{id}) since listing pages don't need to be invalidated.
     *
     * @param object $entity The saved entity
     * @param array<string> $tags Tags from getIdentities()
     * @return array<string>
     */
    public function filterTags(object $entity, array $tags): array
    {
        if (!self::ENABLED) {
            return $tags;
        }

        if (!$entity instanceof Product) {
            return $tags;
        }

        // If listing-relevant attributes changed, keep all tags (including category tags)
        if ($this->hasListingAttributeChanges($entity)) {
            return $tags;
        }

        // Only detail attributes changed — filter out category listing tags
        $categoryPrefix = Category::CACHE_TAG . '_p_'; // cat_c_p_

        return array_values(array_filter($tags, function (string $tag) use ($categoryPrefix): bool {
            return !str_starts_with($tag, $categoryPrefix);
        }));
    }

    private function hasListingAttributeChanges(Product $product): bool
    {
        foreach (self::LISTING_ATTRIBUTES as $attribute) {
            if ($product->dataHasChangedFor($attribute)) {
                return true;
            }
        }

        return false;
    }
}
