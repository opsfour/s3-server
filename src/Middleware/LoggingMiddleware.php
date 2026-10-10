<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Exception\S3Exception;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Logging\AccessLogWriter;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Security\RequestNetworkContext;
use OpsFour\S3Server\Storage\TeeReadableStream;
use Psr\Log\LoggerInterface;

/**
 * Logs S3 request/response details at PSR-3 info level.
 *
 * Records: HTTP method, path, status code, response time (ms),
 * request Content-Length, and response Content-Length.
 */
final class LoggingMiddleware implements Middleware
{
    private readonly FailureIsolatingLogger $logger;

    public function __construct(
        LoggerInterface $logger,
        private readonly ?AccessLogWriter $accessLogs = null,
    ) {
        $this->logger = new FailureIsolatingLogger($logger);
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $receivedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $startTime = hrtime(true);

        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $query = self::redactSensitiveQueryValues($request->getUri()->getQuery());
        $fullPath = $query !== '' ? "{$path}?{$query}" : $path;
        $requestContentLength = $request->getHeader('Content-Length') ?? '-';
        $requestId = $request->hasAttribute('requestId')
            ? (string) $request->getAttribute('requestId')
            : '-';

        try {
            $response = $requestHandler->handleRequest($request);
        } catch (\Throwable $e) {
            $this->writeLogEntry(
                $request,
                $method,
                $fullPath,
                $requestContentLength,
                $requestId,
                $e instanceof S3Exception ? $e->getHttpStatus() : 500,
                '-',
                $startTime,
                $receivedAt,
                $e instanceof S3Exception ? $e->getErrorCode() : '',
            );

            throw $e;
        }

        $responseContentLength = $response->getHeader('Content-Length') ?? '-';
        $statusCode = $response->getStatus();
        if ($this->accessLogs !== null && $method !== 'HEAD' && !in_array($statusCode, [204, 304], true)
            && !($response->getBody() instanceof ReadableBuffer)) {
            // A streaming response can be closed before its advertised length
            // is consumed. Record the bytes actually read by the HTTP driver.
            $bytesRead = 0;
            $body = new TeeReadableStream(
                $response->getBody(),
                static function (string $chunk) use (&$bytesRead): void {
                    $bytesRead += strlen($chunk);
                },
            );
            $body->onClose(function () use (
                $request,
                $method,
                $fullPath,
                $requestContentLength,
                $requestId,
                $statusCode,
                $startTime,
                $receivedAt,
                &$bytesRead,
            ): void {
                $this->writeLogEntry(
                    $request,
                    $method,
                    $fullPath,
                    $requestContentLength,
                    $requestId,
                    $statusCode,
                    (string) $bytesRead,
                    $startTime,
                    $receivedAt,
                );
            });
            $response->setBody($body);
            if ($responseContentLength !== '-') {
                $response->setHeader('Content-Length', $responseContentLength);
            }

            return $response;
        }

        $this->writeLogEntry(
            $request,
            $method,
            $fullPath,
            $requestContentLength,
            $requestId,
            $statusCode,
            $responseContentLength,
            $startTime,
            $receivedAt,
        );

        return $response;
    }

