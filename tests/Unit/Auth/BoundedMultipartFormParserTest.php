<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\FormParser\StreamingFormParser;
use Amp\Http\Server\HttpErrorException;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Auth\BoundedMultipartFormParser;
use OpsFour\S3Server\Auth\PostObjectFormParser;
use OpsFour\S3Server\Auth\PostObjectSpoolCoordinator;
use OpsFour\S3Server\Auth\PostObjectTempFileSweeper;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use PHPUnit\Framework\TestCase;

final class BoundedMultipartFormParserTest extends TestCase
{
    /** @param list<string> $names */
    #[\PHPUnit\Framework\Attributes\DataProvider('duplicateChecksumFormFieldNames')]
    public function test_checksum_form_field_names_are_unique_case_insensitively(array $names): void
    {
        $body = '';
        foreach ($names as $index => $name) {
            $body .= "--guard-boundary\r\n"
                . 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n"
                . "value-{$index}\r\n";
        }
        $body .= "--guard-boundary--\r\n";
        $parser = new PostObjectFormParser($this->createMock(CredentialProvider::class), 'us-east-1', 1_048_576);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('POST Object form field names must be non-empty and unique.');
        $parser->parse($this->request(new ReadableBuffer($body), 'guard-boundary'));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function duplicateChecksumFormFieldNames(): iterable
    {
        yield 'Content-MD5 lower then upper' => [['content-md5', 'Content-MD5']];
        yield 'Content-MD5 upper then lower' => [['Content-MD5', 'content-md5']];
        yield 'SDK checksum lower then mixed' => [['x-amz-checksum-sha256', 'X-Amz-Checksum-Sha256']];
        yield 'SDK checksum mixed then lower' => [['X-Amz-Checksum-Sha256', 'x-amz-checksum-sha256']];
    }

    public function test_repeated_content_type_is_rejected_before_form_parsing(): void
    {
        $request = $this->request(new ReadableBuffer(''), 'guard-boundary');
        $request->setHeader('Content-Type', [
            'multipart/form-data; boundary=guard-boundary',
            'multipart/form-data; boundary=other-boundary',
        ]);
        $parser = new PostObjectFormParser($this->createMock(CredentialProvider::class), 'us-east-1', 1_048_576);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('content-type must be supplied at most once.');
        $parser->parse($request);
    }

    public function test_unterminated_first_part_header_is_rejected_before_spooling(): void
    {
        $body = "--guard-boundary\r\nX-Long: " . str_repeat('a', 20_000);
        $this->assertParserRejectsOversizedHeader($body);
    }

    public function test_missing_boundary_is_rejected_before_form_parsing(): void
    {
        $request = $this->request(new ReadableBuffer('name=' . str_repeat('a', 20_000)), 'guard-boundary');
        $request->setHeader('Content-Type', 'multipart/form-data');
        $parser = new PostObjectFormParser($this->createMock(CredentialProvider::class), 'us-east-1', 1_048_576);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('POST Object requires a multipart boundary.');
        $parser->parse($request);
    }

    public function test_boundary_longer_than_rfc_limit_is_rejected_before_scanning(): void
    {
        $boundary = str_repeat('x', 71);
        $parser = new PostObjectFormParser($this->createMock(CredentialProvider::class), 'us-east-1', 1_048_576);

        try {
            $parser->parse($this->request(new ReadableBuffer(''), $boundary));
            self::fail('A 71-byte multipart boundary must be rejected.');
        } catch (InvalidArgumentException $error) {
            self::assertSame(400, $error->getHttpStatus());
            self::assertSame('POST Object requires a valid multipart boundary.', $error->getMessage());
        }

    }

    public function test_long_boundary_header_is_rejected_before_regex_parsing(): void
    {
        $parser = new PostObjectFormParser($this->createMock(CredentialProvider::class), 'us-east-1', 1_048_576);
        $request = $this->request(new ReadableBuffer(''), str_repeat('x', 2_048));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('POST Object requires a valid multipart boundary.');
        $parser->parse($request);
    }

    public function test_oversized_header_after_a_valid_field_is_rejected_before_spooling(): void
    {
        $body = "--guard-boundary\r\n"
            . "Content-Disposition: form-data; name=\"key\"\r\n\r\nobject\r\n"
            . "--guard-boundary\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"object.txt\"\r\n"
            . 'X-Long: ' . str_repeat('a', 20_000);
        $this->assertParserRejectsOversizedHeader($body);
    }

    public function test_completed_header_one_byte_over_limit_is_rejected(): void
    {
        $prefix = "Content-Disposition: form-data; name=\"file\"; filename=\"o\"\r\nX-Pad: ";
        $header = $prefix . str_repeat('a', BoundedMultipartFormParser::MAX_HEADER_BYTES - strlen($prefix) - 3) . "\r\n\r\n";
        self::assertSame(BoundedMultipartFormParser::MAX_HEADER_BYTES + 1, strlen($header));
        $body = "--guard-boundary\r\n" . $header . "payload\r\n--guard-boundary--\r\n";

        $this->assertParserRejectsOversizedHeader($body);
    }

    public function test_malformed_closing_boundary_cannot_hide_an_unbounded_header(): void
    {
        $body = "--guard-boundary\r\n"
            . "Content-Disposition: form-data; name=\"key\"\r\n\r\nobject\r\n"
            . "--guard-boundary--ab"
            . str_repeat('a', 20_000);

        $parser = new PostObjectFormParser($this->createMock(CredentialProvider::class), 'us-east-1', 1_048_576);
        try {
            $parser->parse($this->request(new ReadableIterableStream(str_split($body, 509)), 'guard-boundary'));
            self::fail('A form without a valid closing delimiter must be rejected.');
        } catch (HttpErrorException $error) {
            self::assertSame(400, $error->getStatus());
            self::assertStringContainsString('Request body ended unexpectedly', $error->getMessage());
        }
    }

    public function test_false_boundary_in_file_data_roundtrips_across_chunk_splits(): void
    {
        $file = "before\r\n--guard-boundaryXXXX" . str_repeat('a', 20_000) . 'after';
        $body = "--guard-boundary\r\n"
            . "Content-Disposition: form-data; name=\"key\"\r\n\r\nobject\r\n"
            . "--guard-boundary\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"o\"\r\n\r\n"
            . $file . "\r\n--guard-boundary--\r\n";

        foreach ([1, 17, 509, 65_536] as $chunkSize) {
            $parser = new PostObjectFormParser($this->createMock(CredentialProvider::class), 'us-east-1', 1_048_576);
            $form = $parser->parse($this->request(new ReadableIterableStream(str_split($body, $chunkSize)), 'guard-boundary'));
            try {
                self::assertSame($file, \Amp\ByteStream\buffer($form->openFile()));
            } finally {
                $form->cleanup();
            }
        }
    }

    public function test_many_false_delimiters_stream_without_changing_eight_mib_of_file_data(): void
    {
        $pattern = "part\r\n--guard-boundaryXXXX";
        $file = substr(str_repeat($pattern, (int) ceil(8_388_608 / strlen($pattern))), 0, 8_388_608);
        $body = "--guard-boundary\r\n"
            . "Content-Disposition: form-data; name=\"key\"\r\n\r\nobject\r\n"
            . "--guard-boundary\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"o\"\r\n\r\n"
            . $file . "\r\n--guard-boundary--\r\n";
        $chunks = (static function () use ($body): \Generator {
            for ($offset = 0, $size = strlen($body); $offset < $size; $offset += 65_536) {
                yield substr($body, $offset, 65_536);
            }
        })();
        $parser = new BoundedMultipartFormParser(new ReadableIterableStream($chunks), 'guard-boundary', strlen($body));
        $fileHash = hash_init('sha256');
        $fileBytes = 0;
        foreach ($parser->parse() as $field) {
            if (! $field->isFile()) {
                self::assertSame('object', $field->buffer());

                continue;
            }
            while (($chunk = $field->read()) !== null) {
                hash_update($fileHash, $chunk);
                $fileBytes += strlen($chunk);
            }
        }
        self::assertSame(strlen($file), $fileBytes);
        self::assertSame(hash('sha256', $file), hash_final($fileHash));
    }

    public function test_epilog_is_drained_and_counts_against_total_body_limit(): void
    {
        $body = "--guard-boundary\r\n"
            . "Content-Disposition: form-data; name=\"key\"\r\n\r\nobject\r\n"
            . "--guard-boundary--\r\n";
        $epilog = str_repeat('e', 256);
        $chunks = [$body, $epilog];

        $parser = new BoundedMultipartFormParser(new ReadableIterableStream($chunks), 'guard-boundary', strlen($body) + strlen($epilog));
        foreach ($parser->parse() as $field) {
            self::assertSame('object', $field->buffer());
        }

        $parser = new BoundedMultipartFormParser(new ReadableIterableStream($chunks), 'guard-boundary', strlen($body) + 255);
        try {
            foreach ($parser->parse() as $field) {
                $field->buffer();
            }
            self::fail('Epilog bytes must count against the request limit.');
        } catch (EntityTooLargeException $error) {
            self::assertSame(400, $error->getHttpStatus());
        }
    }

    public function test_repeated_empty_body_reads_fail_without_spinning(): void
    {
        $parser = new BoundedMultipartFormParser(
            new ReadableIterableStream(array_fill(0, 16, '')),
            'guard-boundary',
            1_048_576,
        );

        $this->expectException(HttpErrorException::class);
        $this->expectExceptionMessage('Request body made no progress');
        foreach ($parser->parse() as $field) {
            $field->buffer();
        }
    }

    public function test_closing_boundary_requires_exact_dash_dash_crlf_suffix(): void
    {
        $field = "--guard-boundary\r\n"
            . "Content-Disposition: form-data; name=\"key\"\r\n\r\nobject\r\n";
        foreach (["--guard-boundary--", "--guard-boundary-- \t\r\n"] as $ending) {
            $body = $field . $ending;
            $parser = new BoundedMultipartFormParser(new ReadableBuffer($body), 'guard-boundary', strlen($body));
            try {
                foreach ($parser->parse() as $part) {
                    $part->buffer();
                }
                self::fail('A malformed closing delimiter must be rejected.');
            } catch (HttpErrorException $error) {
                self::assertSame(400, $error->getStatus());
            }
        }
    }

    public function test_interrupted_file_stream_removes_encrypted_spool(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-post-interrupted-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700));
        $chunks = (static function (): \Generator {
            yield "--guard-boundary\r\n"
                . "Content-Disposition: form-data; name=\"key\"\r\n\r\nobject\r\n"
                . "--guard-boundary\r\n"
                . "Content-Disposition: form-data; name=\"file\"; filename=\"o\"\r\n\r\n";
            yield str_repeat('x', 70_000);
            throw new \RuntimeException('source disconnected');
        })();

