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

use Magento\Catalog\Model\Product;

class PurgeStrategy
{
    public const LISTING_ATTRIBUTES = [
        'price',
        'special_price',
        'special_from_date',
        'special_to_date',
        'status',
        'visibility',
        'name',
        'small_image',
        'thumbnail',
    ];

    public const DETAIL_ATTRIBUTES = [
        'description',
        'short_description',
        'meta_title',
        'meta_keyword',
        'meta_description',
        'url_key',
        'media_gallery',
    ];

    /**
     * @param string[] $listingAttributes
     * @param string[] $detailAttributes
     */
    public function __construct(
        private readonly array $listingAttributes = self::LISTING_ATTRIBUTES,
        private readonly array $detailAttributes = self::DETAIL_ATTRIBUTES
    ) {
    }

    /**
     * @return string[]
     */
    public function getExcludeTags(Product $product): array
    {
        if ($product->isObjectNew()) {
            return [];
        }

        foreach ($this->listingAttributes as $attribute) {
            if ($product->dataHasChangedFor($attribute)) {
                return [];
            }
        }

        return ['cat_c'];
    }
}
