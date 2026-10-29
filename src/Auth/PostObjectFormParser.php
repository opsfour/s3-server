<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use Amp\ByteStream\BufferException;
use Amp\Http\HttpStatus;
use Amp\Http\Server\FormParser\StreamedField;
use Amp\Http\Server\HttpErrorException;
use Amp\Http\Server\Request;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use OpsFour\S3Server\Exception\InvalidAccessKeyIdException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Http\ObjectTagValidator;
use OpsFour\S3Server\Http\RequestHeaderValues;
use OpsFour\S3Server\Http\ObjectKeyValidator;
use OpsFour\S3Server\Exception\SignatureDoesNotMatchException;
use OpsFour\S3Server\Http\Iso8601Timestamp;
use OpsFour\S3Server\Storage\StorageTempFileLease;
use OpsFour\S3Server\Xml\XmlRequestParser;

/**
 * Streams an S3 POST Object form to a temporary file and authenticates it.
 */
final class PostObjectFormParser
{
    private const int MAX_SIGV4_SIGNING_AGE_SECONDS = 604_800;

    private const int MAX_CLOCK_SKEW_SECONDS = 900;

    private const int FIELD_SIZE_LIMIT = 65_536;

    private PostObjectTempFileSweeper $tempFileSweeper;

    private PostObjectSpoolCoordinator $spoolCoordinator;

    public function __construct(
        private readonly CredentialProvider $credentialProvider,
        private readonly string $region,
        private readonly int $bodySizeLimit,
        ?PostObjectTempFileSweeper $tempFileSweeper = null,
        private readonly ?PostObjectAuthorizationPreflight $authorizationPreflight = null,
        private readonly int $spoolBudgetBytes = 10_737_418_240,
        ?PostObjectSpoolCoordinator $spoolCoordinator = null,
        ?string $tempDir = null,
    ) {
        if ($spoolBudgetBytes < 1) {
            throw new \InvalidArgumentException('Browser POST spool budget must be positive.');
        }
        if ($tempDir !== null && ($tempFileSweeper !== null || $spoolCoordinator !== null)) {
            throw new \InvalidArgumentException('Explicit browser POST temp directory cannot be mixed with injected spool components.');
        }
        $this->spoolCoordinator = $spoolCoordinator ?? new PostObjectSpoolCoordinator(
            $tempDir,
            budgetBytes: $spoolBudgetBytes,
        );
        $this->spoolCoordinator->bindBudget($spoolBudgetBytes);
        $this->tempFileSweeper = $tempFileSweeper ?? new PostObjectTempFileSweeper(
            $this->spoolCoordinator->workerPool(),
            $this->spoolCoordinator->tempDir(),
        );
    }

    /** @internal Bind parser dependencies to the S3Server-owned coordinator before serving. */
    public function bindServerSpoolCoordinator(PostObjectSpoolCoordinator $coordinator): void
    {
        $this->spoolCoordinator = $coordinator;
        $this->tempFileSweeper = new PostObjectTempFileSweeper(
            $coordinator->workerPool(),
            $coordinator->tempDir(),
        );
    }

