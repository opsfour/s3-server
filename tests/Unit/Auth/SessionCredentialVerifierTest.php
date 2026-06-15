<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Auth\CanonicalRequest;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Auth\PresignedUrlValidator;
use OpsFour\S3Server\Auth\SignatureV4Verifier;
use OpsFour\S3Server\Auth\SigningKey;
use OpsFour\S3Server\Exception\AccessDeniedException;
use PHPUnit\Framework\TestCase;

final class SessionCredentialVerifierTest extends TestCase
{
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
    }

    public function test_presigned_url_rejects_invalid_session_token(): void
    {
        $credential = $this->temporaryCredential();
        $request = $this->presignedRequest($credential, 'wrong-token');

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('The provided token is invalid.');

        (new PresignedUrlValidator())->validate($request, new InMemoryCredentialProvider($credential), 'us-east-1');
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

    private function signedHeaderRequest(Credential $credential, ?string $sessionToken): Request
    {
        $timestamp = gmdate('Ymd\THis\Z');
        $date = substr($timestamp, 0, 8);
        $headers = [
            'host' => ['127.0.0.1'],
            'x-amz-content-sha256' => ['UNSIGNED-PAYLOAD'],
            'x-amz-date' => [$timestamp],
        ];

        if ($sessionToken !== null) {
            $headers['x-amz-security-token'] = [$sessionToken];
        }

        $signedHeaders = array_keys($headers);
        sort($signedHeaders);

        $canonicalRequest = CanonicalRequest::build(
            method: 'GET',
            uri: '/bucket/key.txt',
            queryString: '',
            headers: $headers,
            signedHeaders: $signedHeaders,
            hashedPayload: 'UNSIGNED-PAYLOAD',
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

        return $this->request('GET', '/bucket/key.txt', $headers);
    }

    private function presignedRequest(Credential $credential, ?string $sessionToken): Request
    {
        $timestamp = gmdate('Ymd\THis\Z');
        $date = substr($timestamp, 0, 8);
        $credentialScope = "{$date}/us-east-1/s3/aws4_request";
        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "{$credential->accessKeyId}/{$credentialScope}",
            'X-Amz-Date' => $timestamp,
            'X-Amz-Expires' => '300',
            'X-Amz-SignedHeaders' => 'host',
        ];

        if ($sessionToken !== null) {
            $query['X-Amz-Security-Token'] = $sessionToken;
        }

        $rawQuery = $this->buildRawQuery($query);
        $canonicalRequest = CanonicalRequest::build(
            method: 'GET',
            uri: '/bucket/key.txt',
            queryString: $rawQuery,
            headers: ['host' => ['127.0.0.1']],
            signedHeaders: ['host'],
            hashedPayload: 'UNSIGNED-PAYLOAD',
        );
        $stringToSign = SignatureV4Verifier::buildStringToSign($timestamp, $credentialScope, $canonicalRequest);
        $query['X-Amz-Signature'] = hash_hmac(
            'sha256',
            $stringToSign,
            SigningKey::derive($credential->secretAccessKey, $date, 'us-east-1', 's3'),
        );

        return $this->request('GET', '/bucket/key.txt?' . $this->buildRawQuery($query), ['host' => ['127.0.0.1']]);
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

    /**
     * @param array<string, list<string>> $headers
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
