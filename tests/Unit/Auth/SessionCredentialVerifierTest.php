<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Aws\Credentials\Credentials as AwsCredentials;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Psr7\Request as PsrRequest;
use League\Uri\Http;
use OpsFour\S3Server\Auth\CanonicalRequest;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\AuthResult;
use OpsFour\S3Server\Auth\AuthType;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Auth\PresignedUrlValidator;
use OpsFour\S3Server\Auth\SignatureV4Verifier;
use OpsFour\S3Server\Auth\SigningKey;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\AuthorizationHeaderMalformedException;
use OpsFour\S3Server\Exception\SignatureDoesNotMatchException;
use OpsFour\S3Server\Http\QueryStringParser;
use PHPUnit\Framework\TestCase;

final class SessionCredentialVerifierTest extends TestCase
{
    public function test_auth_result_keeps_existing_constructor_compatible(): void
    {
        $credential = $this->temporaryCredential();
        $result = new AuthResult($credential, $credential->ownerId, []);

        self::assertSame($credential, $result->credential);
        self::assertNull($result->authType);
    }

    public function test_header_sigv4_accepts_valid_session_token(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->signedHeaderRequest($credential, $credential->sessionToken);

        $result = (new SignatureV4Verifier())->verify(
            $request,
            new InMemoryCredentialProvider($credential),
            'us-east-1',
        );

        $this->assertSame('tenant:acme', $result->ownerId);
        $this->assertSame(AuthType::RestHeader, $result->authType);
    }

    public function test_header_sigv4_accepts_basic_utc_date_header_without_amz_date(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->signedHeaderRequest($credential, $credential->sessionToken, useDateHeader: true);

        self::assertSame('tenant:acme', (new SignatureV4Verifier())->verify(
            $request,
            new InMemoryCredentialProvider($credential),
            'us-east-1',
        )->ownerId);
    }