    public function parse(Request $request): PostObjectForm
    {
        // Browser form authentication owns this value; discard anything an
        // earlier request component attempted to inject.
        AuthType::clear($request);

        $contentType = RequestHeaderValues::singleton($request, 'content-type') ?? '';
        // The multipart boundary itself is limited to 70 bytes by RFC 2046.
        // Bound the whole header before the upstream boundary regex sees it.
        if (strlen($contentType) > 1_024) {
            throw new InvalidArgumentException('POST Object requires a valid multipart boundary.');
        }
        if (!str_starts_with(strtolower($contentType), 'multipart/form-data')) {
            throw new InvalidArgumentException('POST Object requires multipart/form-data.');
        }

        if (preg_match('/;\s*boundary\s*=/', $contentType) !== 1) {
            throw new InvalidArgumentException('POST Object requires a multipart boundary.');
        }
        $boundary = \Amp\Http\Server\FormParser\parseContentBoundary($contentType);
        if ($boundary === null || $boundary === '') {
            throw new InvalidArgumentException('POST Object requires a multipart boundary.');
        }
        if (strlen($boundary) > 70) {
            throw new InvalidArgumentException('POST Object requires a valid multipart boundary.');
        }

        // Browser POST authorizes object options through signed policy form
        // fields. Raw request headers are outside that policy and must not be
        // carried into the synthesized PutObject request below.
        foreach ($request->getHeaders() as $name => $_values) {
            $name = strtolower($name);
            if (str_starts_with($name, 'x-amz-')
                || ($name !== 'content-type' && $name !== 'content-length' && str_starts_with($name, 'content-'))
                || in_array($name, ['cache-control', 'expires', 'if-match', 'if-none-match'], true)) {
                throw new AccessDeniedException('POST Object options must be provided as policy-covered form fields.');
            }
        }

        // Keep the HTTP driver's body limit and Content-Length unchanged.
        $request->getBody()->increaseSizeLimit($this->bodySizeLimit);

        $this->tempFileSweeper->sweepIfDue();

        $fields = [];
        $filePath = null;
        $filename = null;
        $fileSize = 0;
        $seenFile = false;
        $key = null;
        $credential = null;
        $policy = null;
        $policyMaxFileSize = null;
        $fileLease = null;
        $reservation = null;
        $spoolKey = null;
        $fileIdentity = null;
        $activeField = null;

        try {
            $parser = new BoundedMultipartFormParser($request->getBody(), $boundary, $this->bodySizeLimit);
            foreach ($parser->parse() as $field) {
                $activeField = $field;
                if ($field->isFile()) {
                    if ($seenFile || strtolower($field->getName()) !== 'file') {
                        throw new InvalidArgumentException('POST Object requires exactly one file field named "file".');
                    }

                    $filename = self::safeFilename($field);
                    $keyField = self::value($fields, 'key');
                    if ($keyField === null || $keyField === '') {
                        throw new InvalidArgumentException('Bucket POST must contain a field named "key".');
                    }
                    $key = str_replace('${filename}', $filename, $keyField);
                    ObjectKeyValidator::assertValid($key);
                    self::setValue($fields, 'key', $key);

                    // The file field must be last, so all signature fields are
                    // available now. Reject bad credentials before spooling an
                    // attacker-controlled body to temporary disk.
                    [$credential, $policy] = $this->authenticate($fields);
                    if ($credential !== null) {
                        AuthType::set($request, AuthType::Post);
                        $request->setAttribute(
                            's3.signatureVersion',
                            self::value($fields, 'x-amz-algorithm') !== null ? 'SigV4' : 'SigV2',
                        );
                    }
                    $bucket = $request->getAttribute('s3.bucket');
                    $policyMaxFileSize = self::preflightPolicy(
                        $policy,
                        $fields,
                        is_string($bucket) ? $bucket : null,
                    );
                    // S3's browser POST `tagging` field contains a Tagging XML
                    // document. Validate and normalize it before the file body
                    // is accepted into the encrypted spool.
                    self::taggingHeader($fields);
                    $this->authorizationPreflight?->check($request, $credential, $fields, $key);
                    $seenFile = true;
                    $spoolKey = random_bytes(32);
                    $filePath = $this->createTempPath();
                    $reservation = $this->spoolCoordinator->start($filePath);
                    $createdStat = @lstat($filePath);
                    if ($createdStat === false) {
                        throw new \RuntimeException('Browser POST temporary file disappeared before writing.');
                    }
                    $fileIdentity = [
                        'device' => $createdStat['dev'],
                        'inode' => $createdStat['ino'],
                        'owner' => $createdStat['uid'],
                    ];
                    $reservedFileBytes = 0;
                    $requestUpperBound = self::declaredRequestBodyLength($request);
                    $knownFileUpperBound = $policyMaxFileSize === null
                        ? $requestUpperBound
                        : ($requestUpperBound === null ? $policyMaxFileSize : min($policyMaxFileSize, $requestUpperBound));
                    if ($knownFileUpperBound !== null && $knownFileUpperBound > 0) {
                        $reservedFileBytes = EncryptedTempFrames::worstCaseEncodedBytes($knownFileUpperBound);
                        $this->spoolCoordinator->reserve($reservation, $reservedFileBytes, $this->spoolBudgetBytes);
                    }
                    $fileLease = StorageTempFileLease::acquireExisting($filePath);
                    $file = \Amp\File\openFile($filePath, 'c');
                    $fileLease->assertPath($filePath);
                    // Amp's writable modes create a missing file. Detect a
                    // replacement before any upload bytes reach that handle.
                    clearstatcache(true, $filePath);
                    $openedStat = @lstat($filePath);
                    if ($openedStat === false
                        || ($openedStat['mode'] & 0o170000) !== 0o100000
                        || $openedStat['dev'] !== $createdStat['dev']
                        || $openedStat['ino'] !== $createdStat['ino']
                        || ($openedStat['mode'] & 0o077) !== 0) {
                        $file->close();
                        throw new \RuntimeException('Browser POST temporary file changed before writing.');
                    }
                    $writeCancellation = null;
                    try {
                        $frameIndex = 0;
                        $fileBytes = 0;
                        $pendingPlaintext = '';
                        $writeFrame = function (string $plaintext) use (
                            $spoolKey,
                            &$frameIndex,
                            &$fileBytes,
                            &$reservedFileBytes,
                            $reservation,
                            $file,
                        ): void {
                            $frame = EncryptedTempFrames::encode($spoolKey, $frameIndex++, $plaintext);
                            $frameBytes = strlen($frame);
                            if ($frameBytes > PHP_INT_MAX - $fileBytes) {
                                throw new \OverflowException('Browser POST temporary size overflowed.');
                            }
                            $nextFileBytes = $fileBytes + $frameBytes;
                            if ($nextFileBytes > $reservedFileBytes) {
                                $this->spoolCoordinator->reserve(
                                    $reservation,
                                    $nextFileBytes - $reservedFileBytes,
                                    $this->spoolBudgetBytes,
                                );
                                $reservedFileBytes = $nextFileBytes;
                            }
                            $file->write($frame);
                            $fileBytes = $nextFileBytes;
                        };
                        while (($chunk = $field->read()) !== null) {
                            $chunkBytes = strlen($chunk);
                            $fileSize += $chunkBytes;
                            if ($policyMaxFileSize !== null && $fileSize > $policyMaxFileSize) {
                                throw new EntityTooLargeException('Upload exceeds the policy maximum.');
                            }
                            $pendingPlaintext .= $chunk;
                            while (strlen($pendingPlaintext) >= EncryptedTempFrames::MAX_PLAINTEXT_BYTES) {
                                $writeFrame(substr($pendingPlaintext, 0, EncryptedTempFrames::MAX_PLAINTEXT_BYTES));
                                $pendingPlaintext = substr($pendingPlaintext, EncryptedTempFrames::MAX_PLAINTEXT_BYTES);
                            }
                        }
                        if ($pendingPlaintext !== '') {
                            $writeFrame($pendingPlaintext);
                        }
                    } catch (\Amp\CancelledException $error) {
                        $writeCancellation = $error;
                        throw $error;
                    } finally {
                        \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::inFinally(
                            $writeCancellation,
                            $file->close(...),
                        );
                        $fileLease->release();
                        $fileLease = null;
                    }
                    clearstatcache(true, $filePath);
                    $completedStat = @lstat($filePath);
                    if ($completedStat === false
                        || ($completedStat['mode'] & 0o170000) !== 0o100000
                        || ($completedStat['mode'] & 0o077) !== 0
                        || $completedStat['dev'] !== $fileIdentity['device']
                        || $completedStat['ino'] !== $fileIdentity['inode']
                        || $completedStat['uid'] !== $fileIdentity['owner']
                        || $completedStat['size'] !== $fileBytes) {
                        throw new \RuntimeException('Browser POST temporary file is incomplete.');
                    }

                    $activeField = null;
                    continue;
                }

                // AWS requires the file field to be the final form field.
                if ($seenFile) {
                    throw new InvalidArgumentException('The file field must be the last field in a POST Object form.');
                }

                $name = $field->getName();
                if ($name === '' || self::fieldExists($fields, $name)) {
                    throw new InvalidArgumentException('POST Object form field names must be non-empty and unique.');
                }
                $fields[$name] = $field->buffer(limit: self::FIELD_SIZE_LIMIT);
                $activeField = null;
            }

            if ($filePath === null || $filename === null || $key === null || $spoolKey === null || $fileIdentity === null) {
                throw new InvalidArgumentException('POST Object requires a file field.');
            }

            $form = new PostObjectForm(
                fields: $fields,
                key: $key,
                filename: $filename,
                filePath: $filePath,
                fileSize: $fileSize,
                spoolKey: $spoolKey,
                credential: $credential,
                policy: $policy,
                onCleanup: function () use ($reservation): void {
                    if ($reservation !== null) {
                        $this->spoolCoordinator->cleanup($reservation);
                    }
                },
                fileIdentity: $fileIdentity,
                workerPool: $this->spoolCoordinator->workerPool(),
            );

            $this->prepareRequestForAuthorizationAndWrite($request, $form);

            return $form;
        } catch (\Throwable $e) {
            \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::afterFailure(
                $e,
                static function () use ($activeField): void {
                    $activeField?->close();
                },
                static function () use (&$fileLease): void {
                    $fileLease?->release();
                    $fileLease = null;
                },
                function () use ($reservation): void {
                    if ($reservation !== null) {
                        $this->spoolCoordinator->cleanup($reservation);
                    }
                },
            );

            if ($e instanceof HttpErrorException && $e->getStatus() === HttpStatus::BAD_REQUEST) {
                throw new InvalidArgumentException(
                    $e->getReason() ?: 'POST Object multipart form is malformed.',
                );
            }
            if ($e instanceof BufferException) {
                throw new InvalidArgumentException('POST Object form fields may not exceed 64 KiB.');
            }

            throw $e;
        }
    }

