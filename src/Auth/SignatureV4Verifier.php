<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\AuthorizationHeaderMalformedException;
use OpsFour\S3Server\Exception\InvalidAccessKeyIdException;
use OpsFour\S3Server\Exception\S3Exception;
use OpsFour\S3Server\Exception\SignatureDoesNotMatchException;

/**
 * Verifies AWS Signature Version 4 (header-based) authentication.
 *
 * Parses the Authorization header, resolves the credential, builds the
 * canonical request, computes the expected signature using the HMAC-SHA256
 * signing key chain, and performs constant-time comparison.
 *
 * @see https://docs.aws.amazon.com/AmazonS3/latest/API/sig-v4-header-based-auth.html
 */
final class SignatureV4Verifier
{
    /**
     * Maximum allowed clock skew in seconds (15 minutes).
     */
    private const int MAX_CLOCK_SKEW_SECONDS = 900;

    /**
     * Regex pattern for parsing the Authorization header.
     *
     * Format: AWS4-HMAC-SHA256 Credential={accessKeyId}/{date}/{region}/s3/aws4_request,
     *         SignedHeaders={signed-headers}, Signature={signature}
     */
    private const string AUTH_HEADER_PATTERN = '/^AWS4-HMAC-SHA256\s+'
        . 'Credential=(?P<accessKeyId>[A-Za-z0-9\/\-_]+)\/(?P<date>\d{8})\/(?P<region>[a-zA-Z0-9\-]+)\/(?P<service>[a-zA-Z0-9\-]+)\/aws4_request,\s*'
        . 'SignedHeaders=(?P<signedHeaders>[a-z0-9;.\-_]+),\s*'
        . 'Signature=(?P<signature>[a-f0-9]{64})$/';

    /**
     * Verify an AWS SigV4 header-based authentication request.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @param  CredentialProvider  $credentialProvider  Provider to look up credentials.
     * @param  string  $region  The expected AWS region.
     * @return AuthResult The authentication result on success.
     *
     * @throws S3Exception On any authentication failure.
     */
    public function verify(Request $request, CredentialProvider $credentialProvider, string $region): AuthResult
    {
        // 1. Parse the Authorization header.
        $authHeader = $request->getHeader('authorization');
        if ($authHeader === null) {
            throw new AccessDeniedException('Missing Authorization header.');
        }

        $parsed = self::parseAuthorizationHeader($authHeader);

        // 2. Look up the credential.
        $credential = $credentialProvider->getCredential($parsed['accessKeyId']);
        if ($credential === null || ! $credential->isActive) {
            throw new InvalidAccessKeyIdException(
                'The AWS Access Key Id you provided does not exist in our records.',
            );
        }
        SessionCredentialValidator::validate($credential, $request->getHeader('x-amz-security-token'));

        // 3. Validate the service field is 's3'.
        if ($parsed['service'] !== 's3') {
            throw new AuthorizationHeaderMalformedException(
                sprintf(
                    'The authorization header is malformed; the service \'%s\' is wrong; expecting \'s3\'.',
                    $parsed['service'],
                ),
            );
        }

        // 4. Validate the region in the credential scope matches expected region.
        if ($parsed['region'] !== $region) {
            throw new AuthorizationHeaderMalformedException(
                sprintf(
                    'The authorization header is malformed; the region \'%s\' is wrong; expecting \'%s\'.',
                    $parsed['region'],
                    $region,
                ),
            );
        }

        // 4. Get the request timestamp and check clock skew.
        $timestamp = self::extractTimestamp($request);
        self::validateClockSkew($timestamp);

        // 5. Get hashed payload from x-amz-content-sha256 header.
        $hashedPayload = $request->getHeader('x-amz-content-sha256') ?? 'UNSIGNED-PAYLOAD';

        // 6. Build the canonical request.
        $signedHeaders = explode(';', $parsed['signedHeaders']);
        sort($signedHeaders);

        // AWS requires 'host' to always be signed — reject if missing.
        if (!in_array('host', $signedHeaders, true)) {
            throw new AuthorizationHeaderMalformedException(
                'Authorization header requires "host" to be a signed header.',
            );
        }

        $canonicalRequest = CanonicalRequest::build(
            method: $request->getMethod(),
            uri: $request->getUri()->getPath(),
            queryString: $request->getUri()->getQuery(),
            headers: self::extractHeaders($request),
            signedHeaders: $signedHeaders,
            hashedPayload: $hashedPayload,
        );

        // 7. Validate that credential scope date matches x-amz-date date.
        $timestampDate = substr($timestamp, 0, 8);
        if ($timestampDate !== $parsed['date']) {
            throw new AuthorizationHeaderMalformedException(
                'The date in the credential scope does not match the x-amz-date.',
            );
        }

        // 8. Build the string to sign.
        $credentialScope = sprintf('%s/%s/s3/aws4_request', $parsed['date'], $region);
        $stringToSign = self::buildStringToSign($timestamp, $credentialScope, $canonicalRequest);

        // 8. Derive the signing key.
        $signingKey = SigningKey::derive($credential->secretAccessKey, $parsed['date'], $region, 's3');

        // 9. Compute the expected signature.
        $expectedSignature = hash_hmac('sha256', $stringToSign, $signingKey);

        // 10. Constant-time comparison.
        if (! hash_equals($expectedSignature, $parsed['signature'])) {
            throw new SignatureDoesNotMatchException(
                'The request signature we calculated does not match the signature you provided. '
                . 'Check your key and signing method.',
            );
        }

        return new AuthResult(
            credential: $credential,
            ownerId: $credential->ownerId,
            signedHeaders: $signedHeaders,
            signature: $parsed['signature'],
            credentialDate: $parsed['date'],
            credentialRegion: $parsed['region'],
        );
    }

