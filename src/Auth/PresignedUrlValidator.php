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
 * Validates AWS SigV4 presigned URL authentication.
 *
 * Presigned URLs carry all authentication parameters in the query string
 * rather than the Authorization header. This validator extracts those
 * parameters, verifies expiry, builds the canonical request (excluding
 * X-Amz-Signature), and performs the same signing verification as
 * header-based SigV4.
 *
 * @see https://docs.aws.amazon.com/AmazonS3/latest/API/sigv4-query-string-auth.html
 */
final class PresignedUrlValidator
{
    /**
     * Maximum presigned URL expiry: 7 days (604800 seconds).
     */
    private const int MAX_EXPIRES_SECONDS = 604800;

    /**
     * Validate a presigned URL request.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @param  CredentialProvider  $credentialProvider  Provider to look up credentials.
     * @param  string  $region  The expected AWS region.
     * @return AuthResult The authentication result on success.
     *
     * @throws S3Exception On any authentication failure.
     */
    public function validate(Request $request, CredentialProvider $credentialProvider, string $region): AuthResult
    {
        // 1. Extract and validate all required presign query parameters.
        $params = self::extractPresignParams($request);

        // 2. Validate algorithm.
        if ($params['algorithm'] !== 'AWS4-HMAC-SHA256') {
            throw new AuthorizationHeaderMalformedException(
                sprintf(
                    'Unsupported authorization algorithm: %s. Expected AWS4-HMAC-SHA256.',
                    $params['algorithm'],
                ),
            );
        }

        // 3. Parse the credential scope.
        $credentialParts = explode('/', $params['credential']);
        if (count($credentialParts) !== 5) {
            throw new AuthorizationHeaderMalformedException(
                'Invalid credential format. Expected: accessKeyId/date/region/s3/aws4_request.',
            );
        }

        [$accessKeyId, $date, $credRegion, $service, $requestType] = $credentialParts;

        // Validate that X-Amz-Date date portion matches credential scope date.
        $amzDatePortion = substr($params['date'], 0, 8);
        if ($amzDatePortion !== $date) {
            throw new AuthorizationHeaderMalformedException(
                'X-Amz-Date date portion does not match credential scope date.',
            );
        }

        if ($service !== 's3' || $requestType !== 'aws4_request') {
            throw new AuthorizationHeaderMalformedException(
                'Invalid credential scope. Service must be "s3" and request type must be "aws4_request".',
            );
        }

        // 4. Validate the region.
        if ($credRegion !== $region) {
            throw new AuthorizationHeaderMalformedException(
                sprintf(
                    'The authorization header is malformed; the region \'%s\' is wrong; expecting \'%s\'.',
                    $credRegion,
                    $region,
                ),
            );
        }

        // 5. Look up the credential.
        $credential = $credentialProvider->getCredential($accessKeyId);
        if ($credential === null || ! $credential->isActive) {
            throw new InvalidAccessKeyIdException(
                'The AWS Access Key Id you provided does not exist in our records.',
            );
        }
        SessionCredentialValidator::validate($credential, $request->getQueryParameter('X-Amz-Security-Token'));

        // 6. Validate expiry.
        $expires = (int) $params['expires'];
        if ($expires < 1 || $expires > self::MAX_EXPIRES_SECONDS) {
            throw new AccessDeniedException(
                sprintf(
                    'X-Amz-Expires must be between 1 and %d seconds. Got: %d.',
                    self::MAX_EXPIRES_SECONDS,
                    $expires,
                ),
            );
        }

        self::validateExpiry($params['date'], $expires);

        // 7. Build the canonical query string, excluding X-Amz-Signature.
        $queryStringWithoutSignature = self::buildQueryStringWithoutSignature($request);

        // 8. Build the canonical request.
        // For presigned URLs, the hashed payload is always "UNSIGNED-PAYLOAD".
        $signedHeaders = explode(';', $params['signedHeaders']);
        sort($signedHeaders);

        // AWS requires 'host' to always be signed — reject if missing.
        if (!in_array('host', $signedHeaders, true)) {
            throw new AccessDeniedException(
                'Presigned URL requires "host" to be a signed header.',
            );
        }

        $canonicalRequest = CanonicalRequest::build(
            method: $request->getMethod(),
            uri: $request->getUri()->getPath(),
            queryString: $queryStringWithoutSignature,
            headers: self::extractHeaders($request),
            signedHeaders: $signedHeaders,
            hashedPayload: 'UNSIGNED-PAYLOAD',
        );

        // 9. Build the string to sign.
        $credentialScope = sprintf('%s/%s/s3/aws4_request', $date, $region);
        $stringToSign = SignatureV4Verifier::buildStringToSign(
            $params['date'],
            $credentialScope,
            $canonicalRequest,
        );

        // 10. Derive the signing key.
        $signingKey = SigningKey::derive($credential->secretAccessKey, $date, $region, 's3');

        // 11. Compute the expected signature.
        $expectedSignature = hash_hmac('sha256', $stringToSign, $signingKey);

        // 12. Constant-time comparison.
        if (! hash_equals($expectedSignature, $params['signature'])) {
            throw new SignatureDoesNotMatchException(
                'The request signature we calculated does not match the signature you provided. '
                .'Check your key and signing method.',
            );
        }

        return new AuthResult(
            credential: $credential,
            ownerId: $credential->ownerId,
            signedHeaders: $signedHeaders,
        );
    }

