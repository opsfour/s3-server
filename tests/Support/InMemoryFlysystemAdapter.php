<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;

final class InMemoryFlysystemAdapter implements FilesystemAdapter
{
    /** @var array<string, string> */
    private array $files = [];

    /** @var array<string, true> */
    private array $directories = [];

    public int $writeStreamCalls = 0;

    public int $writeCalls = 0;

    public ?string $failNextWriteStreamReason = null;

    public function fileExists(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    public function directoryExists(string $path): bool
    {
        $path = trim($path, '/');
        if ($path === '') {
            return true;
        }

        if (isset($this->directories[$path])) {
            return true;
        }

        $prefix = $path.'/';
        foreach ($this->files as $file => $_) {
            if (str_starts_with($file, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->writeCalls++;
        $this->files[$path] = $contents;
        $this->rememberParentDirectories($path);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->writeStreamCalls++;

        if ($this->failNextWriteStreamReason !== null) {
            $reason = $this->failNextWriteStreamReason;
            $this->failNextWriteStreamReason = null;
            throw UnableToWriteFile::atLocation($path, $reason);
        }

        $data = stream_get_contents($contents);
        if ($data === false) {
            throw UnableToWriteFile::atLocation($path, 'Could not read supplied stream.');
        }

        $this->files[$path] = $data;
        $this->rememberParentDirectories($path);
    }

    public function read(string $path): string
    {
        return $this->files[$path] ?? throw UnableToReadFile::fromLocation($path);
    }

    public function readStream(string $path)
    {
        if (! array_key_exists($path, $this->files)) {
            throw UnableToReadFile::fromLocation($path);
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw UnableToReadFile::fromLocation($path, 'Could not open temp stream.');
        }

        fwrite($stream, $this->files[$path]);
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        if (! array_key_exists($path, $this->files)) {
            throw UnableToDeleteFile::atLocation($path);
        }

        unset($this->files[$path]);
    }

    public function deleteDirectory(string $path): void
    {
        $path = trim($path, '/');
        if ($path === '') {
            $this->files = [];
            $this->directories = [];
            return;
        }

        $prefix = $path.'/';
        $deleted = false;

        foreach (array_keys($this->files) as $file) {
            if (str_starts_with($file, $prefix)) {
                unset($this->files[$file]);
                $deleted = true;
            }
        }

        foreach (array_keys($this->directories) as $directory) {
            if ($directory === $path || str_starts_with($directory, $prefix)) {
                unset($this->directories[$directory]);
                $deleted = true;
            }
        }

        if (! $deleted) {
            throw UnableToDeleteDirectory::atLocation($path);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        $path = trim($path, '/');
        if ($path === '') {
            throw UnableToCreateDirectory::atLocation($path, 'Empty directory path.');
        }

        $this->directories[$path] = true;
        $this->rememberParentDirectories($path.'/placeholder');
    }

    public function setVisibility(string $path, string $visibility): void
    {
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, 'private');
    }

    public function mimeType(string $path): FileAttributes
    {
        if (! array_key_exists($path, $this->files)) {
            throw UnableToRetrieveMetadata::mimeType($path);
        }

        return new FileAttributes($path, strlen($this->files[$path]), null, null, 'application/octet-stream');
    }

    public function lastModified(string $path): FileAttributes
    {
        if (! array_key_exists($path, $this->files)) {
            throw UnableToRetrieveMetadata::lastModified($path);
        }

        return new FileAttributes($path, strlen($this->files[$path]), null, time());
    }

    public function fileSize(string $path): FileAttributes
    {
        if (! array_key_exists($path, $this->files)) {
            throw UnableToRetrieveMetadata::fileSize($path);
        }

        return new FileAttributes($path, strlen($this->files[$path]));
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $path = trim($path, '/');
        $prefix = $path === '' ? '' : $path.'/';

        foreach ($this->directories as $directory => $_) {
            if ($directory !== $path && str_starts_with($directory, $prefix)) {
                yield new DirectoryAttributes($directory);
            }
        }

        foreach ($this->files as $file => $contents) {
            if ($path === '' || str_starts_with($file, $prefix)) {
                yield new FileAttributes($file, strlen($contents));
            }
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        if (! array_key_exists($source, $this->files)) {
            throw UnableToMoveFile::fromLocationTo($source, $destination);
        }

        $this->files[$destination] = $this->files[$source];
        unset($this->files[$source]);
        $this->rememberParentDirectories($destination);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        if (! array_key_exists($source, $this->files)) {
            throw UnableToCopyFile::fromLocationTo($source, $destination);
        }

        $this->files[$destination] = $this->files[$source];
        $this->rememberParentDirectories($destination);
    }

    public function contents(string $path): string
    {
        return $this->files[$path] ?? throw new \RuntimeException("No file at {$path}.");
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_keys($this->files);
    }

    private function rememberParentDirectories(string $path): void
    {
        $parts = explode('/', trim($path, '/'));
        array_pop($parts);

        $current = '';
        foreach ($parts as $part) {
            $current = $current === '' ? $part : $current.'/'.$part;
            $this->directories[$current] = true;
        }
    }
}