    public function test_streaming_sigv4_uses_signed_date_header_when_amz_date_is_absent(): void
    {
        $credential = $this->temporaryCredential();
        $timestamp = gmdate('Ymd\THis\Z');
        $payload = 'payload';
        $request = $this->signedHeaderRequest(
            $credential,
            $credential->sessionToken,
            $timestamp,
            additionalHeaders: [
                'content-encoding' => 'aws-chunked',
                'x-amz-content-sha256' => 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
                'x-amz-decoded-content-length' => (string) strlen($payload),
            ],
            useDateHeader: true,
        );
        $authorization = $request->getHeader('authorization');
        self::assertNotNull($authorization);
        if (preg_match('/Signature=([a-f0-9]{64})$/', $authorization, $matches) !== 1) {
            self::fail('Signed streaming request did not contain a seed signature.');
        }
        $seedSignature = $matches[1];
        $scope = substr($timestamp, 0, 8) . '/us-east-1/s3/aws4_request';
        $signingKey = SigningKey::derive(
            $credential->secretAccessKey,
            substr($timestamp, 0, 8),
            'us-east-1',
            's3',
        );
        $dataSignature = self::chunkSignature($payload, $signingKey, $timestamp, $scope, $seedSignature);
        $finalSignature = self::chunkSignature('', $signingKey, $timestamp, $scope, $dataSignature);
        $request->setBody(new ReadableBuffer(
            dechex(strlen($payload)) . ";chunk-signature={$dataSignature}\r\n"
            . $payload . "\r\n"
            . "0;chunk-signature={$finalSignature}\r\n\r\n",
        ));
        $handler = new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(200, body: \Amp\ByteStream\buffer($request->getBody()));
            }
        };

        $response = (new AuthMiddleware(new InMemoryCredentialProvider($credential), 'us-east-1'))
            ->handleRequest($request, $handler);

        self::assertSame(200, $response->getStatus());
        self::assertNull($request->getHeader('content-encoding'));
        self::assertSame($payload, $response->getBody()->read());
        self::assertSame(AuthType::RestHeader, AuthType::get($request));
    }

    public function test_header_sigv4_rejects_rfc_date_header_used_for_signing(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->signedHeaderRequest(
            $credential,
            $credential->sessionToken,
            useDateHeader: true,
            dateHeaderValue: gmdate('D, d M Y H:i:s \G\M\T'),
        );

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Date header must use ISO 8601 basic UTC format.');

        (new SignatureV4Verifier())->verify($request, new InMemoryCredentialProvider($credential), 'us-east-1');
    }

    public function test_header_sigv4_rejects_missing_payload_hash_header(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->signedHeaderRequest($credential, $credential->sessionToken, omitPayloadHashHeader: true);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Missing x-amz-content-sha256 header.');

        (new SignatureV4Verifier())->verify($request, new InMemoryCredentialProvider($credential), 'us-east-1');
    }

    public function test_header_sigv4_rejects_duplicate_authentication_headers(): void
    {
        $credential = $this->temporaryCredential();

        foreach (['authorization', 'x-amz-content-sha256', 'x-amz-date', 'x-amz-security-token'] as $name) {
            $request = $this->signedHeaderRequest($credential, $credential->sessionToken);
            $value = $request->getHeader($name);
            self::assertNotNull($value);
            $request->setHeader($name, [$value, $value]);

            try {
                (new SignatureV4Verifier())->verify(
                    $request,
                    new InMemoryCredentialProvider($credential),
                    'us-east-1',
                );
                self::fail("Duplicate {$name} was accepted.");
            } catch (AccessDeniedException $exception) {
                self::assertSame('Request contains duplicate authentication headers.', $exception->getMessage());
            }
        }
    }

    public function test_header_sigv4_rejects_unsorted_signed_headers_even_with_a_valid_signature(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->signedHeaderRequest($credential, $credential->sessionToken);
        $authorization = $request->getHeader('authorization');
        self::assertNotNull($authorization);
        $request->setHeader('authorization', str_replace(
            'SignedHeaders=host;x-amz-content-sha256;',
            'SignedHeaders=x-amz-content-sha256;host;',
            $authorization,
        ));

        $this->expectException(AuthorizationHeaderMalformedException::class);
        $this->expectExceptionMessage('SignedHeaders must contain unique lowercase header names in sorted order.');
        (new SignatureV4Verifier())->verify($request, new InMemoryCredentialProvider($credential), 'us-east-1');
    }

    public function test_header_sigv4_rejects_duplicate_signed_headers_even_with_a_valid_signature(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->signedHeaderRequest(
            $credential,
            $credential->sessionToken,
            signedHeadersOverride: [
                'host',
                'host',
                'x-amz-content-sha256',
                'x-amz-date',
                'x-amz-security-token',
            ],
        );

        $this->expectException(AuthorizationHeaderMalformedException::class);
        $this->expectExceptionMessage('SignedHeaders must contain unique lowercase header names in sorted order.');
        (new SignatureV4Verifier())->verify($request, new InMemoryCredentialProvider($credential), 'us-east-1');
    }

    public function test_header_sigv4_rejects_unsigned_date_header_used_for_authentication(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->signedHeaderRequest(
            $credential,
            $credential->sessionToken,
            useDateHeader: true,
            signDateHeader: false,
        );

        $this->expectException(AuthorizationHeaderMalformedException::class);
        $this->expectExceptionMessage('Date header used for authentication to be signed.');
        (new SignatureV4Verifier())->verify($request, new InMemoryCredentialProvider($credential), 'us-east-1');
    }

    public function test_header_sigv4_rejects_reordered_duplicate_signed_header_values(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->signedHeaderRequest(
            $credential,
            $credential->sessionToken,
            additionalHeaders: ['x-amz-acl' => ['private', 'public-read']],
        );
        $provider = new InMemoryCredentialProvider($credential);
        self::assertSame('private', $request->getHeader('x-amz-acl'));
        self::assertSame('tenant:acme', (new SignatureV4Verifier())->verify($request, $provider, 'us-east-1')->ownerId);

        $request->setHeader('x-amz-acl', ['public-read', 'private']);
        self::assertSame('public-read', $request->getHeader('x-amz-acl'));
        $this->expectException(SignatureDoesNotMatchException::class);
        (new SignatureV4Verifier())->verify($request, $provider, 'us-east-1');
    }

    public function test_header_sigv4_rejects_conflicting_duplicate_query_values_even_when_signed(): void
    {
        $credential = $this->temporaryCredential();
        $query = 'versionId=first&versionId=second';
        $reordered = 'versionId=second&versionId=first';
        self::assertSame('first', QueryStringParser::parse($query)['versionId']);
        self::assertSame('second', QueryStringParser::parse($reordered)['versionId']);

        $request = $this->signedHeaderRequest($credential, $credential->sessionToken, queryString: $query);
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Conflicting duplicate query parameter.');
        (new SignatureV4Verifier())->verify($request, new InMemoryCredentialProvider($credential), 'us-east-1');
    }

    public function test_signed_query_allows_identical_duplicate_values_but_rejects_decoded_key_aliases_with_different_values(): void
    {
        $credential = $this->temporaryCredential();
        $provider = new InMemoryCredentialProvider($credential);
        $identical = 'versionId=same&version%49d=same';
        self::assertFalse(QueryStringParser::hasConflictingValues($identical));
        self::assertSame('tenant:acme', (new SignatureV4Verifier())->verify(
            $this->signedHeaderRequest($credential, $credential->sessionToken, queryString: $identical),
            $provider,
            'us-east-1',
        )->ownerId);

        $conflicting = 'versionId=first&version%49d=second';
        self::assertTrue(QueryStringParser::hasConflictingValues($conflicting));
        $this->expectException(AccessDeniedException::class);
        (new SignatureV4Verifier())->verify(
            $this->signedHeaderRequest($credential, $credential->sessionToken, queryString: $conflicting),
            $provider,
            'us-east-1',
        );
    }

    public function test_header_sigv4_rejects_missing_session_token(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->signedHeaderRequest($credential, null);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Temporary credentials require x-amz-security-token.');

        (new SignatureV4Verifier())->verify($request, new InMemoryCredentialProvider($credential), 'us-east-1');
    }

    public function test_header_sigv4_rejects_expired_session_credential(): void
    {
        $credential = $this->temporaryCredential(expiresAt: new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')));
        $request = $this->signedHeaderRequest($credential, $credential->sessionToken);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('The provided token has expired.');

        (new SignatureV4Verifier())->verify($request, new InMemoryCredentialProvider($credential), 'us-east-1');
    }

    public function test_header_sigv4_rejects_impossible_calendar_date(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->signedHeaderRequest($credential, $credential->sessionToken, '20260231T120000Z');

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Invalid date/time components in request.');

        (new SignatureV4Verifier())->verify($request, new InMemoryCredentialProvider($credential), 'us-east-1');
    }

    public function test_header_sigv4_rejects_non_numeric_timestamp_even_when_correctly_signed(): void
    {
        $credential = $this->temporaryCredential();
        $timestamp = substr_replace(gmdate('Ymd\THis\Z'), 'a', 13, 1);
        $request = $this->signedHeaderRequest($credential, $credential->sessionToken, $timestamp);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Invalid date format in request.');

        (new SignatureV4Verifier())->verify($request, new InMemoryCredentialProvider($credential), 'us-east-1');
    }

    public function test_header_sigv4_rejects_unsigned_amz_mutation_headers(): void
    {
        $credential = $this->temporaryCredential();
        foreach ([
            'x-amz-acl' => 'public-read',
            'x-amz-copy-source' => '/other/secret',
            'x-amz-server-side-encryption' => 'AES256',
            'content-md5' => 'xUFAKrxLKna5cZ2REBfFkg==',
        ] as $name => $value) {
            $request = $this->signedHeaderRequest($credential, $credential->sessionToken);
            $request->setHeader($name, $value);
            try {
                (new SignatureV4Verifier())->verify($request, new InMemoryCredentialProvider($credential), 'us-east-1');
                self::fail("Unsigned {$name} was accepted.");
            } catch (AccessDeniedException $e) {
                self::assertSame('Request contains an unsigned S3 header.', $e->getMessage());
            }
        }
    }

    public function test_header_sigv4_accepts_signed_amz_mutation_header(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->signedHeaderRequest($credential, $credential->sessionToken, additionalHeaders: ['x-amz-acl' => 'private']);
        self::assertSame('tenant:acme', (new SignatureV4Verifier())->verify(
            $request,
            new InMemoryCredentialProvider($credential),
            'us-east-1',
        )->ownerId);
    }

    public function test_header_sigv4_accepts_sdk_signed_repeated_acl_grant_headers(): void
    {
        $credential = $this->temporaryCredential();
        $signed = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
            new PsrRequest('PUT', 'http://127.0.0.1/bucket?acl', [
                'x-amz-grant-read' => ['id="z-reader"', 'id="a-reader"'],
            ]),
            new AwsCredentials($credential->accessKeyId, $credential->secretAccessKey, $credential->sessionToken),
        );
        self::assertSame(['id="z-reader"', 'id="a-reader"'], $signed->getHeader('x-amz-grant-read'));

        $request = $this->requestFromPsr($signed);

        self::assertSame('tenant:acme', (new SignatureV4Verifier())->verify(
            $request,
            new InMemoryCredentialProvider($credential),
            'us-east-1',
        )->ownerId);
    }

    public function test_presigned_url_accepts_valid_session_token(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->presignedRequest($credential, $credential->sessionToken);

        $result = (new PresignedUrlValidator())->validate(
            $request,
            new InMemoryCredentialProvider($credential),
            'us-east-1',
        );

        $this->assertSame('tenant:acme', $result->ownerId);
        $this->assertSame(AuthType::RestQueryString, $result->authType);
    }

    public function test_built_in_authentication_scrubs_untrusted_auth_type_before_a_failed_signature(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->signedHeaderRequest($credential, $credential->sessionToken);
        AuthType::set($request, AuthType::RestQueryString);
        $authorization = $request->getHeader('authorization');
        self::assertNotNull($authorization);
        $request->setHeader('authorization', substr($authorization, 0, -1) . ($authorization[-1] === '0' ? '1' : '0'));

        try {
            (new AuthMiddleware(new InMemoryCredentialProvider($credential), 'us-east-1'))->handleRequest(
                $request,
                new class implements RequestHandler {
                    public function handleRequest(Request $request): Response
                    {
                        return new Response(200);
                    }
                },
            );
            self::fail('A request with an invalid signature was accepted.');
        } catch (SignatureDoesNotMatchException) {
            self::assertNull(AuthType::get($request));
            self::assertFalse($request->hasAttribute(AuthType::REQUEST_ATTRIBUTE));
        }
    }

    public function test_invalid_presign_never_installs_auth_type(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->presignedRequest($credential, $credential->sessionToken);
        AuthType::set($request, AuthType::RestHeader);
        $uri = $request->getUri();
        $request->setUri($uri->withQuery(preg_replace(
            '/X-Amz-Signature=[a-f0-9]+/',
            'X-Amz-Signature=' . str_repeat('0', 64),
            $uri->getQuery(),
        ) ?? $uri->getQuery()));

        try {
            (new AuthMiddleware(new InMemoryCredentialProvider($credential), 'us-east-1'))->handleRequest(
                $request,
                new class implements RequestHandler {
                    public function handleRequest(Request $request): Response
                    {
                        return new Response(200);
                    }
                },
            );
            self::fail('A request with an invalid presign was accepted.');
        } catch (SignatureDoesNotMatchException) {
            self::assertNull(AuthType::get($request));
            self::assertFalse($request->hasAttribute(AuthType::REQUEST_ATTRIBUTE));
        }
    }

    public function test_anonymous_authentication_scrubs_preinstalled_typed_auth_type(): void
    {
        $request = $this->request('GET', '/bucket/key.txt', ['host' => ['127.0.0.1']]);
        AuthType::set($request, AuthType::Post);

        $response = (new AuthMiddleware(new InMemoryCredentialProvider(), 'us-east-1'))->handleRequest(
            $request,
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(200, body: (string) $request->getAttribute('ownerId'));
                }
            },
        );

        self::assertSame(200, $response->getStatus());
        self::assertNull($response->getBody()->read());
        self::assertNull(AuthType::get($request));
        self::assertFalse($request->hasAttribute(AuthType::REQUEST_ATTRIBUTE));
    }

    public function test_presigned_url_accepts_literal_plus_in_signed_session_token(): void
    {
        $credential = new Credential(
            accessKeyId: 'AKIASESSION12345678',
            secretAccessKey: 'session-secret-key',
            ownerId: 'tenant:acme',
            sessionToken: 'session+token',
            expiresAt: new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')),
        );
        $request = $this->presignedRequest($credential, $credential->sessionToken);
        $uri = $request->getUri();
        $request->setUri($uri->withQuery(str_replace('session%2Btoken', 'session+token', $uri->getQuery())));

        self::assertSame('session token', $request->getQueryParameter('X-Amz-Security-Token'));
        self::assertSame('tenant:acme', (new PresignedUrlValidator())->validate(
            $request,
            new InMemoryCredentialProvider($credential),
            'us-east-1',
        )->ownerId);
    }

    public function test_empty_first_presign_algorithm_does_not_downgrade_to_anonymous(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->request(
            'GET',
            '/bucket/key.txt?X-Amz-Algorithm=&X-Amz-Algorithm=AWS4-HMAC-SHA256',
            ['host' => ['127.0.0.1']],
        );
        $handler = new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(200);
            }
        };

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Conflicting duplicate query parameter.');
        (new AuthMiddleware(new InMemoryCredentialProvider($credential), 'us-east-1'))->handleRequest($request, $handler);
    }

    public function test_signed_authorization_header_takes_precedence_over_empty_presign_algorithm(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->signedHeaderRequest(
            $credential,
            $credential->sessionToken,
            queryString: 'X-Amz-Algorithm=',
        );
        $handler = new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(200, body: (string) $request->getAttribute('ownerId'));
            }
        };

        $response = (new AuthMiddleware(new InMemoryCredentialProvider($credential), 'us-east-1'))
            ->handleRequest($request, $handler);
        self::assertSame(200, $response->getStatus());
        self::assertSame('tenant:acme', $response->getBody()->read());
    }

    public function test_presigned_url_rejects_conflicting_duplicate_query_values_even_when_signed(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->presignedRequest(
            $credential,
            $credential->sessionToken,
            additionalQueryString: 'versionId=first&versionId=second',
        );

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Conflicting duplicate query parameter.');
        (new PresignedUrlValidator())->validate($request, new InMemoryCredentialProvider($credential), 'us-east-1');
    }

    public function test_presigned_url_allows_identical_duplicate_query_values(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->presignedRequest(
            $credential,
            $credential->sessionToken,
            additionalQueryString: 'versionId=same&version%49d=same',
        );

        self::assertSame('tenant:acme', (new PresignedUrlValidator())->validate(
            $request,
            new InMemoryCredentialProvider($credential),
            'us-east-1',
        )->ownerId);
    }

    public function test_presigned_url_rejects_unsorted_or_missing_signed_headers(): void
    {
        $credential = $this->temporaryCredential();
        $provider = new InMemoryCredentialProvider($credential);

        foreach ([['x-amz-meta-test', 'host'], ['host', 'x-amz-missing']] as $signedHeaders) {
            $request = $this->presignedRequest(
                $credential,
                $credential->sessionToken,
                additionalHeaders: $signedHeaders[0] === 'x-amz-meta-test' ? ['x-amz-meta-test' => 'value'] : [],
                signedHeadersOverride: $signedHeaders,
            );

            try {
                (new PresignedUrlValidator())->validate($request, $provider, 'us-east-1');
                self::fail('Malformed presigned SignedHeaders were accepted.');
            } catch (AuthorizationHeaderMalformedException $exception) {
                self::assertStringContainsString('SignedHeaders', $exception->getMessage());
            }
        }
    }

    public function test_presigned_url_rejects_invalid_session_token(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->presignedRequest($credential, 'wrong-token');

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('The provided token is invalid.');

        (new PresignedUrlValidator())->validate($request, new InMemoryCredentialProvider($credential), 'us-east-1');
    }

    public function test_presigned_url_rejects_impossible_calendar_date(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->presignedRequest($credential, $credential->sessionToken, '20260231T120000Z');

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Invalid date/time components in X-Amz-Date.');

        (new PresignedUrlValidator())->validate($request, new InMemoryCredentialProvider($credential), 'us-east-1');
    }

    public function test_presigned_url_rejects_non_numeric_timestamp_even_when_correctly_signed(): void
    {
        $credential = $this->temporaryCredential();
        $timestamp = substr_replace(gmdate('Ymd\THis\Z'), 'a', 13, 1);
        $request = $this->presignedRequest($credential, $credential->sessionToken, $timestamp);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Invalid X-Amz-Date format.');

        (new PresignedUrlValidator())->validate($request, new InMemoryCredentialProvider($credential), 'us-east-1');
    }

    public function test_presigned_url_rejects_non_decimal_expiry_even_when_correctly_signed(): void
    {
        $credential = $this->temporaryCredential();

        foreach (['1e99', '1junk', '1.5', '+300'] as $expires) {
            $request = $this->presignedRequest($credential, $credential->sessionToken, expires: $expires);

            try {
                (new PresignedUrlValidator())->validate(
                    $request,
                    new InMemoryCredentialProvider($credential),
                    'us-east-1',
                );
                self::fail("Invalid expiry {$expires} was accepted.");
            } catch (AccessDeniedException $exception) {
                self::assertStringContainsString('X-Amz-Expires', $exception->getMessage());
            }
        }
    }

    public function test_presigned_url_rejects_unsigned_amz_mutation_headers(): void
    {
        $credential = $this->temporaryCredential();
        foreach ([
            'x-amz-acl' => 'public-read',
            'x-amz-copy-source' => '/other/secret',
            'x-amz-server-side-encryption' => 'AES256',
            'content-md5' => 'xUFAKrxLKna5cZ2REBfFkg==',
        ] as $name => $value) {
            $request = $this->presignedRequest($credential, $credential->sessionToken);
            $request->setHeader($name, $value);
            try {
                (new PresignedUrlValidator())->validate($request, new InMemoryCredentialProvider($credential), 'us-east-1');
                self::fail("Unsigned {$name} was accepted.");
            } catch (AccessDeniedException $e) {
                self::assertSame('Request contains an unsigned S3 header.', $e->getMessage());
            }
        }
    }

    public function test_presigned_url_accepts_signed_amz_mutation_header(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->presignedRequest($credential, $credential->sessionToken, additionalHeaders: ['x-amz-acl' => 'private']);
        self::assertSame('tenant:acme', (new PresignedUrlValidator())->validate(
            $request,
            new InMemoryCredentialProvider($credential),
            'us-east-1',
        )->ownerId);
    }

    public function test_presigned_url_accepts_sdk_signed_repeated_acl_grant_headers(): void
    {
        $credential = $this->temporaryCredential();
        $signed = (new S3SignatureV4('s3', 'us-east-1'))->presign(
            new PsrRequest('PUT', 'http://127.0.0.1/bucket?acl', [
                'x-amz-grant-read' => ['id="z-reader"', 'id="a-reader"'],
            ]),
            new AwsCredentials($credential->accessKeyId, $credential->secretAccessKey, $credential->sessionToken),
            '+5 minutes',
        );
        self::assertSame(['id="z-reader"', 'id="a-reader"'], $signed->getHeader('x-amz-grant-read'));
        self::assertSame(2, substr_count($signed->getUri()->getQuery(), 'x-amz-grant-read='));

        $request = $this->requestFromPsr($signed);

        self::assertSame('tenant:acme', (new PresignedUrlValidator())->validate(
            $request,
            new InMemoryCredentialProvider($credential),
            'us-east-1',
        )->ownerId);
    }

    private function temporaryCredential(?\DateTimeImmutable $expiresAt = null): Credential
    {
        return new Credential(
            accessKeyId: 'AKIASESSION12345678',
            secretAccessKey: 'session-secret-key',
            ownerId: 'tenant:acme',
            displayName: 'Alice',
            isActive: true,
            sessionToken: 'session-token-123',
            expiresAt: $expiresAt ?? new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')),
        );
    }

    /**
     * @param array<non-empty-string, string|list<string>> $additionalHeaders
     * @param list<string>|null $signedHeadersOverride
     */
    private function signedHeaderRequest(
        Credential $credential,
        ?string $sessionToken,
        ?string $timestamp = null,
        array $additionalHeaders = [],
        bool $useDateHeader = false,
        ?string $dateHeaderValue = null,
        bool $omitPayloadHashHeader = false,
        string $queryString = '',
        bool $signDateHeader = true,
        ?array $signedHeadersOverride = null,
    ): Request {
        $timestamp ??= gmdate('Ymd\THis\Z');
        $date = substr($timestamp, 0, 8);
        $headers = [
            'host' => ['127.0.0.1'],
        ];

        if (! $omitPayloadHashHeader) {
            $headers['x-amz-content-sha256'] = ['UNSIGNED-PAYLOAD'];
        }

        if ($useDateHeader) {
            $headers['date'] = [$dateHeaderValue ?? $timestamp];
        } else {
            $headers['x-amz-date'] = [$timestamp];
        }

        if ($sessionToken !== null) {
            $headers['x-amz-security-token'] = [$sessionToken];
        }
        foreach ($additionalHeaders as $name => $value) {
            $headers[$name] = is_array($value) ? $value : [$value];
        }

        $signedHeaders = $signedHeadersOverride ?? array_keys($headers);
        if ($signedHeadersOverride === null && ! $signDateHeader) {
            $signedHeaders = array_values(array_filter(
                $signedHeaders,
                static fn(string $name): bool => $name !== 'date',
            ));
        }
        if ($signedHeadersOverride === null) {
            sort($signedHeaders);
        }

        $canonicalRequest = CanonicalRequest::build(
            method: 'GET',
            uri: '/bucket/key.txt',
            queryString: $queryString,
            headers: $headers,
            signedHeaders: $signedHeaders,
            hashedPayload: $headers['x-amz-content-sha256'][0] ?? 'UNSIGNED-PAYLOAD',
        );
        $credentialScope = "{$date}/us-east-1/s3/aws4_request";
        $stringToSign = SignatureV4Verifier::buildStringToSign($timestamp, $credentialScope, $canonicalRequest);
        $signature = hash_hmac(
            'sha256',
            $stringToSign,
            SigningKey::derive($credential->secretAccessKey, $date, 'us-east-1', 's3'),
        );

        $headers['authorization'] = [sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $credential->accessKeyId,
            $credentialScope,
            implode(';', $signedHeaders),
            $signature,
        )];

        return $this->request('GET', '/bucket/key.txt' . ($queryString === '' ? '' : '?' . $queryString), $headers);
    }

    /**
     * @param array<non-empty-string, string> $additionalHeaders
     * @param list<string>|null $signedHeadersOverride
     */
    private function presignedRequest(
        Credential $credential,
        ?string $sessionToken,
        ?string $timestamp = null,
        array $additionalHeaders = [],
        string $expires = '300',
        string $additionalQueryString = '',
        ?array $signedHeadersOverride = null,
    ): Request {
        $timestamp ??= gmdate('Ymd\THis\Z');
        $date = substr($timestamp, 0, 8);
        $credentialScope = "{$date}/us-east-1/s3/aws4_request";
        $headers = ['host' => ['127.0.0.1']];
        foreach ($additionalHeaders as $name => $value) {
            $headers[$name] = [$value];
        }
        $signedHeaders = $signedHeadersOverride ?? array_keys($headers);
        if ($signedHeadersOverride === null) {
            sort($signedHeaders);
        }
        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "{$credential->accessKeyId}/{$credentialScope}",
            'X-Amz-Date' => $timestamp,
            'X-Amz-Expires' => $expires,
            'X-Amz-SignedHeaders' => implode(';', $signedHeaders),
        ];

        if ($sessionToken !== null) {
            $query['X-Amz-Security-Token'] = $sessionToken;
        }

        $rawQuery = $this->buildRawQuery($query)
            . ($additionalQueryString === '' ? '' : '&' . $additionalQueryString);
        $canonicalRequest = CanonicalRequest::build(
            method: 'GET',
            uri: '/bucket/key.txt',
            queryString: $rawQuery,
            headers: $headers,
            signedHeaders: $signedHeaders,
            hashedPayload: 'UNSIGNED-PAYLOAD',
        );
        $stringToSign = SignatureV4Verifier::buildStringToSign($timestamp, $credentialScope, $canonicalRequest);
        $query['X-Amz-Signature'] = hash_hmac(
            'sha256',
            $stringToSign,
            SigningKey::derive($credential->secretAccessKey, $date, 'us-east-1', 's3'),
        );

        return $this->request('GET', '/bucket/key.txt?' . $this->buildRawQuery($query)
            . ($additionalQueryString === '' ? '' : '&' . $additionalQueryString), $headers);
    }

    /**
     * @param array<string, string> $query
     */
    private function buildRawQuery(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            $parts[] = rawurlencode($key) . '=' . rawurlencode($value);
        }

        return implode('&', $parts);
    }

    private static function chunkSignature(
        string $payload,
        string $signingKey,
        string $timestamp,
        string $scope,
        string $previousSignature,
    ): string {
        return hash_hmac('sha256', implode("\n", [
            'AWS4-HMAC-SHA256-PAYLOAD',
            $timestamp,
            $scope,
            $previousSignature,
            hash('sha256', ''),
            hash('sha256', $payload),
        ]), $signingKey);
    }

    private function requestFromPsr(\Psr\Http\Message\RequestInterface $request): Request
    {
        $method = $request->getMethod();
        if ($method === '') {
            throw new \LogicException('A signed SDK request must have a method.');
        }

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            if ($name === '') {
                throw new \LogicException('A signed SDK request must not have an empty header name.');
            }
            $headers[$name] = array_values($values);
        }

        return $this->request($method, $request->getRequestTarget(), $headers);
    }

    /**
     * @param non-empty-string $method
     * @param array<non-empty-string, list<string>> $headers
     */
    private function request(string $method, string $path, array $headers): Request
    {
        return new Request(
            new SessionVerifierTestClient(),
            $method,
            Http::new('http://127.0.0.1' . $path),
            $headers,
        );
    }
}

final class SessionVerifierTestClient implements Client
{
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
}