    /**
     * Extract all required presign query parameters.
     *
     * @return array{algorithm: string, credential: string, date: string, expires: string, signedHeaders: string, signature: string}
     *
     * @throws S3Exception If any required parameter is missing.
     */
    private static function extractPresignParams(Request $request): array
    {
        $required = [
            'X-Amz-Algorithm' => 'algorithm',
            'X-Amz-Credential' => 'credential',
            'X-Amz-Date' => 'date',
            'X-Amz-Expires' => 'expires',
            'X-Amz-SignedHeaders' => 'signedHeaders',
            'X-Amz-Signature' => 'signature',
        ];

        $params = [];

        foreach ($required as $queryParam => $key) {
            $value = $request->getQueryParameter($queryParam);
            if ($value === null || $value === '') {
                throw new AuthorizationHeaderMalformedException(
                    sprintf('Missing required query parameter: %s.', $queryParam),
                );
            }
            $params[$key] = $value;
        }

        return $params; // @phpstan-ignore return.type
    }

    /**
     * Validate that the presigned URL has not expired.
     *
     * @param  string  $amzDate  The X-Amz-Date in ISO 8601 basic format.
     * @param  int  $expires  The X-Amz-Expires value in seconds.
     *
     * @throws S3Exception If the presigned URL has expired.
     */
    private static function validateExpiry(string $amzDate, int $expires): void
    {
        if (strlen($amzDate) !== 16 || $amzDate[8] !== 'T' || $amzDate[15] !== 'Z') {
            throw new AccessDeniedException(
                'Invalid X-Amz-Date format. Expected ISO 8601 basic format (YYYYMMDD\'T\'HHMMSS\'Z\').',
            );
        }

        $year  = (int) substr($amzDate, 0, 4);
        $month = (int) substr($amzDate, 4, 2);
        $day   = (int) substr($amzDate, 6, 2);
        $hour  = (int) substr($amzDate, 9, 2);
        $min   = (int) substr($amzDate, 11, 2);
        $sec   = (int) substr($amzDate, 13, 2);

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $hour > 23 || $min > 59 || $sec > 59) {
            throw new AccessDeniedException(
                'Invalid date/time components in X-Amz-Date.',
            );
        }

        $signTime = gmmktime($hour, $min, $sec, $month, $day, $year);

        if ($signTime === false) {
            throw new AccessDeniedException(
                'Invalid X-Amz-Date format. Expected ISO 8601 basic format (YYYYMMDD\'T\'HHMMSS\'Z\').',
            );
        }

        $expiryTime = $signTime + $expires;
        $now = time();

        if ($now > $expiryTime) {
            throw new AccessDeniedException('Request has expired.');
        }

        // Also check if the sign time is too far in the future (clock skew).
        if ($signTime > $now + 900) {
            throw new AccessDeniedException(
                'The difference between the request time and the current time is too large.',
            );
        }
    }

    /**
     * Build the canonical query string excluding the X-Amz-Signature parameter.
     *
     * The original raw query string is reconstructed without X-Amz-Signature,
     * since the signature itself is not included in the canonical request
     * that was signed.
     */
    private static function buildQueryStringWithoutSignature(Request $request): string
    {
        $rawQuery = $request->getUri()->getQuery();
        if ($rawQuery === '') {
            return '';
        }

        $filteredParts = [];

        foreach (explode('&', $rawQuery) as $param) {
            if ($param === '') {
                continue;
            }

            // Exclude X-Amz-Signature parameter.
            $eqPos = strpos($param, '=');
            $key = $eqPos === false ? $param : substr($param, 0, $eqPos);

            if (rawurldecode($key) !== 'X-Amz-Signature') {
                $filteredParts[] = $param;
            }
        }

        return implode('&', $filteredParts);
    }

    /**
     * Extract headers from the request in canonical format.
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
}
