<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\ObjectWriteIntent;

/**
 * Result of one physical publication call. A before-dispatch result does not
 * prove that another call with the same attempt is not still running; it must
 * never authorize SQL intent retirement by itself.
 */
final readonly class LocalObjectWriteOutcome
{
    public const string BEFORE_DISPATCH = 'before_dispatch';

    public const string COMPLETED = 'completed';

    public const string UNCERTAIN = 'uncertain';

    private function __construct(
        public string $attemptId,
        public string $storageTier,
        public string $storagePath,
        public string $state,
        public ?StorageWriteResult $result,
        public ?\Throwable $error,
    ) {}

    public static function beforeDispatch(ObjectWriteIntent $intent, \Throwable $error): self
    {
        return new self($intent->attemptId, $intent->storageTier, $intent->storagePath, self::BEFORE_DISPATCH, null, $error);
    }

    public static function completed(ObjectWriteIntent $intent, ?StorageWriteResult $result, ?\Throwable $error = null): self
    {
        if (($result === null) === ($error === null)) {
            throw new \InvalidArgumentException('Completed write needs exactly one result or error.');
        }
        if ($result !== null && $result->path !== $intent->storagePath) {
            throw new \InvalidArgumentException('Completed write result must match its intent path.');
        }

        return new self($intent->attemptId, $intent->storageTier, $intent->storagePath, self::COMPLETED, $result, $error);
    }

    public static function uncertain(ObjectWriteIntent $intent, \Throwable $error): self
    {
        return new self($intent->attemptId, $intent->storageTier, $intent->storagePath, self::UNCERTAIN, null, $error);
    }
}
