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

/**
 * Keeps the tags of one response within Trident's per-entry tag limit.
 *
 * Trident refuses to cache a response that carries more tags than
 * `[cache.tags] max_tags_per_entry` (200 by default): it answers
 * `X-Cache: PASS` with the reason "cache tag count limit", on every request.
 * A category or search page that lists many products gets there easily, because
 * `X-Magento-Tags` carries one `cat_p_<id>` per listed product.
 *
 * So the list is bounded here, with the scheme the other Trident integrations
 * use (`Qoliber\Trident\Tags\TagSet` in qoliber/trident-php; that class is not
 * used directly because it lower-cases tags, and Trident matches Magento's
 * tags case-sensitively, exactly as `X-Magento-Tags` carries them):
 *
 *  - tags are kept in two passes: first everything that is NOT a listed
 *    product (the page's own entity, its category, CMS blocks, the broad
 *    tags), then the `cat_p_<id>` tags, each in the order Magento emitted
 *    them. What does not fit is dropped;
 *  - a response that dropped something carries an OVERFLOW tag in its place:
 *    {@see OVERFLOW_PRODUCTS} when a `cat_p_<id>` tag was dropped,
 *    {@see OVERFLOW} when any other tag was;
 *  - every purge by tag adds the overflow tag of each tag it carries
 *    ({@see withOverflow()}). A page that lost `cat_p_42` is therefore purged
 *    by a change to ANY product, instead of by none.
 *
 * The cost is over-purging of the pages that overflowed, which until now were
 * not cached at all. The two overflow tags keep that cost where it belongs: a
 * CMS block save does not refresh a listing that only dropped product tags.
 *
 * Both halves must use this class. A purge sent without {@see withOverflow()}
 * misses every page that dropped one of its tags.
 */
class TagBudget
{
    /** Trident's default `[cache.tags] max_tags_per_entry`. */
    public const DEFAULT_MAX_TAGS = 200;

    /** Carried by a response that dropped a tag other than `cat_p_<id>`. */
    public const OVERFLOW = 'trident_overflow';

    /** Carried by a response that dropped a `cat_p_<id>` tag. */
    public const OVERFLOW_PRODUCTS = 'trident_overflow_cat_p';

    /** One tag and both overflow tags: a smaller limit behaves as this one. */
    private const MIN_MAX_TAGS = 3;

    /** How many of the limit's slots stay reserved for the overflow tags. */
    private const RESERVED = 2;

    private const LISTED_PRODUCT = '/^cat_p_\d+$/';

    /**
     * The `X-Magento-Tags` value, bounded to $maxTags tags.
     *
     * Returned byte for byte as given when it is within the limit, or when
     * $maxTags is 0 or less (the cap is switched off).
     *
     * @param string $value Comma-separated tags.
     * @param int $maxTags
     * @return string
     */
    public static function fitHeader(string $value, int $maxTags = self::DEFAULT_MAX_TAGS): string
    {
        if ($maxTags <= 0) {
            return $value;
        }
        $tags = self::distinct(explode(',', $value));
        if (count($tags) <= max(self::MIN_MAX_TAGS, $maxTags)) {
            return $value;
        }

        return implode(',', self::fit($tags, $maxTags));
    }

    /**
     * At most max(3, $maxTags) tags: the ones that fit, then the overflow
     * tags standing in for the ones that did not.
     *
     * @param array<int|string, string> $tags
     * @param int $maxTags
     * @return list<string>
     */
    public static function fit(array $tags, int $maxTags = self::DEFAULT_MAX_TAGS): array
    {
        $tags = self::distinct($tags);
        $maxTags = max(self::MIN_MAX_TAGS, $maxTags);
        if (count($tags) <= $maxTags) {
            return $tags;
        }

        $others = [];
        $products = [];
        $needed = [];
        foreach ($tags as $tag) {
            if ($tag === self::OVERFLOW || $tag === self::OVERFLOW_PRODUCTS) {
                // Already an overflow tag: it keeps its reserved slot.
                $needed[$tag] = true;
            } elseif (preg_match(self::LISTED_PRODUCT, $tag) === 1) {
                $products[] = $tag;
            } else {
                $others[] = $tag;
            }
        }

        $slots = $maxTags - self::RESERVED;
        $kept = [];
        foreach ([...$others, ...$products] as $tag) {
            if (count($kept) < $slots) {
                $kept[] = $tag;
                continue;
            }
            $needed[(string) self::overflowTagFor($tag)] = true;
        }
        foreach ([self::OVERFLOW_PRODUCTS, self::OVERFLOW] as $overflow) {
            if (isset($needed[$overflow])) {
                $kept[] = $overflow;
            }
        }

        return $kept;
    }

    /**
     * The tags of a purge, plus the overflow tag of every tag among them.
     *
     * The given tags come back unchanged and in order; the overflow tags are
     * appended. Applying it twice adds nothing more.
     *
     * @param array<int|string, string> $tags
     * @return list<string>
     */
    public static function withOverflow(array $tags): array
    {
        $out = [];
        $overflow = [];
        foreach ($tags as $tag) {
            $tag = (string) $tag;
            $out[] = $tag;
            $for = self::overflowTagFor($tag);
            if ($for !== null) {
                $overflow[$for] = true;
            }
        }
        foreach (array_keys($overflow) as $tag) {
            $tag = (string) $tag;
            if (!in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }

        return $out;
    }

    /**
     * The overflow tag a response carries when it drops $tag, which is also
     * the one a purge of $tag has to add.
     *
     * @param string $tag
     * @return string|null Null for an empty tag and for the overflow tags
     *         themselves, which are never dropped.
     */
    public static function overflowTagFor(string $tag): ?string
    {
        $tag = trim($tag);
        if ($tag === '' || $tag === self::OVERFLOW || $tag === self::OVERFLOW_PRODUCTS) {
            return null;
        }

        return preg_match(self::LISTED_PRODUCT, $tag) === 1 ? self::OVERFLOW_PRODUCTS : self::OVERFLOW;
    }

    /**
     * Trimmed, without empty ones, each once, in first-seen order.
     *
     * @param array<int|string, string> $tags
     * @return list<string>
     */
    private static function distinct(array $tags): array
    {
        $seen = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '') {
                $seen[$tag] = true;
            }
        }

        return array_map('strval', array_keys($seen));
    }
}