    /**
     * Reject signed forms whose non-file conditions cannot succeed before
     * writing their file to disk. The handler checks again after parsing.
     *
     * @param array<string, mixed>|null $policy
     * @param array<string, string> $fields
     */
    private static function preflightPolicy(?array $policy, array $fields, ?string $bucket): ?int
    {
        if ($policy === null) {
            return null;
        }

        $expiration = $policy['expiration'] ?? null;
        if (!is_string($expiration)) {
            throw new InvalidArgumentException('Policy must contain a string expiration.');
        }
        $expiresAt = Iso8601Timestamp::parse($expiration, requireUtc: true);
        if ($expiresAt === null) {
            throw new InvalidArgumentException('Invalid expiration date format in policy.');
        }
        if (new \DateTimeImmutable('now', new \DateTimeZone('UTC')) > $expiresAt) {
            throw new AccessDeniedException('Invalid according to Policy: Policy expired.');
        }

        $maxFileSize = null;
        $conditions = $policy['conditions'] ?? null;
        if (!is_array($conditions) || $conditions === []) {
            throw new InvalidArgumentException('Policy conditions must be a non-empty array.');
        }
        $coveredFields = [];
        foreach ($conditions as $condition) {
            if (!is_array($condition) || $condition === []) {
                throw new InvalidArgumentException('Invalid Policy: empty or invalid condition.');
            }
            if (!array_is_list($condition)) {
                foreach ($condition as $field => $expectedValue) {
                    if (!is_string($field) || !is_scalar($expectedValue)) {
                        throw new InvalidArgumentException('Invalid exact-match policy condition.');
                    }
                    $coveredFields[] = strtolower($field);
                    if (strtolower($field) === 'bucket') {
                        if ($bucket === null) {
                            continue;
                        }
                        $actual = $bucket;
                    } else {
                        $actual = self::value($fields, $field) ?? '';
                    }
                    if ($actual !== (string) $expectedValue) {
                        throw new AccessDeniedException('Invalid according to Policy: exact-match condition failed.');
                    }
                }
                continue;
            }
            if (count($condition) !== 3) {
                throw new InvalidArgumentException('Invalid Policy: condition arrays require 3 elements.');
            }
            if (!is_scalar($condition[0]) || !is_scalar($condition[1]) || !is_scalar($condition[2])) {
                throw new InvalidArgumentException('Invalid Policy: condition values must be scalar.');
            }
            $operator = strtolower((string) $condition[0]);
            if ($operator !== 'content-length-range') {
                if (!in_array($operator, ['eq', 'starts-with'], true)) {
                    throw new InvalidArgumentException('Unsupported POST policy condition operator.');
                }
                $field = ltrim((string) $condition[1], '$');
                if ($field === '') {
                    throw new InvalidArgumentException('POST policy condition field is empty.');
                }
                $coveredFields[] = strtolower($field);
                if (strtolower($field) === 'bucket') {
                    if ($bucket === null) {
                        continue;
                    }
                    $actual = $bucket;
                } else {
                    $actual = self::value($fields, $field) ?? '';
                }
                $expected = (string) $condition[2];
                if (($operator === 'eq' && $actual !== $expected)
                    || ($operator === 'starts-with' && ! PostPolicyConditionMatcher::startsWith($field, $actual, $expected))) {
                    throw new AccessDeniedException('Invalid according to Policy: form field condition failed.');
                }
                continue;
            }
            $min = filter_var($condition[1], FILTER_VALIDATE_INT);
            $max = filter_var($condition[2], FILTER_VALIDATE_INT);
            if ($min === false || $max === false || $min < 0 || $max < $min) {
                throw new InvalidArgumentException('Invalid content-length-range policy condition.');
            }
            $maxFileSize = $maxFileSize === null ? $max : min($maxFileSize, $max);
        }

        foreach ($fields as $field => $_) {
            $field = strtolower($field);
            if (in_array($field, ['policy', 'signature', 'x-amz-signature'], true)
                || str_starts_with($field, 'x-ignore-')) {
                continue;
            }
            if (!in_array($field, $coveredFields, true)) {
                throw new AccessDeniedException("Invalid according to Policy: extra field '{$field}' is not covered by a condition.");
            }
        }

        return $maxFileSize;
    }

