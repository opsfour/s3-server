<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use OpsFour\S3Server\Auth\PostPolicyConditionMatcher;
use PHPUnit\Framework\TestCase;

final class PostPolicyConditionMatcherTest extends TestCase
{
    public function test_every_content_type_in_a_comma_separated_value_must_match(): void
    {
        self::assertTrue(PostPolicyConditionMatcher::startsWith(
            'Content-Type',
            'image/jpeg,image/png,image/gif',
            'image/',
        ));
        self::assertFalse(PostPolicyConditionMatcher::startsWith(
            'content-type',
            'image/jpeg,text/plain',
            'image/',
        ));
    }

    public function test_commas_in_other_fields_are_matched_as_plain_text(): void
    {
        self::assertTrue(PostPolicyConditionMatcher::startsWith('x-amz-meta-values', 'one,two', 'one,'));
    }
}
