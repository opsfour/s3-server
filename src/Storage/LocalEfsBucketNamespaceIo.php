<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Blocking filesystem calls confined to the optional bucket namespace worker. */
interface LocalEfsBucketNamespaceIo
{
    /** @return array<array-key, int>|false */
    public function lstat(string $path): array|false;

    /** @return list<string>|false */
    public function listDirectory(string $path): array|false;

    public function mkdir(string $path): bool;

    public function syncDirectory(string $path): bool;
}
