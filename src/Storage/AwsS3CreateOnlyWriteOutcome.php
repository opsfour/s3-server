<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Closed provider evidence from one create-only AWS publication. */
final readonly class AwsS3CreateOnlyWriteOutcome
{
    public const string CONFIRMED_SUCCESS = 'confirmed_success';
    public const string PRECONDITION_CONFLICT = 'precondition_conflict';
    public const string CONFIRMED_REJECTION = 'confirmed_rejection';
    public const string AMBIGUOUS = 'ambiguous';

    private function __construct(public string $state, public ?string $etag = null) {}

    public static function confirmedSuccess(string $etag): self
    {
        if (preg_match('/^"[^"\s,]+"$/D', $etag) !== 1 || $etag === '"*"') {
            throw new \InvalidArgumentException('Confirmed AWS publication requires an exact quoted ETag.');
        }

        return new self(self::CONFIRMED_SUCCESS, $etag);
    }

    public static function preconditionConflict(): self
    {
        return new self(self::PRECONDITION_CONFLICT);
    }

    public static function confirmedRejection(): self
    {
        return new self(self::CONFIRMED_REJECTION);
    }

    public static function ambiguous(): self
    {
        return new self(self::AMBIGUOUS);
    }
}