        try {
            $parser = new PostObjectFormParser(
                $this->createMock(CredentialProvider::class),
                'us-east-1',
                1_048_576,
                new PostObjectTempFileSweeper(tempDir: $tempDir),
                spoolCoordinator: new PostObjectSpoolCoordinator($tempDir),
            );
            try {
                $parser->parse($this->request(new ReadableIterableStream($chunks), 'guard-boundary'));
                self::fail('An interrupted file stream must fail.');
            } catch (\RuntimeException $error) {
                self::assertSame('source disconnected', $error->getMessage());
                self::assertSame([], glob($tempDir . '/s3-post-*') ?: []);
            }
        } finally {
            foreach (glob($tempDir . '/.opsfour-s3-post-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-post-budget');
            @rmdir($tempDir);
        }
    }

    public function test_invalid_first_boundary_is_rejected_upstream_before_any_later_part(): void
    {
        $body = "--wrong-boundary\r\n" . str_repeat('x', 20_000)
            . "\r\n--guard-boundary\r\nX-Long: " . str_repeat('y', 20_000);
        $request = $this->request(new ReadableIterableStream(str_split($body, 509)), 'guard-boundary');

        try {
            foreach ((new StreamingFormParser())->parseForm($request) as $field) {
                $field->buffer();
            }
            self::fail('Amp must reject an invalid first boundary.');
        } catch (HttpErrorException $error) {
            self::assertSame(400, $error->getStatus());
            self::assertStringContainsString('Invalid boundary', $error->getMessage());
        }

        $parser = new PostObjectFormParser($this->createMock(CredentialProvider::class), 'us-east-1', 1_048_576);
        try {
            $parser->parse($this->request(new ReadableIterableStream(str_split($body, 509)), 'guard-boundary'));
            self::fail('The bounded parser must reject an invalid first boundary.');
        } catch (HttpErrorException $error) {
            self::assertSame(400, $error->getStatus());
            self::assertStringContainsString('Invalid boundary', $error->getMessage());
        }
    }