    /**
     * @param array<string, string> $fields
     * @return array{Credential|null, array<string, mixed>|null}
     */
    private function authenticate(array $fields): array
    {
        $policyBase64 = self::value($fields, 'policy');
        $v4Algorithm = self::value($fields, 'x-amz-algorithm');
        $v4Credential = self::value($fields, 'x-amz-credential');
        $v2AccessKey = self::value($fields, 'AWSAccessKeyId');

        if ($v4Algorithm !== null || $v4Credential !== null) {
            if ($policyBase64 === null || $policyBase64 === '') {
                throw new InvalidArgumentException('POST requires a policy document when credentials are provided.');
            }

            $credential = $this->authenticateV4($fields, $policyBase64);

            return [$credential, self::decodePolicy($policyBase64)];
        }

        if ($v2AccessKey !== null && $v2AccessKey !== '') {
            if ($policyBase64 === null || $policyBase64 === '') {
                throw new InvalidArgumentException('POST requires a policy document when credentials are provided.');
            }

            $signature = self::value($fields, 'signature');
            if ($signature === null || $signature === '') {
                throw new InvalidArgumentException('POST requires a signature when credentials are provided.');
            }

            $credential = $this->credential($v2AccessKey, self::value($fields, 'x-amz-security-token'));
            $expected = base64_encode(hash_hmac('sha1', $policyBase64, $credential->secretAccessKey, true));
            if (!hash_equals($expected, $signature)) {
                throw new SignatureDoesNotMatchException();
            }

            return [$credential, self::decodePolicy($policyBase64)];
        }

        if ($policyBase64 !== null && $policyBase64 !== '') {
            throw new InvalidArgumentException('POST policy requires authentication.');
        }

        return [null, null];
    }

