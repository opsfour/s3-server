<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use Amp\ByteStream\ReadableBuffer;
use Amp\File\Driver\ParallelFilesystemDriver;
use Amp\File\File;
use Amp\File\FilesystemDriver;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\PostObjectFormParser;
use OpsFour\S3Server\Auth\PostObjectSpoolCoordinator;
use OpsFour\S3Server\Auth\PostObjectTempFileSweeper;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\S3ServerConfig;
use PHPUnit\Framework\TestCase;

use function Amp\File\createDefaultDriver;
use function Amp\File\filesystem;

final class PostObjectSpoolBudgetTest extends TestCase
{
    public function test_auth_post_spool_uses_one_explicit_temp_directory_and_rejects_invalid_binding(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-auth-post-path-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700));
        $canonicalTempDir = realpath($tempDir);
        self::assertIsString($canonicalTempDir);

        try {
            $credentials = $this->createMock(CredentialProvider::class);
            $auth = new AuthMiddleware($credentials, 'us-east-1', postSpoolTempDir: $tempDir);
            $parser = (new \ReflectionProperty($auth, 'postObjectFormParser'))->getValue($auth);
            self::assertInstanceOf(PostObjectFormParser::class, $parser);
            $coordinator = (new \ReflectionProperty($parser, 'spoolCoordinator'))->getValue($parser);
            $sweeper = (new \ReflectionProperty($parser, 'tempFileSweeper'))->getValue($parser);
            self::assertInstanceOf(PostObjectSpoolCoordinator::class, $coordinator);
            self::assertInstanceOf(PostObjectTempFileSweeper::class, $sweeper);
            self::assertSame($canonicalTempDir, $coordinator->tempDir());
            self::assertSame($canonicalTempDir, (new \ReflectionProperty($sweeper, 'tempDir'))->getValue($sweeper));

            try {
                new AuthMiddleware($credentials, 'us-east-1', postSpoolTempDir: $tempDir . '/missing');
                self::fail('An unavailable explicit POST spool directory was accepted.');
            } catch (\RuntimeException) {
                self::assertDirectoryDoesNotExist($tempDir . '/missing');
            }

            try {
                new PostObjectFormParser(
                    $credentials,
                    'us-east-1',
                    1_024,
                    new PostObjectTempFileSweeper(tempDir: $tempDir . '/other'),
                    tempDir: $tempDir,
                );
                self::fail('A mismatched injected POST sweeper bypassed the explicit directory.');
            } catch (\InvalidArgumentException) {
                self::assertSame($canonicalTempDir, $coordinator->tempDir());
            }
        } finally {
            @rmdir($tempDir . '/.opsfour-s3-post-budget');
            @rmdir($tempDir);
        }
    }

    public function test_browser_post_file_is_private_even_with_permissive_umask(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-post-mode-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o755, true));
        $oldUmask = umask(0);
        $form = null;

        try {
            $parser = new PostObjectFormParser(
                $this->createMock(CredentialProvider::class),
                'us-east-1',
                1_024,
                new PostObjectTempFileSweeper(tempDir: $tempDir),
                spoolCoordinator: new PostObjectSpoolCoordinator($tempDir),
            );
            $form = $parser->parse($this->formRequest('private', 'secret body'));
            clearstatcache(true, $form->filePath);
            self::assertSame(0o600, fileperms($form->filePath) & 0o777);
            self::assertStringNotContainsString('secret body', (string) file_get_contents($form->filePath));
            self::assertSame('secret body', \Amp\ByteStream\buffer($form->openFile()));
            $form->cleanup();
            self::assertFileDoesNotExist($form->filePath);
        } finally {
            $form?->cleanup();
            umask($oldUmask);
            foreach (glob($tempDir . '/.opsfour-s3-post-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-post-budget');
            @rmdir($tempDir);
        }
    }

    public function test_form_cleanup_closes_unconsumed_request_body_before_deleting_spool(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-post-close-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $request = $this->formRequest('object', 'unconsumed body');
        $form = null;

        try {
            $parser = new PostObjectFormParser(
                $this->createMock(CredentialProvider::class),
                'us-east-1',
                1_024,
                new PostObjectTempFileSweeper(tempDir: $tempDir),
                spoolCoordinator: new PostObjectSpoolCoordinator($tempDir),
            );
            $form = $parser->parse($request);
            self::assertTrue($request->getBody()->isReadable());

            $form->cleanup();
            self::assertTrue($request->getBody()->isClosed());
            self::assertFileDoesNotExist($form->filePath);
        } finally {
            $form?->cleanup();
            foreach (glob($tempDir . '/.opsfour-s3-post-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-post-budget');
            @rmdir($tempDir);
        }
    }

    public function test_browser_post_rejects_a_spool_removed_before_opening_it_for_write(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-post-open-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o755, true));
        $canonicalTempDir = realpath($tempDir);
        self::assertIsString($canonicalTempDir);
        $realDriver = new ParallelFilesystemDriver();
        $driver = $this->createMock(FilesystemDriver::class);
        $driver->method('openFile')->willReturnCallback(
            function (string $path, string $mode) use ($realDriver, $canonicalTempDir): \Amp\File\File {
                if (str_starts_with($path, $canonicalTempDir . '/s3-post-') && $mode === 'c') {
                    self::assertTrue(unlink($path));
                }

                return $realDriver->openFile($path, $mode);
            },
        );
        $driver->method('deleteFile')->willReturnCallback(
            static fn(string $path) => $realDriver->deleteFile($path),
        );
        filesystem($driver);
        $oldUmask = umask(0);

        try {
            $parser = new PostObjectFormParser(
                $this->createMock(CredentialProvider::class),
                'us-east-1',
                1_024,
                new PostObjectTempFileSweeper(tempDir: $tempDir),
                spoolCoordinator: new PostObjectSpoolCoordinator($tempDir),
            );

            try {
                $form = $parser->parse($this->formRequest('private', 'secret body'));
                $form->cleanup();
                self::fail('A deleted spool must not be recreated while opening the upload.');
            } catch (\RuntimeException $error) {
                self::assertSame('Browser POST temporary file changed before writing.', $error->getMessage());
                self::assertSame([], glob($tempDir . '/s3-post-*') ?: []);
            }
        } finally {
            filesystem(createDefaultDriver());
            umask($oldUmask);
            foreach (glob($tempDir . '/.opsfour-s3-post-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-post-budget');
            @rmdir($tempDir);
        }
    }

    public function test_browser_post_rejects_short_local_write_and_releases_spool(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-post-short-write-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o755, true));
        $canonicalTempDir = realpath($tempDir);
        self::assertIsString($canonicalTempDir);
        $realDriver = new ParallelFilesystemDriver();
        $driver = $this->createMock(FilesystemDriver::class);
        $driver->method('openFile')->willReturnCallback(function (string $path, string $mode) use ($realDriver, $canonicalTempDir): File {
            if (!str_starts_with($path, $canonicalTempDir . '/s3-post-') || $mode !== 'c') {
                return $realDriver->openFile($path, $mode);
            }
            $file = $this->createMock(File::class);
            $file->method('write')->willReturnCallback(static function (string $bytes) use ($path): void {
                file_put_contents($path, substr($bytes, 0, 2), FILE_APPEND);
            });

            return $file;
        });
        $driver->method('deleteFile')->willReturnCallback(
            static fn(string $path) => $realDriver->deleteFile($path),
        );
        filesystem($driver);

        try {
            $parser = new PostObjectFormParser(
                $this->createMock(CredentialProvider::class),
                'us-east-1',
                1_024,
                new PostObjectTempFileSweeper(tempDir: $tempDir),
                spoolCoordinator: new PostObjectSpoolCoordinator($tempDir),
            );
            try {
                $form = $parser->parse($this->formRequest('short', 'abcdef'));
                $form->cleanup();
                self::fail('A short browser POST spool write must reject the form.');
            } catch (\RuntimeException $error) {
                self::assertSame('Browser POST temporary file is incomplete.', $error->getMessage());
                self::assertSame([], glob($tempDir . '/s3-post-*') ?: []);
            }
        } finally {
            filesystem(createDefaultDriver());
            foreach (glob($tempDir . '/.opsfour-s3-post-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-post-budget');
            @rmdir($tempDir);
        }
    }

    public function test_browser_post_spool_rejects_ciphertext_tampering_and_truncation(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-post-auth-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $parser = new PostObjectFormParser(
            $this->createMock(CredentialProvider::class),
            'us-east-1',
            1_024,
            new PostObjectTempFileSweeper(tempDir: $tempDir),
            spoolCoordinator: new PostObjectSpoolCoordinator($tempDir),
        );

        try {
            foreach (['tamper', 'truncate'] as $fault) {
                $form = $parser->parse($this->formRequest($fault, 'secret body'));
                try {
                    $bytes = file_get_contents($form->filePath);
                    self::assertIsString($bytes);
                    self::assertSame(43, strlen($bytes));
                    self::assertStringNotContainsString('secret body', $bytes);
                    $changed = $fault === 'tamper'
                        ? substr_replace($bytes, $bytes[35] ^ "\x01", 35, 1)
                        : substr($bytes, 0, -1);
                    file_put_contents($form->filePath, $changed);
                    try {
                        \Amp\ByteStream\buffer($form->openFile());
                        self::fail("The {$fault} browser POST spool must fail authentication.");
                    } catch (InternalErrorException) {
                        self::assertFileExists($form->filePath);
                    }
                } finally {
                    $form->cleanup();
                    self::assertFileDoesNotExist($form->filePath);
                }
            }
        } finally {
            foreach (glob($tempDir . '/.opsfour-s3-post-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-post-budget');
            @rmdir($tempDir);
        }
    }

    public function test_world_writable_non_sticky_directory_is_rejected(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-post-mode-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        self::assertTrue(chmod($tempDir, 0o777));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('private or sticky');
            new PostObjectSpoolCoordinator($tempDir);
        } finally {
            @rmdir($tempDir);
        }
    }

    public function test_concurrent_forms_share_budget_and_cleanup_releases_it(): void
    {
        $parser = new PostObjectFormParser(
            $this->createMock(CredentialProvider::class),
            'us-east-1',
            1_024,
            spoolBudgetBytes: 39,
        );
        $otherParser = new PostObjectFormParser(
            $this->createMock(CredentialProvider::class),
            'us-east-1',
            1_024,
            spoolBudgetBytes: 39,
        );

        $first = $parser->parse($this->formRequest('first', 'abcd'));
        try {
            self::assertSame(4, $first->fileSize);
            try {
                $otherParser->parse($this->formRequest('second', 'abcd'));
                self::fail('A second concurrent form must not exceed the shared spool budget.');
            } catch (SlowDownException $e) {
                self::assertSame(503, $e->getHttpStatus());
                self::assertSame('SlowDown', $e->getErrorCode());
            }
        } finally {
            $first->cleanup();
        }

        $third = $otherParser->parse($this->formRequest('third', 'abcd'));
        try {
            self::assertSame(4, $third->fileSize);
        } finally {
            $third->cleanup();
        }
    }

    public function test_budget_must_be_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PostObjectFormParser(
            $this->createMock(CredentialProvider::class),
            'us-east-1',
            1_024,
            spoolBudgetBytes: 0,
        );
    }

    public function test_server_config_preserves_and_validates_budget(): void
    {
        $config = new S3ServerConfig(storagePath: sys_get_temp_dir());
        self::assertSame(10_737_418_240, $config->postSpoolBudgetBytes);
        self::assertSame(123, $config->with(['postSpoolBudgetBytes' => 123])->postSpoolBudgetBytes);

        $this->expectException(\InvalidArgumentException::class);
        new S3ServerConfig(storagePath: sys_get_temp_dir(), postSpoolBudgetBytes: 0);
    }

    private function formRequest(string $key, string $body): Request
    {
        $boundary = 's3-budget-boundary';
        $multipart = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"key\"\r\n\r\n"
            . "{$key}\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"upload.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\n"
            . "{$body}\r\n"
            . "--{$boundary}--\r\n";
        $request = new Request($this->createMock(Client::class), 'POST', Http::new('http://127.0.0.1/source'));
        $request->setHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary);
        $request->setBody(new ReadableBuffer($multipart));
        $request->setAttribute('s3.bucket', 'source');

        return $request;
    }
}
