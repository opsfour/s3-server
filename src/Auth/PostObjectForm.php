<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Storage\FileReadableStream;

/**
 * Parsed POST Object form data with the uploaded file spooled to disk.
 */
final class PostObjectForm
{
    private bool $cleaned = false;

    /** @var list<EncryptedTempReadableStream> */
    private array $openStreams = [];

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
        private readonly string $spoolKey,
        public readonly ?Credential $credential,
        public readonly ?array $policy,
        private readonly ?\Closure $onCleanup = null,
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
        $stream = new EncryptedTempReadableStream(
            new FileReadableStream(\Amp\File\openFile($this->filePath, 'r')),
            $this->spoolKey,
            $this->fileSize,
        );
        $this->openStreams[] = $stream;

        return $stream;
    }

    public function cleanup(): void
    {
        if ($this->cleaned) {
            return;
        }

        $this->cleaned = true;
        try {
            foreach ($this->openStreams as $stream) {
                try {
                    $stream->close();
                } catch (\Throwable) {
                    // Cleanup must still remove the spool and release its
                    // reservation after a file-worker close failure.
                }
            }
            $this->openStreams = [];
            \Amp\File\deleteFile($this->filePath);
        } catch (\Throwable) {
        } finally {
            ($this->onCleanup)?->__invoke();
        }
    }
}