    /**
     * @param array<string, string> $fields
     */
    private function authenticateV4(array $fields, string $policyBase64): Credential
    {
        $algorithm = self::value($fields, 'x-amz-algorithm');
        $credentialField = self::value($fields, 'x-amz-credential');
        $dateTime = self::value($fields, 'x-amz-date');
        $signature = self::value($fields, 'x-amz-signature');

        if ($algorithm !== 'AWS4-HMAC-SHA256'
            || $credentialField === null
            || $dateTime === null
            || $signature === null) {
            throw new AccessDeniedException('Invalid POST Signature Version 4 fields.');
        }

        $scope = explode('/', $credentialField);
        if (count($scope) !== 5
            || $scope[0] === ''
            || $scope[1] === ''
            || $scope[2] !== $this->region
            || $scope[3] !== 's3'
            || $scope[4] !== 'aws4_request'
            || !self::isValidAmzDate($dateTime)
            || !str_starts_with($dateTime, $scope[1])) {
            throw new AccessDeniedException('Invalid POST credential scope.');
        }
        self::validateV4SigningTime($dateTime);

        $credential = $this->credential($scope[0], self::value($fields, 'x-amz-security-token'));
        $signingKey = SigningKey::derive($credential->secretAccessKey, $scope[1], $scope[2], 's3');
        $expected = hash_hmac('sha256', $policyBase64, $signingKey);
        if (!hash_equals($expected, strtolower($signature))) {
            throw new SignatureDoesNotMatchException();
        }

        return $credential;
    }

