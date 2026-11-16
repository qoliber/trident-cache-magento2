<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Model\TagBudget;

/**
 * Review I10: a response with more tags than Trident's `max_tags_per_entry`
 * is not cached. The module bounds the list and stands an overflow tag in for
 * what it dropped; every tag purge carries the matching overflow tag.
 */
class TagBudgetTest extends TestCase
{
    /**
     * @return list<string> cat_p_<from> .. cat_p_<to>
     */
    private static function products(int $from, int $to): array
    {
        return array_map(static fn (int $i): string => 'cat_p_' . $i, range($from, $to));
    }

    public function testAListWithinTheLimitIsReturnedAsItIs(): void
    {
        $tags = ['cat_c_5', 'cat_c_p_5', ...self::products(1, 198)];
        $this->assertCount(200, $tags);

        $this->assertSame($tags, TagBudget::fit($tags, 200));
    }

    public function testTheHeaderIsUntouchedWithinTheLimit(): void
    {
        // Byte for byte, the odd spacing and the repeated tag included: the
        // header is only rewritten when it has to be.
        $value = 'cat_c_5, cat_p_1,cat_p_1,FPC';

        $this->assertSame($value, TagBudget::fitHeader($value, 200));
    }

    public function testOneTagOverTheLimitDropsListedProductsAndAddsTheirOverflowTag(): void
    {
        // 201 tags: the case that was answered PASS, "cache tag count limit".
        $tags = ['cat_c_5', 'cat_c_p_5', 'cms_b_7', ...self::products(1, 198)];
        $this->assertCount(201, $tags);

        $fitted = TagBudget::fit($tags, 200);

        $this->assertLessThanOrEqual(200, count($fitted));
        // 198 slots (two stay reserved for the overflow tags): the three
        // non-product tags, then the first 195 products.
        $this->assertSame(
            ['cat_c_5', 'cat_c_p_5', 'cms_b_7', ...self::products(1, 195), TagBudget::OVERFLOW_PRODUCTS],
            $fitted
        );
        $this->assertNotContains(TagBudget::OVERFLOW, $fitted, 'no tag other than a listed product was dropped');
    }

    public function testThePagesOwnTagsSurviveWhateverTheirPositionInTheHeader(): void
    {
        // The category's own tags come last, behind 500 listed products.
        $tags = [...self::products(1, 500), 'cat_c_5', 'cat_c_p_5', 'cms_b_footer', 'store'];

        $fitted = TagBudget::fit($tags, 200);

        $this->assertCount(199, $fitted);
        foreach (['cat_c_5', 'cat_c_p_5', 'cms_b_footer', 'store'] as $own) {
            $this->assertContains($own, $fitted);
        }
        $this->assertSame(TagBudget::OVERFLOW_PRODUCTS, $fitted[198]);
        $this->assertContains('cat_p_194', $fitted);
        $this->assertNotContains('cat_p_195', $fitted);
    }

    public function testTagsAreKeptExactlyAsMagentoEmittedThem(): void
    {
        // Trident matches tags case-sensitively; nothing is lower-cased or rewritten.
        $tags = ['FPC', 'CAT_P_42', 'cms_b_Hero-Banner', ...self::products(1, 300)];

        $fitted = TagBudget::fit($tags, 50);

        $this->assertSame(['FPC', 'CAT_P_42', 'cms_b_Hero-Banner'], array_slice($fitted, 0, 3));
    }

    public function testDroppingOtherTagsAddsTheGeneralOverflowTag(): void
    {
        $blocks = array_map(static fn (int $i): string => 'cms_b_' . $i, range(1, 300));

        $fitted = TagBudget::fit([...$blocks, 'cat_p_1'], 200);

        $this->assertCount(200, $fitted);
        $this->assertSame(array_slice($blocks, 0, 198), array_slice($fitted, 0, 198));
        // The product tag did not fit either, so both overflow tags are there.
        $this->assertSame([TagBudget::OVERFLOW_PRODUCTS, TagBudget::OVERFLOW], array_slice($fitted, 198));
    }