    private function writeLogEntry(
        Request $request,
        string $method,
        string $fullPath,
        string $requestContentLength,
        string $requestId,
        int $statusCode,
        string $responseContentLength,
        int $startTime,
        \DateTimeImmutable $receivedAt,
        string $errorCode = '',
    ): void {
        $elapsedMs = round((hrtime(true) - $startTime) / 1_000_000, 2);
        $this->logger->info(
            '{method} {path} {status} {time}ms',
            [
                'method' => $method,
                'path' => $fullPath,
                'status' => $statusCode,
                'time' => $elapsedMs,
                'requestId' => $requestId,
                'requestContentLength' => $requestContentLength,
                'responseContentLength' => $responseContentLength,
            ],
        );

        $bucket = $request->hasAttribute('s3.bucket')
            ? (string) $request->getAttribute('s3.bucket')
            : '';
        // An unresolved bucket has no owner to bind the log entry to. A name
        // created later by another account must not receive this request.
        $bucketOwner = $request->hasAttribute('s3.cachedBucketOwner')
            ? $request->getAttribute('s3.cachedBucketOwner')
            : null;
        if ($bucket !== '' && is_string($bucketOwner) && $bucketOwner !== '') {
            $tlsInfo = $request->getClient()->getTlsInfo();
            $credential = $request->hasAttribute('credential') ? $request->getAttribute('credential') : null;
            $queryParams = QueryStringParser::parse($request->getUri()->getQuery());
            $operation = $request->hasAttribute('s3.operation')
                ? $request->getAttribute('s3.operation')
                : null;
            $versionId = $operation instanceof S3Operation
                && ($operation->usesExistingObjectOwner() || $operation === S3Operation::DeleteObject)
                ? ($queryParams['versionId'] ?? '')
                : '';
            $signatureVersion = $request->hasAttribute('s3.signatureVersion')
                ? (string) $request->getAttribute('s3.signatureVersion')
                : '';
            $authenticationType = '';
            $authorization = $request->getHeader('authorization') ?? '';
            $authenticated = $request->hasAttribute('credential')
                ? ($credential instanceof Credential && $credential->ownerId !== '')
                : ($request->hasAttribute('ownerId') && $request->getAttribute('ownerId') !== '');
            // Browser POST authenticates its policy inside the form. A supplied
            // Authorization header or query algorithm is not verified there.
            if ($authenticated && $operation !== S3Operation::PostObject) {
                if (str_starts_with($authorization, 'AWS4-HMAC-SHA256 ')) {
                    $signatureVersion = 'SigV4';
                    $authenticationType = 'AuthHeader';
                } elseif (($queryParams['X-Amz-Algorithm'] ?? '') === 'AWS4-HMAC-SHA256') {
                    $signatureVersion = 'SigV4';
                    $authenticationType = 'QueryString';
                }
            }
            $this->accessLogs?->log(
                bucket: $bucket,
                key: $request->hasAttribute('s3.key') ? (string) $request->getAttribute('s3.key') : '',
                operation: $request->hasAttribute('s3.website') && $request->getAttribute('s3.website') === true
                    ? "WEBSITE.{$method}.OBJECT"
                    : self::accessLogOperation($operation, $method),
                httpStatus: $statusCode,
                bytesTransferred: $method !== 'HEAD' && !in_array($statusCode, [204, 304], true)
                    && is_numeric($responseContentLength)
                        ? (int) $responseContentLength
                        : 0,
                remoteIp: RequestNetworkContext::rateLimitIp($request),
                requesterId: $request->hasAttribute('credential')
                    ? ($credential instanceof Credential ? $credential->ownerId : '')
                    : ($request->hasAttribute('ownerId') ? (string) $request->getAttribute('ownerId') : ''),
                bucketOwnerId: $bucketOwner,
                bucketGeneration: $request->hasAttribute('s3.cachedBucketGeneration')
                    ? (string) $request->getAttribute('s3.cachedBucketGeneration')
                    : '',
                requestId: $requestId,
                requestUri: "{$method} {$fullPath} HTTP/{$request->getProtocolVersion()}",
                errorCode: $errorCode,
                receivedAt: $receivedAt,
                objectSize: $request->hasAttribute('s3.objectSize')
                    && is_int($request->getAttribute('s3.objectSize'))
                        ? $request->getAttribute('s3.objectSize')
                        : null,
                hostId: $request->hasAttribute('amzId2') ? (string) $request->getAttribute('amzId2') : '',
                userAgent: $request->getHeader('User-Agent') ?? '',
                referer: $request->getHeader('Referer') ?? '',
                hostHeader: $request->getHeader('Host') ?? '',
                versionId: $versionId,
                signatureVersion: $signatureVersion,
                cipherSuite: $tlsInfo?->getCipherName() ?? '',
                authenticationType: $authenticationType,
                tlsVersion: $tlsInfo?->getVersion() ?? '',
            );
        }

        // Both copy operations perform a destination PUT and a source GET.
        // Bind the source record to the generation captured by the handler.
        if ($this->accessLogs !== null
            && $request->hasAttribute('s3.operation')
            && in_array($request->getAttribute('s3.operation'), [S3Operation::CopyObject, S3Operation::UploadPartCopy], true)
            && $request->hasAttribute('s3.copySourceBucket')
            && $request->hasAttribute('s3.copySourceLogEligible')
            && $request->getAttribute('s3.copySourceLogEligible') === true) {
            $sourceBucket = $request->getAttribute('s3.copySourceBucket');
            $sourceKey = $request->getAttribute('s3.copySourceKey');
            $sourceOwner = $request->getAttribute('s3.copySourceBucketOwner');
            $sourceGeneration = $request->getAttribute('s3.copySourceBucketGeneration');
            if (!is_string($sourceBucket) || $sourceBucket === '' || !is_string($sourceKey)
                || !is_string($sourceOwner) || $sourceOwner === ''
                || !is_string($sourceGeneration) || $sourceGeneration === '') {
                return;
            }

            $sourceReadComplete = $request->hasAttribute('s3.copySourceReadComplete')
                && $request->getAttribute('s3.copySourceReadComplete') === true;
            $sourceSize = $request->hasAttribute('s3.copySourceObjectSize')
                ? $request->getAttribute('s3.copySourceObjectSize')
                : null;
            $sourceBytesTransferred = $request->hasAttribute('s3.copySourceBytesTransferred')
                ? $request->getAttribute('s3.copySourceBytesTransferred')
                : $sourceSize;
            $sourceVersionId = $request->hasAttribute('s3.copySourceVersionId')
                ? $request->getAttribute('s3.copySourceVersionId')
                : null;
            $sourcePath = '/' . $sourceBucket . '/' . implode('/', array_map('rawurlencode', explode('/', $sourceKey)));
            if (is_string($sourceVersionId)) {
                $sourcePath .= '?versionId=' . rawurlencode($sourceVersionId);
            }
            $credential = $request->hasAttribute('credential') ? $request->getAttribute('credential') : null;
            $queryParams = QueryStringParser::parse($request->getUri()->getQuery());
            $authenticated = $request->hasAttribute('credential')
                ? ($credential instanceof Credential && $credential->ownerId !== '')
                : ($request->hasAttribute('ownerId') && $request->getAttribute('ownerId') !== '');
            $signatureVersion = $request->hasAttribute('s3.signatureVersion')
                ? (string) $request->getAttribute('s3.signatureVersion')
                : '';
            $authenticationType = '';
            if ($authenticated) {
                if (str_starts_with($request->getHeader('authorization') ?? '', 'AWS4-HMAC-SHA256 ')) {
                    $signatureVersion = 'SigV4';
                    $authenticationType = 'AuthHeader';
                } elseif (($queryParams['X-Amz-Algorithm'] ?? '') === 'AWS4-HMAC-SHA256') {
                    $signatureVersion = 'SigV4';
                    $authenticationType = 'QueryString';
                }
            }
            $tlsInfo = $request->getClient()->getTlsInfo();
            $this->accessLogs->log(
                bucket: $sourceBucket,
                key: $sourceKey,
                operation: $request->getAttribute('s3.operation') === S3Operation::UploadPartCopy
                    ? 'REST.COPY.PART_GET' : 'REST.COPY.OBJECT_GET',
                httpStatus: $sourceReadComplete ? 200 : $statusCode,
                bytesTransferred: $sourceReadComplete && is_int($sourceBytesTransferred) ? $sourceBytesTransferred : 0,
                remoteIp: RequestNetworkContext::rateLimitIp($request),
                requesterId: $request->hasAttribute('credential')
                    ? ($credential instanceof Credential ? $credential->ownerId : '')
                    : ($request->hasAttribute('ownerId') ? (string) $request->getAttribute('ownerId') : ''),
                bucketOwnerId: $sourceOwner,
                bucketGeneration: $sourceGeneration,
                requestId: $requestId,
                requestUri: "GET {$sourcePath} HTTP/{$request->getProtocolVersion()}",
                errorCode: $sourceReadComplete ? '' : $errorCode,
                receivedAt: $receivedAt,
                objectSize: is_int($sourceSize) ? $sourceSize : null,
                hostId: $request->hasAttribute('amzId2') ? (string) $request->getAttribute('amzId2') : '',
                userAgent: $request->getHeader('User-Agent') ?? '',
                referer: $request->getHeader('Referer') ?? '',
                hostHeader: $request->getHeader('Host') ?? '',
                versionId: is_string($sourceVersionId) ? $sourceVersionId : '',
                signatureVersion: $signatureVersion,
                cipherSuite: $tlsInfo?->getCipherName() ?? '',
                authenticationType: $authenticationType,
                tlsVersion: $tlsInfo?->getVersion() ?? '',
            );
        }
    }

