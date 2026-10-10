<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** @internal Candidate-only result; the caller still owns the SQL commit. */
final readonly class LocalEncryptedMultipartCompletionResult
{
    /** @param array<string, string> $encryptionMetadata */
    public function __construct(
        public StorageWriteResult $write,
        public array $encryptionMetadata,
    ) {}
}
