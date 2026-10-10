<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/**
 * Computes the first valid UTF-8 string after every key in a prefix range.
 *
 * PostgreSQL text parameters must themselves be valid UTF-8, so a byte-wise
 * increment is not portable across all stores. UTF-8 preserves Unicode scalar
 * order, which lets every store use this bound with its byte collation.
 */
final class ListingPrefixRange
{
    public static function exclusiveUpperBound(string $prefix): ?string
    {
        $characters = preg_split('//u', $prefix, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            throw new \InvalidArgumentException('Listing prefixes must be valid UTF-8.');
        }

        for ($index = count($characters) - 1; $index >= 0; $index--) {
            $codePoint = mb_ord($characters[$index], 'UTF-8');
            if ($codePoint === false) {
                throw new \InvalidArgumentException('Listing prefixes must be valid UTF-8.');
            }

            if ($codePoint === 0x10FFFF) {
                continue;
            }

            // Surrogates are not Unicode scalar values. Jump over their range.
            $nextCodePoint = $codePoint === 0xD7FF ? 0xE000 : $codePoint + 1;

            return implode('', array_slice($characters, 0, $index)) . mb_chr($nextCodePoint, 'UTF-8');
        }

        return null;
    }
}