    /**
     * Parse the AWS4-HMAC-SHA256 Authorization header.
     *
     * @return array{accessKeyId: string, date: string, region: string, service: string, signedHeaders: string, signature: string}
     *
     * @throws S3Exception If the header format is invalid.
     */
    private static function parseAuthorizationHeader(string $header): array
    {
        if (! preg_match(self::AUTH_HEADER_PATTERN, $header, $matches)) {
            throw new AuthorizationHeaderMalformedException(
                'The authorization header is malformed; it must contain Credential, SignedHeaders, and Signature.',
            );
        }

        return [
            'accessKeyId' => $matches['accessKeyId'],
            'date' => $matches['date'],
            'region' => $matches['region'],
            'service' => $matches['service'],
            'signedHeaders' => $matches['signedHeaders'],
            'signature' => $matches['signature'],
        ];
    }

    /**
     * Extract the request timestamp from x-amz-date or Date header.
     *
     * @return string The timestamp in ISO 8601 basic format (YYYYMMDD'T'HHMMSS'Z').
     *
     * @throws S3Exception If no valid timestamp is found.
     */
    private static function extractTimestamp(Request $request): string
    {
        $timestamp = $request->getHeader('x-amz-date');

        if ($timestamp !== null) {
            return $timestamp;
        }

        $dateHeader = $request->getHeader('date');
        if ($dateHeader !== null) {
            // Convert RFC 7231 date to ISO 8601 basic format.
            $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC7231, $dateHeader);
            if ($dt !== false) {
                return $dt->format('Ymd\THis\Z');
            }

            // Try RFC 2822 format as fallback.
            $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC2822, $dateHeader);
            if ($dt !== false) {
                return $dt->format('Ymd\THis\Z');
            }
        }

        throw new AccessDeniedException(
            'AWS authentication requires a valid Date or x-amz-date header.',
        );
    }

    /**
     * Validate that the request timestamp is within the allowed clock skew.
     *
     * @throws S3Exception If the timestamp is more than 15 minutes from server time.
     */
    private static function validateClockSkew(string $timestamp): void
    {
        if (strlen($timestamp) !== 16 || $timestamp[8] !== 'T' || $timestamp[15] !== 'Z') {
            throw new AccessDeniedException(
                'Invalid date format in request. Expected ISO 8601 basic format (YYYYMMDD\'T\'HHMMSS\'Z\').',
            );
        }

        $year  = (int) substr($timestamp, 0, 4);
        $month = (int) substr($timestamp, 4, 2);
        $day   = (int) substr($timestamp, 6, 2);
        $hour  = (int) substr($timestamp, 9, 2);
        $min   = (int) substr($timestamp, 11, 2);
        $sec   = (int) substr($timestamp, 13, 2);

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $hour > 23 || $min > 59 || $sec > 59) {
            throw new AccessDeniedException(
                'Invalid date/time components in request.',
            );
        }

        $requestTime = gmmktime($hour, $min, $sec, $month, $day, $year);

        if ($requestTime === false) {
            throw new AccessDeniedException(
                'Invalid date format in request. Expected ISO 8601 basic format (YYYYMMDD\'T\'HHMMSS\'Z\').',
            );
        }

        $diff = abs(time() - $requestTime);

        if ($diff > self::MAX_CLOCK_SKEW_SECONDS) {
            throw new AccessDeniedException(
                sprintf(
                    'The difference between the request time and the current time is too large. '
                    . 'Server time: %s, Request time: %s.',
                    gmdate('Ymd\THis\Z'),
                    $timestamp,
                ),
            );
        }
    }

    /**
     * Extract headers from the request in the format expected by CanonicalRequest.
     *
     * Returns headers as lowercase-name => list of values.
     *
     * @return array<string, list<string>>
     */
    private static function extractHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $lcName = strtolower($name);
            $headers[$lcName] = $values;
        }

        return $headers;
    }

    /**
     * Build the string to sign for AWS SigV4.
     *
     * Format:
     *   AWS4-HMAC-SHA256\n
     *   {timestamp}\n
     *   {credentialScope}\n
     *   {sha256(canonicalRequest)}
     */
    public static function buildStringToSign(string $timestamp, string $credentialScope, string $canonicalRequest): string
    {
        return implode("\n", [
            'AWS4-HMAC-SHA256',
            $timestamp,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);
    }
}
