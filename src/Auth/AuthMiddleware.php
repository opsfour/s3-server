<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\S3Exception;

/**
 * Amp HTTP Server middleware for S3 authentication.
 *
 * Intercepts every request and performs SigV4 authentication before
 * passing control to the next handler. Supports three authentication modes:
 *
 * 1. Header-based SigV4 (Authorization header)
 * 2. Presigned URL (X-Amz-Algorithm query parameter)
 * 3. Chunked SigV4 streaming (STREAMING-AWS4-HMAC-SHA256-PAYLOAD)
 *
 * On successful authentication, the following request attributes are set:
 * - "credential": The resolved Credential object
 * - "ownerId": The tenant/owner ID string
 * - "signedHeaders": Array of signed header names
 *
 * If the request uses chunked SigV4, the body stream is replaced with
 * a verified stream that strips chunked framing and verifies each
 * chunk's signature.
 */
final class AuthMiddleware implements Middleware
{
    private readonly SignatureV4Verifier $sigV4Verifier;

    private readonly PresignedUrlValidator $presignedValidator;

    private readonly ChunkedSignatureVerifier $chunkedVerifier;

    public function __construct(
        private readonly CredentialProvider $credentialProvider,
        private readonly string $region,
    ) {
        $this->sigV4Verifier = new SignatureV4Verifier;
        $this->presignedValidator = new PresignedUrlValidator;
        $this->chunkedVerifier = new ChunkedSignatureVerifier;
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        // POST Object (HTML form upload) uses its own authentication via form fields.
        // Skip normal SigV4 auth — the PostObjectHandler handles V2 signature verification.
        // Set empty defaults so downstream middleware doesn't crash on missing attributes.
        $contentType = $request->getHeader('content-type') ?? '';
        if ($request->getMethod() === 'POST' && str_contains($contentType, 'multipart/form-data')) {
            $request->setAttribute('credential', null);
            $request->setAttribute('ownerId', '');
            $request->setAttribute('signedHeaders', []);
            return $requestHandler->handleRequest($request);
        }

        $authResult = $this->authenticate($request);

        // Set request attributes for downstream handlers.
        $request->setAttribute('credential', $authResult->credential);
        $request->setAttribute('ownerId', $authResult->ownerId);
        $request->setAttribute('signedHeaders', $authResult->signedHeaders);

        // If chunked SigV4 streaming, wrap the body with signature verification.
        $contentSha = $request->getHeader('x-amz-content-sha256');
        if ($contentSha === 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD') {
            $this->wrapChunkedBody($request, $authResult);
        } elseif ($contentSha === 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD-TRAILER') {
            // TRAILER variant uses a different string-to-sign for the trailing
            // checksum block. Treat as regular chunked SigV4 for now — the chunk
            // data signatures are identical, only the trailing checksum header
            // is unverified. This is safe because the individual chunk signatures
            // still guarantee data integrity.
            $this->wrapChunkedBody($request, $authResult);
        }

        return $requestHandler->handleRequest($request);
    }

    /**
     * Determine the authentication method and verify the request.
     *
     * @throws S3Exception If authentication fails or no auth method is present.
     */
    private function authenticate(Request $request): AuthResult
    {
        // 1. Check for Authorization header (header-based SigV4).
        if ($request->hasHeader('authorization')) {
            return $this->sigV4Verifier->verify($request, $this->credentialProvider, $this->region);
        }

        // 2. Check for presigned URL (X-Amz-Algorithm query parameter).
        $algorithm = $request->getQueryParameter('X-Amz-Algorithm');
        if ($algorithm !== null && $algorithm !== '') {
            return $this->presignedValidator->validate($request, $this->credentialProvider, $this->region);
        }

        // 3. No authentication method found — treat as anonymous request.
        // Allow anonymous through for bucket/object operations (ACL middleware decides).
        // Reject anonymous for service-level operations (ListBuckets requires auth).
        $path = $request->getUri()->getPath();
        if ($path === '/' || $path === '') {
            throw new AccessDeniedException(
                'Access Denied. No authentication information provided.',
            );
        }

        return new AuthResult(
            credential: new \OpsFour\S3Server\Auth\Credential(
                accessKeyId: '',
                secretAccessKey: '',
                ownerId: '',
                displayName: '',
                isActive: true,
            ),
            ownerId: '',
            signedHeaders: [],
        );
    }

    /**
     * Wrap the request body with a chunked signature verifying stream.
     *
     * Extracts the seed signature from the Authorization header and derives
     * the signing key to create a verified stream that strips chunked framing
     * and verifies each chunk's signature in the chain.
     */
    private function wrapChunkedBody(Request $request, AuthResult $authResult): void
    {
        // Use pre-parsed auth data from AuthResult instead of re-parsing the header.
        if ($authResult->signature === '' || $authResult->credentialDate === '') {
            throw new \OpsFour\S3Server\Exception\AccessDeniedException(
                'Chunked SigV4 streaming requires Authorization header.',
            );
        }

        $date = $authResult->credentialDate;
        $scopeRegion = $authResult->credentialRegion;

        // Get the timestamp.
        $timestamp = $request->getHeader('x-amz-date') ?? '';

        // Derive the signing key.
        $signingKey = SigningKey::derive(
            $authResult->credential->secretAccessKey,
            $date,
            $scopeRegion,
            's3',
        );

        $credentialScope = sprintf('%s/%s/s3/aws4_request', $date, $scopeRegion);

        // Replace the request body with the verified stream.
        $verifiedStream = $this->chunkedVerifier->createVerifiedStream(
            body: $request->getBody(),
            signingKey: $signingKey,
            timestamp: $timestamp,
            credentialScope: $credentialScope,
            seedSignature: $authResult->signature,
        );

        $request->setBody($verifiedStream);
    }
}
