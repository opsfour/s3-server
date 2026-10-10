<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** @internal A completed result still requires transactional SQL consume. */
final readonly class LocalMultipartPartWriteOutcome
{
    public const string BEFORE_DISPATCH = 'before_dispatch';
    public const string COMPLETED = 'completed';
    public const string UNCERTAIN = 'uncertain';

    public function __construct(
        public string $path,
        public string $state,
        public ?string $dispatchId,
        public ?StorageWriteResult $result,
        public ?\Throwable $error,
    ) {
        if ($result !== null && ($state !== self::COMPLETED || $dispatchId === null || $result->path !== $path)) {
            throw new \InvalidArgumentException('Multipart part result must match the armed planned path.');
        }
    }
}