    public function test_split_delimiters_and_headers_are_passed_through_unchanged(): void
    {
        $chunks = [
            '--guard', "-boundary\r", "\nContent-Disposition: form-data; name=\"key\"\r",
            "\n\r", "\nobject\r\n--guard-boun", "dary\r", "\nContent-Disposition: form-data; name=\"file\"; filename=\"o\"\r\n",
            "Content-Type: text/plain\r\n\r", "\nlarge file bytes\r\n--guard-", 'boundary--', "\r\n",
        ];
        try {
            foreach ((new StreamingFormParser())->parseForm(
                $this->request(new ReadableIterableStream($chunks), 'guard-boundary'),
            ) as $field) {
                $field->buffer();
            }
            self::fail('The installed Amp parser must reproduce the split closing-boundary error.');
        } catch (HttpErrorException|\ValueError $error) {
            if ($error instanceof HttpErrorException) {
                self::assertSame(400, $error->getStatus());
            }
        }

        $parser = new PostObjectFormParser($this->createMock(CredentialProvider::class), 'us-east-1', 1_048_576);
        $form = $parser->parse($this->request(new ReadableIterableStream($chunks), 'guard-boundary'));
        try {
            self::assertSame('object', $form->key);
            self::assertSame('large file bytes', \Amp\ByteStream\buffer($form->openFile()));
        } finally {
            $form->cleanup();
        }
    }

