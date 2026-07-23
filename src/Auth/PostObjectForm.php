<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use Amp\ByteStream\ReadableStream;

/**
 * Parsed POST Object form data with the uploaded file spooled to disk.
 */
final class PostObjectForm
{
    private bool $cleaned = false;

    /**
     * @param array<string, string> $fields
     * @param array<string, mixed>|null $policy
     */
    public function __construct(
        public readonly array $fields,
        public readonly string $key,
        public readonly string $filename,
        public readonly string $filePath,
        public readonly int $fileSize,
        public readonly ?Credential $credential,
        public readonly ?array $policy,
    ) {}

    public function value(string $name): ?string
    {
        $name = strtolower($name);
        foreach ($this->fields as $fieldName => $value) {
            if (strtolower($fieldName) === $name) {
                return $value;
            }
        }

        return null;
    }

    public function openFile(): ReadableStream
    {
        return \Amp\File\openFile($this->filePath, 'r');
    }

    public function cleanup(): void
    {
        if ($this->cleaned) {
            return;
        }

        $this->cleaned = true;
        try {
            \Amp\File\deleteFile($this->filePath);
        } catch (\Throwable) {
        }
    }
}
