<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Lifecycle;

use OpsFour\S3Server\Lifecycle\LifecycleRuleValidator;
use PHPUnit\Framework\TestCase;

final class LifecycleRuleValidatorTest extends TestCase
{
    public function test_stored_rule_id_limit_counts_unicode_characters(): void
    {
        LifecycleRuleValidator::assertValid($this->rule(str_repeat('é', 255)));

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Stored lifecycle rule ID is invalid.');

        LifecycleRuleValidator::assertValid($this->rule(str_repeat('é', 256)));
    }

    public function test_stored_rule_id_must_be_valid_utf8(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Stored lifecycle rule ID is invalid.');

        LifecycleRuleValidator::assertValid($this->rule("\xC3\x28"));
    }

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

    /** @return array<string, mixed> */
    private function rule(string $id): array
    {
        return [
            'id' => $id,
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ];
    }
}