    private static function isValidAmzDate(string $dateTime): bool
    {
        if (preg_match('/\A[0-9]{8}T[0-9]{6}Z\z/', $dateTime) !== 1) {
            return false;
        }

        $year = (int) substr($dateTime, 0, 4);
        $month = (int) substr($dateTime, 4, 2);
        $day = (int) substr($dateTime, 6, 2);
        $hour = (int) substr($dateTime, 9, 2);
        $minute = (int) substr($dateTime, 11, 2);
        $second = (int) substr($dateTime, 13, 2);

        return checkdate($month, $day, $year)
            && $hour <= 23
            && $minute <= 59
            && $second <= 59;
    }

    private static function validateV4SigningTime(string $dateTime): void
    {
        $signedAt = gmmktime(
            (int) substr($dateTime, 9, 2),
            (int) substr($dateTime, 11, 2),
            (int) substr($dateTime, 13, 2),
            (int) substr($dateTime, 4, 2),
            (int) substr($dateTime, 6, 2),
            (int) substr($dateTime, 0, 4),
        );
        if ($signedAt === false) {
            throw new AccessDeniedException('Invalid POST credential scope.');
        }

        $now = time();
        if ($signedAt < $now - self::MAX_SIGV4_SIGNING_AGE_SECONDS) {
            throw new AccessDeniedException('POST signing key is older than seven days.');
        }
        if ($signedAt > $now + self::MAX_CLOCK_SKEW_SECONDS) {
            throw new AccessDeniedException('The difference between the request time and the current time is too large.');
        }
    }

