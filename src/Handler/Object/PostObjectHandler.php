<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Auth\PostObjectForm;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Metadata\MetadataStore;

/**
 * Handles browser-style POST Object after streaming form authentication.
 *
 * The actual write is delegated to PutObjectHandler so form uploads receive
 * the same versioning, quota, encryption, ACL, checksum, cleanup, and
 * notification behavior as regular PutObject requests.
 */
final class PostObjectHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly PutObjectHandler $putObject,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        if (!$request->hasAttribute(PostObjectForm::class)) {
            throw new InvalidArgumentException('POST Object form was not parsed.');
        }

        $form = $request->getAttribute(PostObjectForm::class);
        if (!$form instanceof PostObjectForm) {
            throw new InvalidArgumentException('POST Object form is invalid.');
        }

        if ($form->policy !== null) {
            $conditionError = $this->validatePolicyConditions($form, $bucket);
            if ($conditionError !== null) {
                return $conditionError;
            }
        }

        // Anonymous writes are authorized by the bucket WRITE ACL. Objects
        // created this way belong to the bucket owner.
        $ownerId = $request->hasAttribute('ownerId') ? $request->getAttribute('ownerId') : '';
        if ($ownerId === '') {
            $request->setAttribute('ownerId', $bucketInfo->ownerId);
        }

        $putResponse = $this->putObject->handleRequest($request);
        $etag = $putResponse->getHeader('etag') ?? '""';

        $successRedirect = $form->value('success_action_redirect');
        if ($successRedirect !== null && $successRedirect !== '') {
            if (str_contains($successRedirect, "\r") || str_contains($successRedirect, "\n")) {
                throw new InvalidArgumentException('success_action_redirect contains invalid characters.');
            }

            $scheme = parse_url($successRedirect, PHP_URL_SCHEME);
            if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
                throw new InvalidArgumentException('success_action_redirect must use HTTP or HTTPS.');
            }

            $separator = str_contains($successRedirect, '?') ? '&' : '?';
            $redirectUrl = $successRedirect . $separator
                . 'bucket=' . rawurlencode($bucket)
                . '&key=' . rawurlencode($form->key)
                . '&etag=' . rawurlencode($etag);

            return new Response(
                status: 303,
                headers: [
                    'Location' => $redirectUrl,
                    'ETag' => $etag,
                ],
            );
        }

        $status = $this->resolveSuccessStatus($form->value('success_action_status'));
        $headers = $putResponse->getHeaders();

        if ($status === 201) {
            $host = $request->getHeader('host') ?? 'localhost';
            $scheme = $request->getClient()->getTlsInfo() !== null ? 'https' : 'http';
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<PostResponse>'
                . '<Location>' . $scheme . '://' . htmlspecialchars($host, ENT_XML1, 'UTF-8')
                . '/' . rawurlencode($bucket) . '/' . rawurlencode($form->key) . '</Location>'
                . '<Bucket>' . htmlspecialchars($bucket, ENT_XML1, 'UTF-8') . '</Bucket>'
                . '<Key>' . htmlspecialchars($form->key, ENT_XML1, 'UTF-8') . '</Key>'
                . '<ETag>' . htmlspecialchars($etag, ENT_XML1, 'UTF-8') . '</ETag>'
                . '</PostResponse>';
            $headers['content-type'] = ['application/xml'];

            return new Response(status: 201, headers: $headers, body: $xml);
        }

        return new Response(status: $status, headers: $headers);
    }

    private function validatePolicyConditions(PostObjectForm $form, string $bucket): ?Response
    {
        $policy = $form->policy;
        if ($policy === null) {
            return null;
        }

        $expiration = $policy['expiration'] ?? null;
        if (!is_string($expiration)) {
            return $this->errorResponse(400, 'InvalidArgument', 'Policy must contain a string expiration.');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $expiration)) {
            return $this->errorResponse(400, 'InvalidArgument', 'Invalid Policy: invalid expiration date format.');
        }

        try {
            $expiresAt = new \DateTimeImmutable($expiration, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return $this->errorResponse(400, 'InvalidArgument', 'Invalid expiration date format in policy.');
        }

        if (new \DateTimeImmutable('now', new \DateTimeZone('UTC')) > $expiresAt) {
            return $this->errorResponse(403, 'AccessDenied', 'Invalid according to Policy: Policy expired.');
        }

        $conditions = $policy['conditions'] ?? null;
        if (!is_array($conditions) || $conditions === []) {
            return $this->errorResponse(400, 'InvalidArgument', 'Policy conditions must be a non-empty array.');
        }

        $coveredFields = [];
        foreach ($conditions as $condition) {
            if (!is_array($condition) || $condition === []) {
                return $this->errorResponse(400, 'InvalidArgument', 'Invalid Policy: empty or invalid condition.');
            }

            if (!array_is_list($condition)) {
                foreach ($condition as $field => $expectedValue) {
                    if (!is_string($field) || !is_scalar($expectedValue)) {
                        return $this->errorResponse(400, 'InvalidArgument', 'Invalid exact-match policy condition.');
                    }

                    $coveredFields[] = strtolower($field);
                    $expected = (string) $expectedValue;
                    if ($this->fieldValue($form, $field, $bucket) !== $expected) {
                        return $this->policyConditionFailure('eq', $field, $expected);
                    }
                }

                continue;
            }

            if (count($condition) !== 3) {
                return $this->errorResponse(400, 'InvalidArgument', 'Invalid Policy: condition arrays require 3 elements.');
            }

            $operator = strtolower((string) $condition[0]);
            if ($operator === 'content-length-range') {
                $min = filter_var($condition[1], FILTER_VALIDATE_INT);
                $max = filter_var($condition[2], FILTER_VALIDATE_INT);
                if ($min === false || $max === false || $min < 0 || $max < $min) {
                    return $this->errorResponse(400, 'InvalidArgument', 'Invalid content-length-range policy condition.');
                }
                if ($form->fileSize < $min) {
                    return $this->errorResponse(400, 'EntityTooSmall', 'Upload is smaller than the policy minimum.');
                }
                if ($form->fileSize > $max) {
                    return $this->errorResponse(400, 'EntityTooLarge', 'Upload exceeds the policy maximum.');
                }

                continue;
            }

            if (!in_array($operator, ['eq', 'starts-with'], true)) {
                return $this->errorResponse(400, 'InvalidArgument', 'Unsupported POST policy condition operator.');
            }

            $field = ltrim((string) $condition[1], '$');
            if ($field === '') {
                return $this->errorResponse(400, 'InvalidArgument', 'POST policy condition field is empty.');
            }
            $coveredFields[] = strtolower($field);
            $expected = (string) $condition[2];
            $actual = $this->fieldValue($form, $field, $bucket);

            if ($operator === 'eq' && $actual !== $expected) {
                return $this->policyConditionFailure($operator, $field, $expected);
            }
            if ($operator === 'starts-with' && !str_starts_with($actual, $expected)) {
                return $this->policyConditionFailure($operator, $field, $expected);
            }
        }

        foreach ($form->fields as $field => $_) {
            $field = strtolower($field);
            if (in_array($field, ['policy', 'signature', 'x-amz-signature'], true)
                || str_starts_with($field, 'x-ignore-')) {
                continue;
            }
            if (!in_array($field, $coveredFields, true)) {
                return $this->errorResponse(
                    403,
                    'AccessDenied',
                    "Invalid according to Policy: extra field '{$field}' is not covered by a condition.",
                );
            }
        }

        return null;
    }

    private function fieldValue(PostObjectForm $form, string $field, string $bucket): string
    {
        return strtolower($field) === 'bucket'
            ? $bucket
            : ($form->value($field) ?? '');
    }

    private function policyConditionFailure(string $operator, string $field, string $expected): Response
    {
        return $this->errorResponse(
            403,
            'AccessDenied',
            "Invalid according to Policy: [\"{$operator}\", \"\${$field}\", \"{$expected}\"]",
        );
    }

    private function resolveSuccessStatus(?string $successStatus): int
    {
        return match ($successStatus) {
            '200' => 200,
            '201' => 201,
            default => 204,
        };
    }

    private function errorResponse(int $status, string $code, string $message): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<Error>'
            . '<Code>' . htmlspecialchars($code, ENT_XML1, 'UTF-8') . '</Code>'
            . '<Message>' . htmlspecialchars($message, ENT_XML1, 'UTF-8') . '</Message>'
            . '</Error>';

        return new Response(
            status: $status,
            headers: ['Content-Type' => 'application/xml'],
            body: $xml,
        );
    }
}