    public function test_exact_header_limit_accepts_a_quoted_boundary_and_file(): void
    {
        $headerPrefix = "Content-Disposition: form-data; name=\"file\"; filename=\"o\"\r\nX-Pad: ";
        $header = $headerPrefix . str_repeat('a', BoundedMultipartFormParser::MAX_HEADER_BYTES - strlen($headerPrefix) - 4) . "\r\n\r\n";
        self::assertSame(BoundedMultipartFormParser::MAX_HEADER_BYTES, strlen($header));
        $body = "--guard-boundary\r\n"
            . "Content-Disposition: form-data; name=\"key\"\r\n\r\nobject\r\n"
            . "--guard-boundary\r\n"
            . $header
            . "payload\r\n--guard-boundary--\r\n";

        $parser = new PostObjectFormParser($this->createMock(CredentialProvider::class), 'us-east-1', 1_048_576);
        $form = $parser->parse($this->request(new ReadableIterableStream(str_split($body, 509)), '"guard-boundary"'));
        try {
            self::assertSame('object', $form->key);
            self::assertSame('payload', \Amp\ByteStream\buffer($form->openFile()));
        } finally {
            $form->cleanup();
        }
    }

    private function assertParserRejectsOversizedHeader(string $body): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-post-header-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700));
        try {
            $parser = new PostObjectFormParser(
                $this->createMock(CredentialProvider::class),
                'us-east-1',
                1_048_576,
                new PostObjectTempFileSweeper(tempDir: $tempDir),
                spoolCoordinator: new PostObjectSpoolCoordinator($tempDir),
            );
            try {
                $parser->parse($this->request(new ReadableIterableStream(str_split($body, 509)), 'guard-boundary'));
                self::fail('The oversized part header must be rejected.');
            } catch (InvalidArgumentException $error) {
                self::assertSame(400, $error->getHttpStatus());
                self::assertSame('Multipart part header exceeds 16 KiB.', $error->getMessage());
                self::assertSame([], glob($tempDir . '/s3-post-*') ?: []);
            }
        } finally {
            foreach (glob($tempDir . '/.opsfour-s3-post-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-post-budget');
            @rmdir($tempDir);
        }
    }

    private function request(\Amp\ByteStream\ReadableStream $body, string $boundary): Request
    {
        $request = new Request($this->createMock(Client::class), 'POST', Http::new('http://127.0.0.1/source'));
        $request->setHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary);
        $request->setBody($body);
        $request->setAttribute('s3.bucket', 'source');

        return $request;
    }
}
