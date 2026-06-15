<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Auth\SessionCredentialValidator;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;
use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

/**
 * Handles POST Object (HTML form-based upload to /{bucket}).
 *
 * Supports multipart/form-data uploads with:
 * - V2 signature (AWSAccessKeyId + policy + signature)
 * - Anonymous uploads (no auth, requires public-write ACL)
 * - success_action_redirect / success_action_status
 * - ${filename} substitution in key
 * - content-length-range policy conditions
 * - starts-with, eq conditions on fields
 */
final class PostObjectHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
        private readonly ?CredentialProvider $credentialProvider = null,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');

        // 1. Verify bucket exists.
        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        // 2. Parse multipart/form-data body.
        $fields = $this->parseMultipartFormData($request);

        // 3. Extract standard fields (case-insensitive per S3 spec).
        $f = fn (string $name): ?string => $this->getFormField($fields, $name);
        $key = $f('key');
        $file = $fields['file'] ?? null;
        $policy = $f('policy');
        $signature = $f('signature');
        $accessKeyId = $f('AWSAccessKeyId');
        $acl = $f('acl') ?? 'private';
        $successStatus = $f('success_action_status');
        $successRedirect = $f('success_action_redirect');
        $contentType = $f('Content-Type') ?? 'binary/octet-stream';
        $tagging = $f('tagging');

        // 4. Validate key is present.
        if ($key === null || $key === '') {
            return $this->errorResponse(400, 'InvalidArgument', 'Bucket POST must contain a field named \'key\'.');
        }

        // 5. Substitute ${filename} in key BEFORE policy validation.
        $filename = $fields['_filename'] ?? 'file';
        $key = str_replace('${filename}', $filename, $key);
        // Update fields map so policy condition checks use the substituted key.
        $fields['key'] = $key;

        // 6. Authenticate if credentials provided.
        $ownerId = $request->hasAttribute('ownerId') ? $request->getAttribute('ownerId') : $bucketInfo->ownerId;

        if ($accessKeyId !== null && $accessKeyId !== '') {
            // V2 signature authentication.
            if ($policy === null || $policy === '') {
                return $this->errorResponse(400, 'InvalidArgument', 'POST requires a policy document when credentials are provided.');
            }
            if ($signature === null || $signature === '') {
                return $this->errorResponse(400, 'InvalidArgument', 'POST requires a signature when credentials are provided.');
            }

            $authResult = $this->authenticateV2($accessKeyId, $policy, $signature, $fields['x-amz-security-token'] ?? null);
            if ($authResult === null) {
                return $this->errorResponse(403, 'AccessDenied', 'Access Denied.');
            }

            [$ownerId, $policyJson] = $authResult;

            // 7. Validate policy conditions.
            $conditionError = $this->validatePolicyConditions($policyJson, $fields, $bucket);
            if ($conditionError !== null) {
                return $conditionError;
            }
        } elseif ($policy !== null && $policy !== '') {
            // Policy without credentials — invalid.
            return $this->errorResponse(400, 'InvalidArgument', 'POST policy requires authentication.');
        }
        // Else: anonymous upload — allowed if bucket ACL permits.

        // 8. Get file content.
        $body = $file ?? '';

        // 9. Validate checksum if x-amz-checksum-* provided.
        $checksumSha256 = $f('x-amz-checksum-sha256');
        if ($checksumSha256 !== null && $checksumSha256 !== '') {
            $computedChecksum = base64_encode(hash('sha256', $body, true));
            if (!hash_equals($computedChecksum, $checksumSha256)) {
                return $this->errorResponse(400, 'BadDigest',
                    'The SHA-256 you specified did not match what we received.');
            }
        }

        $checksumCrc32 = $f('x-amz-checksum-crc32');
        if ($checksumCrc32 !== null && $checksumCrc32 !== '') {
            $computedCrc = base64_encode(pack('N', crc32($body)));
            if (!hash_equals($computedCrc, $checksumCrc32)) {
                return $this->errorResponse(400, 'BadDigest',
                    'The CRC32 you specified did not match what we received.');
            }
        }

        // 10. Extract user metadata from x-amz-meta-* form fields.
        $userMetadata = [];
        foreach ($fields as $fieldName => $fieldValue) {
            if (str_starts_with(strtolower($fieldName), 'x-amz-meta-')) {
                $metaKey = substr($fieldName, 11); // Strip 'x-amz-meta-'
                $userMetadata[$metaKey] = $fieldValue;
            }
        }

        // 10. Store the object.
        $etag = '"' . md5($body) . '"';
        $writeResult = $this->storage->putObject($bucket, $key, new ReadableBuffer($body));

        $this->metadata->putObjectMetadata(
            bucket: $bucket,
            key: $key,
            ownerId: $ownerId,
            size: strlen($body),
            etag: $etag,
            contentType: $contentType,
            storagePath: $writeResult->path,
            storageClass: 'STANDARD',
            userMetadata: $userMetadata,
        );

        // 10. Handle tagging if provided.
        if ($tagging !== null && $tagging !== '') {
            $this->applyTagging($bucket, $key, $tagging);
        }

        // 11. Build response.
        if ($successRedirect !== null && $successRedirect !== '') {
            $separator = str_contains($successRedirect, '?') ? '&' : '?';
            $redirectUrl = $successRedirect . $separator
                . 'bucket=' . rawurlencode($bucket)
                . '&key=' . rawurlencode($key)
                . '&etag=' . rawurlencode($etag);

            return new Response(
                status: 303,
                headers: [
                    'Location' => $redirectUrl,
                    'ETag' => $etag,
                ],
            );
        }

        $status = $this->resolveSuccessStatus($successStatus);

        if ($status === 201) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<PostResponse>'
                . '<Location>http://' . ($request->getHeader('host') ?? 'localhost') . '/' . $bucket . '/' . rawurlencode($key) . '</Location>'
                . '<Bucket>' . $bucket . '</Bucket>'
                . '<Key>' . htmlspecialchars($key, ENT_XML1, 'UTF-8') . '</Key>'
                . '<ETag>' . $etag . '</ETag>'
                . '</PostResponse>';

            return new Response(
                status: 201,
                headers: [
                    'Content-Type' => 'application/xml',
                    'ETag' => $etag,
                ],
                body: $xml,
            );
        }

        return new Response(
            status: $status,
            headers: [
                'ETag' => $etag,
            ],
        );
    }

    /**
     * Parse multipart/form-data from the request body.
     *
     * @return array<string, string> Field name → value. 'file' contains the file content, '_filename' the original filename.
     */
    private function parseMultipartFormData(Request $request): array
    {
        $contentType = $request->getHeader('content-type') ?? '';
        $body = $request->getBody()->buffer();

        // Extract boundary.
        if (!preg_match('/boundary=(?:"([^"]+)"|([^\s;]+))/i', $contentType, $m)) {
            throw new InvalidArgumentException('Missing multipart boundary in Content-Type.');
        }
        $boundary = $m[1] !== '' ? $m[1] : $m[2];

        $fields = [];
        $parts = explode('--' . $boundary, $body);

        foreach ($parts as $part) {
            $part = ltrim($part, "\r\n");
            if ($part === '' || $part === '--' || str_starts_with($part, '--')) {
                continue;
            }

            // Split headers from body.
            $headerEnd = strpos($part, "\r\n\r\n");
            if ($headerEnd === false) {
                continue;
            }

            $headerBlock = substr($part, 0, $headerEnd);
            $partBody = substr($part, $headerEnd + 4);
            // Remove trailing \r\n.
            $partBody = rtrim($partBody, "\r\n");

            // Parse Content-Disposition header.
            $name = null;
            $filename = null;
            foreach (explode("\r\n", $headerBlock) as $headerLine) {
                if (stripos($headerLine, 'content-disposition:') === 0) {
                    if (preg_match('/name="([^"]*)"/', $headerLine, $nm)) {
                        $name = $nm[1];
                    }
                    if (preg_match('/filename="([^"]*)"/', $headerLine, $fn)) {
                        $filename = $fn[1];
                    }
                }
            }

            if ($name === null) {
                continue;
            }

            if ($name === 'file') {
                $fields['file'] = $partBody;
                if ($filename !== null) {
                    $fields['_filename'] = $filename;
                }
            } else {
                $fields[$name] = $partBody;
            }
        }

        return $fields;
    }

    /**
     * Authenticate using S3 V2 signature (HMAC-SHA1 of base64 policy).
     *
     * @return array{0: string, 1: array}|null [ownerId, decodedPolicy] on success, null on failure.
     */
    private function authenticateV2(string $accessKeyId, string $policyBase64, string $signatureBase64, ?string $sessionToken): ?array
    {
        if ($this->credentialProvider === null) {
            return null;
        }

        $credential = $this->credentialProvider->getCredential($accessKeyId);
        if ($credential === null || !$credential->isActive) {
            return null;
        }

        try {
            SessionCredentialValidator::validate($credential, $sessionToken);
        } catch (\Throwable) {
            return null;
        }

        // Compute expected signature: HMAC-SHA1(secretKey, base64Policy).
        $expectedSignature = base64_encode(
            hash_hmac('sha1', $policyBase64, $credential->secretAccessKey, true),
        );

        if (!hash_equals($expectedSignature, $signatureBase64)) {
            return null;
        }

        // Decode the policy.
        $policyJson = json_decode(base64_decode($policyBase64, true) ?: '', true);
        if (!is_array($policyJson)) {
            return null;
        }

        return [$credential->ownerId, $policyJson];
    }

    /**
     * Validate policy conditions against the submitted form fields.
     */
    private function validatePolicyConditions(array $policy, array $fields, string $bucket): ?Response
    {
        // Check expiration — must use exact key 'expiration' (case-sensitive per S3 spec).
        $expiration = $policy['expiration'] ?? null;
        if ($expiration === null) {
            // S3 requires lowercase 'expiration' — reject if using wrong case or missing.
            if (isset($policy['Expiration'])) {
                return $this->errorResponse(400, 'InvalidArgument', 'Invalid Policy: the \'expiration\' field is case-sensitive and must be lowercase.');
            }
            return $this->errorResponse(400, 'InvalidArgument', 'Policy must contain an expiration.');
        }

        // Validate date format — must be ISO 8601 (YYYY-MM-DDTHH:MM:SSZ or similar).
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $expiration)) {
            return $this->errorResponse(400, 'InvalidArgument', 'Invalid Policy: Invalid expiration date format.');
        }

        try {
            $expiresAt = new \DateTimeImmutable($expiration, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return $this->errorResponse(400, 'InvalidArgument', 'Invalid expiration date format in policy.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($now > $expiresAt) {
            return $this->errorResponse(403, 'AccessDenied', 'Invalid according to Policy: Policy expired.');
        }

        // Check conditions — must use exact key 'conditions' (case-sensitive per S3 spec).
        $conditions = $policy['conditions'] ?? null;
        if ($conditions === null) {
            if (isset($policy['Conditions'])) {
                return $this->errorResponse(400, 'InvalidArgument', 'Invalid Policy: the \'conditions\' field is case-sensitive and must be lowercase.');
            }
            return $this->errorResponse(400, 'InvalidArgument', 'Policy must contain conditions.');
        }

        if (!is_array($conditions) || $conditions === []) {
            return $this->errorResponse(400, 'InvalidArgument', 'Policy conditions must be a non-empty array.');
        }

        foreach ($conditions as $condition) {
            if (is_array($condition) && !array_is_list($condition)) {
                // Exact match: {"field": "value"} — empty objects are invalid.
                if ($condition === []) {
                    return $this->errorResponse(400, 'InvalidArgument', 'Invalid Policy: empty condition element.');
                }
                foreach ($condition as $field => $expectedValue) {
                    $fieldLower = strtolower($field);
                    $actualValue = $this->getFieldValue($fields, $field, $bucket);
                    if ($actualValue !== $expectedValue) {
                        return $this->errorResponse(403, 'AccessDenied',
                            "Invalid according to Policy: Policy Condition failed: [\"eq\", \"\${$field}\", \"{$expectedValue}\"]");
                    }
                }
            } elseif (is_array($condition) && array_is_list($condition)) {
                if (count($condition) !== 3) {
                    return $this->errorResponse(400, 'InvalidArgument', 'Invalid Policy: condition array must have exactly 3 elements.');
                }
                $operator = strtolower((string) $condition[0]);

                if ($operator === 'starts-with') {
                    $field = ltrim((string) $condition[1], '$');
                    $prefix = (string) $condition[2];
                    $actualValue = $this->getFieldValue($fields, $field, $bucket);

                    // Empty prefix means "any value is accepted".
                    if ($prefix !== '' && !str_starts_with($actualValue, $prefix)) {
                        return $this->errorResponse(403, 'AccessDenied',
                            "Invalid according to Policy: Policy Condition failed: [\"starts-with\", \"\${$field}\", \"{$prefix}\"]");
                    }
                } elseif ($operator === 'eq') {
                    $field = ltrim((string) $condition[1], '$');
                    $expectedValue = (string) $condition[2];
                    $actualValue = $this->getFieldValue($fields, $field, $bucket);

                    if ($actualValue !== $expectedValue) {
                        return $this->errorResponse(403, 'AccessDenied',
                            "Invalid according to Policy: Policy Condition failed: [\"eq\", \"\${$field}\", \"{$expectedValue}\"]");
                    }
                } elseif ($operator === 'content-length-range') {
                    $min = (int) $condition[1];
                    $max = (int) $condition[2];
                    $fileSize = strlen($fields['file'] ?? '');

                    if ($fileSize < $min) {
                        return $this->errorResponse(400, 'EntityTooSmall',
                            'Your proposed upload is smaller than the minimum allowed size.');
                    }
                    if ($fileSize > $max) {
                        return $this->errorResponse(400, 'EntityTooLarge',
                            'Your proposed upload exceeds the maximum allowed size.');
                    }
                }
            }
        }

        return null; // All conditions passed.
    }

    /**
     * Case-insensitive form field lookup.
     */
    private function getFormField(array $fields, string $name): ?string
    {
        $nameLower = strtolower($name);
        foreach ($fields as $k => $v) {
            if (strtolower($k) === $nameLower) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Get the value of a field, with special handling for 'bucket'.
     */
    private function getFieldValue(array $fields, string $field, string $bucket): string
    {
        $fieldLower = strtolower($field);

        if ($fieldLower === 'bucket') {
            return $bucket;
        }

        // Case-insensitive field lookup.
        foreach ($fields as $k => $v) {
            if (strtolower($k) === $fieldLower) {
                return $v;
            }
        }

        return '';
    }

    /**
     * Resolve the success status code.
     */
    private function resolveSuccessStatus(?string $successStatus): int
    {
        if ($successStatus === '200') {
            return 200;
        }
        if ($successStatus === '201') {
            return 201;
        }

        // Default: 204 for any other or missing value.
        return 204;
    }

    /**
     * Apply tagging from the form field (XML format).
     */
    private function applyTagging(string $bucket, string $key, string $taggingXml): void
    {
        try {
            $xml = new \SimpleXMLElement($taggingXml);
            $tags = [];
            foreach ($xml->TagSet->Tag ?? [] as $tag) {
                $tags[] = [
                    'key' => (string) $tag->Key,
                    'value' => (string) $tag->Value,
                ];
            }
            if ($tags !== []) {
                $this->metadata->putObjectTagging($bucket, $key, $tags);
            }
        } catch (\Throwable) {
            // Silently ignore invalid tagging XML.
        }
    }

    /**
     * Build an S3 XML error response.
     */
    private function errorResponse(int $status, string $code, string $message): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<Error>'
            . '<Code>' . $code . '</Code>'
            . '<Message>' . htmlspecialchars($message, ENT_XML1, 'UTF-8') . '</Message>'
            . '</Error>';

        return new Response(
            status: $status,
            headers: ['Content-Type' => 'application/xml'],
            body: $xml,
        );
    }
}
