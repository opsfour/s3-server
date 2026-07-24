<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Contracts\AuthenticationMiddleware;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\S3Exception;
use OpsFour\S3Server\Routing\S3Operation;

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
final class AuthMiddleware implements AuthenticationMiddleware
{
    private readonly SignatureV4Verifier $sigV4Verifier;

    private readonly PresignedUrlValidator $presignedValidator;

    private readonly ChunkedSignatureVerifier $chunkedVerifier;

    private readonly PostObjectFormParser $postObjectFormParser;

    public function __construct(
        private readonly CredentialProvider $credentialProvider,
        private readonly string $region,
        int $requestBodySizeLimit = 5_368_709_120,
    ) {
        $this->sigV4Verifier = new SignatureV4Verifier();
        $this->presignedValidator = new PresignedUrlValidator();
        $this->chunkedVerifier = new ChunkedSignatureVerifier();
        $this->postObjectFormParser = new PostObjectFormParser(
            $credentialProvider,
            $region,
            $requestBodySizeLimit,
        );
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        // POST Object (HTML form upload) uses its own authentication via form fields.
        // Skip normal SigV4 auth — the PostObjectHandler handles V2 signature verification.
        // Set empty defaults so downstream middleware doesn't crash on missing attributes.
        $operation = $request->hasAttribute('s3.operation')
            ? $request->getAttribute('s3.operation')
            : null;
        if ($operation === S3Operation::PostObject) {
            $form = $this->postObjectFormParser->parse($request);
            $request->setAttribute(PostObjectForm::class, $form);
            $request->setAttribute('credential', $form->credential);
            $request->setAttribute(
                'ownerId',
                $form->credential === null ? '' : $form->credential->ownerId,
            );
            $request->setAttribute('signedHeaders', []);
            try {
                return $requestHandler->handleRequest($request);
            } finally {
                $form->cleanup();
            }
        }

        $authResult = $this->authenticate($request);

        if ($operation === S3Operation::CreateBucket && $authResult->ownerId === '') {
            throw new AccessDeniedException(
                'Access Denied. Bucket creation requires authentication.',
            );
        }

        // Set request attributes for downstream handlers.
        $request->setAttribute('credential', $authResult->credential);
        $request->setAttribute('ownerId', $authResult->ownerId);
        $request->setAttribute('signedHeaders', $authResult->signedHeaders);

        // If chunked SigV4 streaming, wrap the body with signature verification.
        $contentSha = $request->getHeader('x-amz-content-sha256');
        if ($contentSha === 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD') {
            $this->wrapChunkedBody($request, $authResult);
        } elseif ($contentSha === 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD-TRAILER') {
            $this->wrapChunkedBody($request, $authResult, true);
        } elseif ($contentSha === 'STREAMING-UNSIGNED-PAYLOAD-TRAILER') {
            $trailerNames = $this->trailerNames($request);
            $request->setBody((new UnsignedChunkedTrailerDecoder())->createDecodedStream(
                $request->getBody(),
                $trailerNames,
                $this->trailerCallback($request),
            ));
            $this->removeAwsChunkedContentEncoding($request);
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
    private function wrapChunkedBody(Request $request, AuthResult $authResult, bool $withTrailers = false): void
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

        $trailerNames = [];
        if ($withTrailers) {
            $trailerNames = $this->trailerNames($request);
        }

        // Replace the request body with the verified stream.
        $verifiedStream = $this->chunkedVerifier->createVerifiedStream(
            body: $request->getBody(),
            signingKey: $signingKey,
            timestamp: $timestamp,
            credentialScope: $credentialScope,
            seedSignature: $authResult->signature,
            trailerNames: $trailerNames,
            onTrailers: $this->trailerCallback($request),
        );

        $request->setBody($verifiedStream);
        $this->removeAwsChunkedContentEncoding($request);
    }

    /** @return list<string> */
    private function trailerNames(Request $request): array
    {
        $declaration = strtolower($request->getHeader('x-amz-trailer') ?? '');
        $trailerNames = array_values(array_filter(array_map('trim', explode(',', $declaration))));
        if ($trailerNames === [] || count(array_unique($trailerNames)) !== count($trailerNames)) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'x-amz-trailer must declare one or more unique checksum trailer names.',
            );
        }

        return $trailerNames;
    }

    private function trailerCallback(Request $request): \Closure
    {
        return static function (array $trailers) use ($request): void {
            foreach ($trailers as $name => $value) {
                if (! is_string($name) || $name === '' || ! is_string($value)) {
                    throw new \LogicException('Verified trailer callback received malformed headers.');
                }
                $request->setHeader($name, $value);
            }
        };
    }

    private function removeAwsChunkedContentEncoding(Request $request): void
    {
        $encodings = array_values(array_filter(
            array_map('trim', explode(',', $request->getHeader('content-encoding') ?? '')),
            static fn(string $encoding): bool => strtolower($encoding) !== 'aws-chunked' && $encoding !== '',
        ));
        if ($encodings === []) {
            $request->removeHeader('content-encoding');

            return;
        }

        $request->setHeader('content-encoding', implode(', ', $encodings));
    }
}
