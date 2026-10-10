<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Blocking physical operations used only by the optional EFS delete worker. */
interface LocalEfsDeleteIo
{
    /** @return list<string>|false */
    public function listDirectory(string $path): array|false;

    public function isDirectory(string $path): bool;

    public function isFile(string $path): bool;

    public function isLink(string $path): bool;

    public function realPath(string $path): string|false;

    public function unlinkFile(string $path): bool;

    public function syncDirectory(string $path): bool;
}
