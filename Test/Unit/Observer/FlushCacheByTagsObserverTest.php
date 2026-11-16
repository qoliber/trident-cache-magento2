<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\Observer;

use Magento\Framework\App\Cache\Tag\Resolver;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\PurgeAfterCommit;
use Qoliber\TridentCache\Model\PurgeStrategy;
use Qoliber\TridentCache\Model\TagBudget;
use Qoliber\TridentCache\Observer\FlushCacheByTagsObserver;

/**
 * Review I10: a purge by tag also names the overflow tags, so a page that
 * dropped one of the purged tags to stay within Trident's limit is purged.
 */
class FlushCacheByTagsObserverTest extends TestCase
{
    /**
     * @param list<string> $resolved What the tag resolver returns for the saved entity.
     * @return list<list<string>> The tag lists handed to PurgeAfterCommit::purgeTags().
     */
    private function purgedFor(array $resolved, bool $enabled = true): array
    {
        $purged = [];
        $purge = $this->createMock(PurgeAfterCommit::class);
        $purge->method('purgeTags')->willReturnCallback(function (array $tags) use (&$purged): void {
            $purged[] = $tags;
        });
        $config = $this->createMock(Config::class);
        $config->method('isTridentEnabled')->willReturn($enabled);
        $resolver = $this->createMock(Resolver::class);
        $resolver->method('getTags')->willReturn($resolved);

        $observer = new Observer();
        $observer->setData('event', new Event(['object' => new DataObject()]));
        (new FlushCacheByTagsObserver($purge, $config, $resolver, new PurgeStrategy()))->execute($observer);

        return $purged;
    }

    public function testAProductSaveAlsoPurgesTheProductOverflowTag(): void
    {
        $this->assertSame(
            [['cat_p_42', TagBudget::OVERFLOW_PRODUCTS]],
            $this->purgedFor(['cat_p_42', 'cat_p_42'])
        );
    }

    public function testOtherTagsAlsoPurgeTheGeneralOverflowTag(): void
    {
        $this->assertSame(
            [['cat_p_42', 'cat_c_p_5', 'cms_b_7', TagBudget::OVERFLOW_PRODUCTS, TagBudget::OVERFLOW]],
            $this->purgedFor(['cat_p_42', 'cat_c_p_5', 'cms_b_7'])
        );
    }

    public function testTagsAreStillPurgedExactlyAsResolved(): void
    {
        // Never lower-cased: Trident matches tags case-sensitively.
        $this->assertSame([['CAT_P_42', TagBudget::OVERFLOW]], $this->purgedFor(['CAT_P_42']));
    }

    public function testNothingIsPurgedWithoutTagsOrWhenTridentIsOff(): void
    {
        $this->assertSame([], $this->purgedFor([]));
        $this->assertSame([], $this->purgedFor(['cat_p_42'], false));
    }
}
