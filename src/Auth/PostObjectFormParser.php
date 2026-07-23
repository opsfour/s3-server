<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use Amp\Http\Server\FormParser\StreamedField;
use Amp\Http\Server\FormParser\StreamingFormParser;
use Amp\Http\Server\Request;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\InvalidAccessKeyIdException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\SignatureDoesNotMatchException;

/**
 * Streams an S3 POST Object form to a temporary file and authenticates it.
 */
final class PostObjectFormParser
{
    private const int FIELD_SIZE_LIMIT = 65_536;

    public function __construct(
        private readonly CredentialProvider $credentialProvider,
        private readonly string $region,
        private readonly int $bodySizeLimit,
    ) {}

    public function parse(Request $request): PostObjectForm
    {
        $contentType = $request->getHeader('content-type') ?? '';
        if (!str_starts_with(strtolower($contentType), 'multipart/form-data')) {
            throw new InvalidArgumentException('POST Object requires multipart/form-data.');
        }

        $fields = [];
        $filePath = null;
        $filename = null;
        $fileSize = 0;
        $seenFile = false;

        try {
            $parser = new StreamingFormParser(fieldCountLimit: 100);
            foreach ($parser->parseForm($request, $this->bodySizeLimit) as $field) {
                if ($field->isFile()) {
                    if ($seenFile || strtolower($field->getName()) !== 'file') {
                        throw new InvalidArgumentException('POST Object requires exactly one file field named "file".');
                    }

                    $seenFile = true;
                    $filename = self::safeFilename($field);
                    $filePath = $this->createTempPath();
                    $file = \Amp\File\openFile($filePath, 'x');
                    try {
                        while (($chunk = $field->read()) !== null) {
                            $fileSize += strlen($chunk);
                            $file->write($chunk);
                        }
                    } finally {
                        $file->close();
                    }

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
            }

            if ($filePath === null || $filename === null) {
                throw new InvalidArgumentException('POST Object requires a file field.');
            }

            $keyField = self::value($fields, 'key');
            if ($keyField === null || $keyField === '') {
                throw new InvalidArgumentException('Bucket POST must contain a field named "key".');
            }

            $key = str_replace('${filename}', $filename, $keyField);
            self::setValue($fields, 'key', $key);

            [$credential, $policy] = $this->authenticate($fields);

            $form = new PostObjectForm(
                fields: $fields,
                key: $key,
                filename: $filename,
                filePath: $filePath,
                fileSize: $fileSize,
                credential: $credential,
                policy: $policy,
            );

            $this->prepareRequestForAuthorizationAndWrite($request, $form);

            return $form;
        } catch (\Throwable $e) {
            if ($filePath !== null) {
                try {
                    \Amp\File\deleteFile($filePath);
                } catch (\Throwable) {
                }
            }

            throw $e;
        }
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
            || !preg_match('/^\d{8}T\d{6}Z$/', $dateTime)
            || !str_starts_with($dateTime, $scope[1])) {
            throw new AccessDeniedException('Invalid POST credential scope.');
        }

        $credential = $this->credential($scope[0], self::value($fields, 'x-amz-security-token'));
        $signingKey = SigningKey::derive($credential->secretAccessKey, $scope[1], $scope[2], 's3');
        $expected = hash_hmac('sha256', $policyBase64, $signingKey);
        if (!hash_equals($expected, strtolower($signature))) {
            throw new SignatureDoesNotMatchException();
        }

        return $credential;
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

        $contentType = $form->value('Content-Type') ?? 'application/octet-stream';
        $request->setHeader('content-type', $contentType);

        foreach ($form->fields as $name => $value) {
            $lower = strtolower($name);
            if ($lower === 'acl') {
                $request->setHeader('x-amz-acl', $value);
            } elseif ($lower === 'tagging') {
                $request->setHeader('x-amz-tagging', $value);
            } elseif (str_starts_with($lower, 'x-amz-meta-')
                || str_starts_with($lower, 'x-amz-checksum-')
                || str_starts_with($lower, 'x-amz-server-side-encryption')
                || in_array($lower, ['cache-control', 'content-encoding', 'content-disposition', 'expires'], true)) {
                $request->setHeader($lower, $value);
            }
        }
    }

    private function createTempPath(): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 's3-post-' . bin2hex(random_bytes(16)) . '.tmp';
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