    public function testTheResultNeverExceedsTheLimit(): void
    {
        $tags = [
            ...array_map(static fn (int $i): string => 'cat_c_' . $i, range(1, 150)),
            ...self::products(1, 1000),
        ];
        foreach ([3, 10, 50, 200, 201, 1000] as $limit) {
            $this->assertLessThanOrEqual($limit, count(TagBudget::fit($tags, $limit)), 'limit ' . $limit);
        }
    }

    public function testALimitBelowThreeBehavesAsThree(): void
    {
        // One tag and the two overflow tags is the smallest useful list.
        $this->assertSame(
            ['cat_c_5', TagBudget::OVERFLOW_PRODUCTS],
            TagBudget::fit(['cat_c_5', ...self::products(1, 10)], 1)
        );
    }

    public function testDuplicatesAndEmptyTagsDoNotCountTowardTheLimit(): void
    {
        $value = implode(',', ['cat_c_5', '', ' cat_c_5 ', ...self::products(1, 199), ...self::products(1, 199)]);

        // 200 distinct tags: within the limit, so the header is left alone.
        $this->assertSame($value, TagBudget::fitHeader($value, 200));
    }

    public function testTheHeaderOverTheLimitIsRewrittenWithCommas(): void
    {
        $value = implode(',', ['cat_c_5', ...self::products(1, 250)]);

        $fitted = explode(',', TagBudget::fitHeader($value, 200));

        $this->assertCount(199, $fitted);
        $this->assertSame('cat_c_5', $fitted[0]);
        $this->assertSame(TagBudget::OVERFLOW_PRODUCTS, $fitted[198]);
    }

    public function testALimitOfZeroSwitchesTheCapOff(): void
    {
        $value = implode(',', self::products(1, 5000));

        $this->assertSame($value, TagBudget::fitHeader($value, 0));
        $this->assertSame($value, TagBudget::fitHeader($value, -1));
    }

    public function testAPurgeOfAProductCarriesTheProductOverflowTag(): void
    {
        $this->assertSame(
            ['cat_p_42', 'cat_p', TagBudget::OVERFLOW_PRODUCTS, TagBudget::OVERFLOW],
            TagBudget::withOverflow(['cat_p_42', 'cat_p'])
        );
        $this->assertSame(['cat_p_42', TagBudget::OVERFLOW_PRODUCTS], TagBudget::withOverflow(['cat_p_42']));
    }

    public function testAPurgeOfAnyOtherTagCarriesTheGeneralOverflowTag(): void
    {
        $this->assertSame(['cms_b_7', TagBudget::OVERFLOW], TagBudget::withOverflow(['cms_b_7']));
        $this->assertSame(['cat_c_5', 'cat_c_p_5', TagBudget::OVERFLOW], TagBudget::withOverflow(['cat_c_5', 'cat_c_p_5']));
        $this->assertSame(['robots_1', TagBudget::OVERFLOW], TagBudget::withOverflow(['robots_1']));
    }

    public function testAddingTheOverflowTagsTwiceAddsNothing(): void
    {
        $once = TagBudget::withOverflow(['cat_p_42', 'cms_b_7']);

        $this->assertSame($once, TagBudget::withOverflow($once));
        $this->assertSame([], TagBudget::withOverflow([]));
    }

    /**
     * The property the scheme rests on: whichever tag a response dropped, a
     * purge of that tag names a tag the response still carries.
     */
    public function testEveryDroppedTagIsReachedThroughATagTheResponseKept(): void
    {
        $tags = [
            'store',
            ...array_map(static fn (int $i): string => 'cat_c_' . $i, range(1, 120)),
            ...array_map(static fn (int $i): string => 'cms_b_' . $i, range(1, 120)),
            ...self::products(1, 400),
        ];
        $fitted = TagBudget::fit($tags, 200);
        $dropped = array_values(array_diff($tags, $fitted));
        $this->assertNotEmpty($dropped);

        foreach ($dropped as $tag) {
            $this->assertNotEmpty(
                array_intersect(TagBudget::withOverflow([$tag]), $fitted),
                'a purge of ' . $tag . ' must reach the page that dropped it'
            );
        }
    }
}