    private static function accessLogOperation(mixed $operation, string $method): string
    {
        return match ($operation) {
            S3Operation::GetObject => 'REST.GET.OBJECT',
            S3Operation::HeadObject => 'REST.HEAD.OBJECT',
            S3Operation::PutObject => 'REST.PUT.OBJECT',
            S3Operation::DeleteObject => 'REST.DELETE.OBJECT',
            S3Operation::PostObject => 'REST.POST.OBJECT',
            S3Operation::DeleteObjects => 'BATCH.DELETE.OBJECT',
            S3Operation::HeadBucket => 'REST.HEAD.BUCKET',
            S3Operation::GetBucketVersioning => 'REST.GET.VERSIONING',
            S3Operation::GetBucketLogging => 'REST.GET.LOGGING_STATUS',
            S3Operation::GetBucketPolicy => 'REST.GET.BUCKETPOLICY',
            S3Operation::PutBucketPolicy => 'REST.PUT.BUCKETPOLICY',
            S3Operation::ListObjects, S3Operation::ListObjectsV2 => 'REST.GET.BUCKET',
            S3Operation::CopyObject => 'REST.COPY.OBJECT',
            S3Operation::UploadPartCopy => 'REST.COPY.PART',
            S3Operation::GetObjectAcl => 'REST.GET.OBJECT_ACL',
            S3Operation::GetObjectTagging => 'REST.GET.OBJECT_TAGGING',
            S3Operation::GetObjectAttributes => 'REST.GET.OBJECT_ATTRIBUTES',
            S3Operation::SelectObjectContent => 'REST.SELECT.OBJECT_CONTENT',
            S3Operation::GetPublicAccessBlock => 'REST.GET.PUBLIC.ACCESS.BLOCK',
            S3Operation::GetBucketPolicyStatus => 'REST.GET.POLICY.STATUS',
            default => $operation instanceof S3Operation ? $operation->value : $method,
        };
    }

    private static function redactSensitiveQueryValues(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $sensitive = [
            'awsaccesskeyid' => true,
            'signature' => true,
            'securitytoken' => true,
            'x-amz-credential' => true,
            'x-amz-security-token' => true,
            'x-amz-signature' => true,
        ];
        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            [$rawName] = array_pad(explode('=', $pair, 2), 2, '');
            if (isset($sensitive[strtolower(rawurldecode($rawName))])) {
                $pairs[] = $rawName . '=' . rawurlencode('[REDACTED]');
            } else {
                $pairs[] = $pair;
            }
        }

        return implode('&', $pairs);
    }
}
