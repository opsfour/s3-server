<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Lifecycle;

use OpsFour\S3Server\Lifecycle\LifecycleRuleValidator;
use PHPUnit\Framework\TestCase;

final class LifecycleRuleValidatorTest extends TestCase
{
    public function test_stored_false_delete_marker_action_cannot_be_combined_with_tag_filter(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('tag filter is incompatible');

        LifecycleRuleValidator::assertValid([
            'id' => 'invalid-tagged-marker-rule',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => ['tag' => ['key' => 'env', 'value' => 'prod']],
            'transitions' => null,
            'expiration' => ['expiredObjectDeleteMarker' => false],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]);
    }
}
