<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptedMultipartPartCodec;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Handler\Multipart\CompleteMultipartUploadHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Metadata\MultipartWriteIntent;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\FilesystemBackend;

[$script, $databasePath, $storagePath, $mode] = $_SERVER['argv'];
$metadata = new SqliteMetadataStore($databasePath);
$metadata->initialize();
$metadata->createBucket('owner', 'bucket', 'us-east-1');
$storage = new FilesystemBackend($storagePath);
$storage->createBucket('bucket');
$uploadId = bin2hex(random_bytes(16));
$usesSse = in_array($mode, ['ciphertext', 'encrypted-read'], true);
$encryption = null;
$bucket = $metadata->getBucket('bucket');
if ($bucket === null) {
    throw new \RuntimeException('Crash fixture bucket was not created.');
}
$metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner', userMetadata: $usesSse
    ? [
        '__sse-algorithm' => EncryptionRequestResolver::SSE_S3,
        '__mpu-part-format' => EncryptedMultipartPartCodec::FORMAT,
    ] : []);
$plaintext = 'multipart bytes';
$physicalPart = $plaintext;
if ($usesSse) {
    $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
    $physicalPart = EncryptedMultipartPartCodec::encode(
        $encryption,
        EncryptionRequestResolver::SSE_S3,
        $plaintext,
        null,
        $bucket->generation,
        'bucket',
        $uploadId,
        1,
    );
}
$part = $storage->putPart('bucket', 'key', $uploadId, 1, new ReadableBuffer($physicalPart));
$partEtag = md5($plaintext);
$metadata->putPart($uploadId, 1, $partEtag, strlen($plaintext), $part->path);
file_put_contents($storagePath . '/upload-id', $uploadId);

if ($mode !== 'committed') {
    $attemptId = bin2hex(random_bytes(16));
    $paths = $storage->planMultipartCompletionPaths('bucket', 'key', $bucket->generation, $attemptId);
    $selected = [[
        'partNumber' => 1,
        'etag' => $partEtag,
        'size' => strlen($plaintext),
        'storagePath' => $part->path,
    ]];
    $now = $metadata->multipartWriteIntentNow();
    $metadata->createMultipartWriteIntent(new MultipartWriteIntent(
        attemptId: $attemptId,
        uploadId: $uploadId,
        bucket: 'bucket',
        bucketGeneration: $bucket->generation,
        ownerId: 'owner',
        storageTier: 'STANDARD',
        assemblyPath: $paths['assembly'],
        finalPath: $paths['final'],
        partDigest: MultipartWriteIntent::digestParts($selected),
        sseMode: $usesSse ? EncryptionRequestResolver::SSE_S3 : null,
        leaseUntil: $now + 60,
        nextAttemptAt: $now + 60,
    ));
    if (! $metadata->armMultipartWriteIntent($attemptId, bin2hex(random_bytes(16)), $now + 60, $now + 120)) {
        throw new \RuntimeException('Crash fixture could not arm its local completion dispatch.');
    }
    if ($mode === 'assembly') {
        $storage->assembleMultipartUploadAtPath('bucket', 'key', $uploadId, $selected, $paths['assembly']);
    } elseif ($mode === 'armed-assembly') {
        $storage->assembleMultipartUploadFencedAtPath(
            'bucket',
            'key',
            $bucket->generation,
            $attemptId,
            $uploadId,
            $selected,
            $paths['assembly'],
        );
    } elseif (in_array($mode, ['encrypted-read', 'ciphertext'], true)) {
        if ($encryption === null) {
            throw new \RuntimeException('Crash fixture encryption is unavailable.');
        }
        $stream = $storage->getObjectByPath($part->path);
        try {
            $stored = \Amp\ByteStream\buffer($stream);
        } finally {
            $stream->close();
        }
        $decoded = EncryptedMultipartPartCodec::decode(
            $encryption,
            EncryptionRequestResolver::SSE_S3,
            $stored,
            null,
            $bucket->generation,
            'bucket',
            $uploadId,
            1,
        );
        if (! hash_equals($plaintext, $decoded)) {
            throw new \RuntimeException('Crash fixture encrypted part did not round-trip.');
        }
        if ($mode === 'ciphertext') {
            $encrypted = $encryption->encryptSseS3($decoded);
            $storage->putObjectAtPath(
                'bucket',
                'key',
                new ReadableBuffer($encrypted['ciphertext']),
                $paths['final'],
            );
        }
    } else {
        throw new \RuntimeException('Unknown crash fixture phase.');
    }
    exit(71);
}

$body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
    . $partEtag . '</ETag></Part></CompleteMultipartUpload>';
$request = new Request(
    new class implements Client {
        public function getId(): int
        {
            return 1;
        }
        public function getRemoteAddress(): SocketAddress
        {
            return new InternetAddress('127.0.0.1', 12345);
        }
        public function getLocalAddress(): SocketAddress
        {
            return new InternetAddress('127.0.0.1', 9000);
        }
        public function getTlsInfo(): ?TlsInfo
        {
            return null;
        }
        public function close(): void {}
        public function isClosed(): bool
        {
            return false;
        }
        public function onClose(\Closure $onClose): void {}
    },
    'POST',
    Http::new('http://127.0.0.1/bucket/key?uploadId=' . $uploadId),
    [],
    $body,
);
$request->setAttribute('s3.bucket', 'bucket');
$request->setAttribute('s3.key', 'key');
$request->setAttribute('ownerId', 'owner');
$request->setAttribute('s3.operation', S3Operation::CompleteMultipartUpload);

(new CompleteMultipartUploadHandler($metadata, $storage, $encryption))->handleRequest($request);
exit(71);
