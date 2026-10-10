<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\StorageTierReferences;
use PHPUnit\Framework\TestCase;

final class StorageTierReferencesTest extends TestCase
{
    public function test_numeric_string_names_remain_strings_and_are_deduplicated_exactly(): void
    {
        $tiers = StorageTierReferences::collect(
            [
                ['tier' => '123'],
                ['tier' => '0'],
                ['tier' => '-1'],
                ['tier' => '01'],
                ['tier' => '123'],
            ],
            [[
                'transitions_json' => '[{"storageClass":"0"},{"storageClass":"HOT"}]',
                'noncurrent_transitions_json' => null,
            ]],
        );

        self::assertSame(['-1', '0', '01', '123', 'HOT'], $tiers);
    }
}
