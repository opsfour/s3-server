<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\ObjectWriteIntent;

/** Safe result of one durably armed AWS write attempt. */
final readonly class AwsS3WriteAttemptOutcome
{
    private function __construct(
        public string $attemptId,
        public string $dispatchId,
        public string $state,
        public ?string $etag,
    ) {}

    public static function fromProvider(
        string $attemptId,
        string $dispatchId,
        AwsS3CreateOnlyWriteOutcome $provider,
    ): self {
        if (preg_match('/^[a-f0-9]{32}$/D', $attemptId) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $dispatchId) !== 1
            || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH
            || ! in_array($provider->state, [
                AwsS3CreateOnlyWriteOutcome::CONFIRMED_SUCCESS,
                AwsS3CreateOnlyWriteOutcome::PRECONDITION_CONFLICT,
                AwsS3CreateOnlyWriteOutcome::CONFIRMED_REJECTION,
                AwsS3CreateOnlyWriteOutcome::AMBIGUOUS,
            ], true)
            || (($provider->state === AwsS3CreateOnlyWriteOutcome::CONFIRMED_SUCCESS) !== ($provider->etag !== null))) {
            throw new \InvalidArgumentException('Invalid AWS write-attempt outcome.');
        }

        return new self($attemptId, $dispatchId, $provider->state, $provider->etag);
    }
}
