<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Closure;
use OpsFour\S3Server\Runtime\EfsHostEvidence;

/**
 * Optional local-evidence gate for a deferred EFS read. It never stats EFS;
 * the injected evidence check must use only the local host-evidence bind.
 * A lost heartbeat discards the in-flight chunk and latches the stream closed.
 * After a trip the wrapper makes no explicit close call, since close on hard
 * NFS may block. FileReadableStream and Amp File destructors can still close
 * the underlying handle when references fall away. A blocked read, EOF-close,
 * or destructor close needs a separate worker/host-stop gate; this wrapper
 * cannot impose a hard NFS deadline.
 * FilesystemBackend may use this optional candidate for reads; production
 * admission does not construct or inject its host evidence yet.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class EfsGuardedReadableStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    private bool $closed = false;

    private bool $revoked = false;

    /** @var list<Closure(): void> */
    private array $onCloseCallbacks = [];

    public function __construct(
        private readonly ReadableStream $inner,
        private readonly EfsHostEvidence $evidence,
    ) {}

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->revoked) {
            throw new \RuntimeException('EFS read evidence is unavailable.');
        }
        if ($this->closed) {
            return null;
        }

        $this->assertEvidence();
        try {
            $chunk = $this->inner->read($cancellation);
        } catch (\Throwable $error) {
            // A failed hard-NFS read may coincide with a watcher trip. Do not
            // let its own exception conceal the lost host evidence.
            $this->assertEvidence();
            throw $error;
        }
        // A chunk completed after the watcher tripped must not escape to the
        // caller, even if the underlying worker returned it successfully.
        $this->assertEvidence();
        if ($chunk === null) {
            try {
                $this->close();
            } catch (\Throwable $error) {
                // A close failure may be the first symptom of a host trip.
                $this->assertEvidence();
                throw $error;
            }
            // Closing a hard-NFS handle may wait long enough for the watcher
            // to revoke this host. Do not report a clean EOF after that trip.
            $this->assertEvidence();
        }

        return $chunk;
    }

    public function isReadable(): bool
    {
        if ($this->closed || $this->revoked) {
            return false;
        }
        try {
            $this->assertEvidence();
        } catch (\RuntimeException) {
            return false;
        }

        return $this->inner->isReadable();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        try {
            $this->assertEvidence();
        } catch (\RuntimeException) {
            // Avoid an explicit close after the trip. Inner destructors may
            // still close when their references are released; production must
            // isolate that operation and stop the exact container on loss.
            return;
        }
        $this->closed = true;
        try {
            if (! $this->revoked) {
                $this->inner->close();
            }
        } finally {
            $this->notifyClosed();
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(Closure $onClose): void
    {
        if ($this->closed) {
            $onClose();
            return;
        }
        $this->onCloseCallbacks[] = $onClose;
    }

    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
            // Destructors cannot safely report a storage-close failure.
        }
    }

    private function assertEvidence(): void
    {
        try {
            $this->evidence->assertFresh();
        } catch (\Throwable) {
            $this->revoked = true;
            $this->closed = true;
            try {
                $this->notifyClosed();
            } catch (\Throwable) {
                // A callback cannot turn a failed host check into read success.
            }
            throw new \RuntimeException('EFS read evidence is unavailable.');
        }
    }

    private function notifyClosed(): void
    {
        $callbacks = $this->onCloseCallbacks;
        $this->onCloseCallbacks = [];
        foreach ($callbacks as $callback) {
            $callback();
        }
    }
}
