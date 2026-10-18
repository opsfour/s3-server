<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit;

use Aws\Command;
use Aws\Exception\AwsException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpsFour\S3Server\Tests\Support\ProductionSoakDiagnostics;
use PHPUnit\Framework\TestCase;

final class ProductionSoakDiagnosticsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/s3-soak-diagnostics-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->directory);
    }

    public function test_rejection_keeps_only_allowlisted_transport_fields(): void
    {
        $request = new Request(
            'PUT',
            'https://user:password-sentinel@example.test/private-object?X-Amz-Signature=signature-sentinel',
            ['Authorization' => 'credential-sentinel'],
            'body-sentinel',
        );
        $transport = new ConnectException('message-sentinel', $request, null, [
            'errno' => 28,
            'error' => 'Timeout for https://user:password-sentinel@example.test/private-object?token=credential-sentinel',
            'url' => 'https://example.test/private-object',
            'request_header' => 'Authorization: credential-sentinel',
        ]);
        $exception = new AwsException('exception-message-sentinel', new Command('PutObject'), [
            'code' => 'RequestTimeout',
            'response' => new Response(503, ['X-Secret' => 'header-sentinel'], 'response-body-sentinel'),
            'request' => $request,
        ], $transport);

        $diagnostic = ProductionSoakDiagnostics::rejection('private-object-sentinel', $exception);
        $encoded = json_encode($diagnostic, JSON_THROW_ON_ERROR);

        self::assertSame(AwsException::class, $diagnostic['exception_class']);
        self::assertSame('RequestTimeout', $diagnostic['aws_error_code']);
        self::assertSame(503, $diagnostic['http_status']);
        self::assertSame(28, $diagnostic['handler_errno']);
        self::assertSame('Timeout for [redacted-url]', $diagnostic['handler_error']);
        self::assertStringStartsWith('string-sha256:', (string) $diagnostic['index']);
        foreach (['private-object-sentinel', 'credential-sentinel', 'password-sentinel', 'signature-sentinel',
            'body-sentinel', 'header-sentinel', 'message-sentinel', 'example.test'] as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
    }

    public function test_persisted_rejections_are_bounded_and_private(): void
    {
        $rejections = [];
        for ($index = 0; $index < 120; $index++) {
            $rejections[] = ProductionSoakDiagnostics::rejection($index, new \RuntimeException('not persisted'));
        }

        ProductionSoakDiagnostics::persistRejections($this->directory, $rejections);

        $path = $this->directory . '/command-pool-rejections.json';
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(100, $decoded['rejections']);
        self::assertTrue($decoded['truncated']);
        self::assertSame('0600', substr(sprintf('%04o', fileperms($path)), -4));
    }

    public function test_log_tail_is_redacted_bounded_and_private(): void
    {
        $source = $this->directory . '/source.log';
        file_put_contents($source, str_repeat('x', 300 * 1024)
            . "\nrequest failed at https://example.test/private?token=secret\n"
            . "PUT /private-bucket/private-object?X-Amz-Credential=secret HTTP/1.1\n"
            . "context {\"path\":\"/private-object\",\"storage\":\"filesystem:/private/root\"}\n"
            . "Authorization: credential-sentinel\n"
            . 'final-marker');

        ProductionSoakDiagnostics::persistLogTail($source, $this->directory, 'server.stderr.log');

        $target = $this->directory . '/server.stderr.log';
        $contents = (string) file_get_contents($target);
        self::assertLessThanOrEqual(256 * 1024, strlen($contents));
        self::assertStringContainsString('[redacted-url]', $contents);
        self::assertStringContainsString('[redacted-sensitive-line]', $contents);
        self::assertStringContainsString('final-marker', $contents);
        self::assertStringNotContainsString('example.test', $contents);
        self::assertStringNotContainsString('private-object', $contents);
        self::assertStringNotContainsString('private-bucket', $contents);
        self::assertStringNotContainsString('private/root', $contents);
        self::assertStringNotContainsString('credential-sentinel', $contents);
        self::assertSame('0600', substr(sprintf('%04o', fileperms($target)), -4));
    }
}