    private function credential(string $accessKeyId, ?string $sessionToken): Credential
    {
        $credential = $this->credentialProvider->getCredential($accessKeyId);
        if ($credential === null || !$credential->isActive) {
            throw new InvalidAccessKeyIdException();
        }

        SessionCredentialValidator::validate($credential, $sessionToken);

        return $credential;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodePolicy(string $policyBase64): array
    {
        $decoded = base64_decode($policyBase64, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('POST policy is not valid base64.');
        }

        try {
            $policy = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('POST policy is not valid JSON.');
        }

        if (!is_array($policy)) {
            throw new InvalidArgumentException('POST policy must be a JSON object.');
        }

        return $policy;
    }

    private function prepareRequestForAuthorizationAndWrite(Request $request, PostObjectForm $form): void
    {
        $request->setAttribute('s3.key', $form->key);
        $request->setBody($form->openFile());
        $request->setHeader('content-length', (string) $form->fileSize);

        self::applyFormHeaders($request, $form->fields);
    }

    /** @param array<string, string> $fields */
    public static function applyFormHeaders(Request $request, array $fields): void
    {
        $request->setHeader('content-type', self::value($fields, 'Content-Type') ?? 'application/octet-stream');

        foreach ($fields as $name => $value) {
            $lower = strtolower($name);
            if ($lower === 'acl') {
                $request->setHeader('x-amz-acl', $value);
            } elseif ($lower === 'tagging') {
                $taggingHeader = self::taggingHeader($fields);
                if ($taggingHeader !== null && $taggingHeader !== '') {
                    $request->setHeader('x-amz-tagging', $taggingHeader);
                }
            } elseif ($lower === 'content-md5') {
                $request->setHeader('content-md5', $value);
            } elseif (str_starts_with($lower, 'x-amz-meta-')
                || str_starts_with($lower, 'x-amz-checksum-')
                || str_starts_with($lower, 'x-amz-server-side-encryption')
                || str_starts_with($lower, 'x-amz-object-lock-')
                || $lower === 'x-amz-storage-class'
                || in_array($lower, ['cache-control', 'content-encoding', 'content-disposition', 'expires'], true)) {
                $request->setHeader($lower, $value);
            }
        }
    }

    /**
     * Convert the browser POST Tagging XML field into the query-encoded header
     * representation consumed by the ordinary PutObject authorization and
     * write path. The original form value remains untouched for POST-policy
     * condition matching.
     *
     * @param array<string, string> $fields
     */
    private static function taggingHeader(array $fields): ?string
    {
        $xml = self::value($fields, 'tagging');
        if ($xml === null) {
            return null;
        }

        $tags = ObjectTagValidator::validate(XmlRequestParser::parseTagging($xml), 10);

        return implode('&', array_map(
            static fn(array $tag): string => rawurlencode($tag['key']) . '=' . rawurlencode($tag['value']),
            $tags,
        ));
    }

    private function createTempPath(): string
    {
        $directory = $this->spoolCoordinator->tempDir();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $path = $directory . DIRECTORY_SEPARATOR . 's3-post-' . bin2hex(random_bytes(24)) . '.tmp';
            if (@lstat($path) === false) {
                return $path;
            }
        }

        throw new \RuntimeException('Cannot allocate browser POST temporary path.');
    }

    private static function declaredRequestBodyLength(Request $request): ?int
    {
        $value = RequestHeaderValues::singleton($request, 'content-length');
        if ($value === null) {
            return null;
        }
        if ($value === '' || ! ctype_digit($value)) {
            throw new InvalidArgumentException('Content-Length must be a non-negative decimal integer.');
        }
        $normalized = ltrim($value, '0');
        $maximum = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            throw new InvalidArgumentException('Content-Length exceeds the local integer range.');
        }

        return (int) $value;
    }

    private static function safeFilename(StreamedField $field): string
    {
        $filename = str_replace('\\', '/', $field->getFilename() ?? '');
        $filename = basename($filename);

        return $filename !== '' ? $filename : 'file';
    }

    /**
     * @param array<string, string> $fields
     */
    private static function value(array $fields, string $name): ?string
    {
        $name = strtolower($name);
        foreach ($fields as $fieldName => $value) {
            if (strtolower($fieldName) === $name) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $fields
     */
    private static function fieldExists(array $fields, string $name): bool
    {
        return self::value($fields, $name) !== null;
    }

    /**
     * @param array<string, string> $fields
     */
    private static function setValue(array &$fields, string $name, string $value): void
    {
        foreach ($fields as $fieldName => $_) {
            if (strtolower($fieldName) === strtolower($name)) {
                $fields[$fieldName] = $value;

                return;
            }
        }

        $fields[$name] = $value;
    }
}
