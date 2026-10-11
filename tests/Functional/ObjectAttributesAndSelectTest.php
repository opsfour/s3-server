<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\Credentials\Credentials;
use Aws\S3\Exception\S3Exception;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

final class ObjectAttributesAndSelectTest extends S3FunctionalTestCase
{
    private static string $bucket = '';

    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$seeded) {
            return;
        }

        self::$bucket = 'attributes-select-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => self::$bucket]);
        self::$seeded = true;
    }

    public function test_get_object_attributes_for_regular_object(): void
    {
        $body = 'object attributes body';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'attrs.txt',
            'Body' => $body,
            'StorageClass' => 'STANDARD_IA',
            'ChecksumAlgorithm' => 'SHA256',
        ]);

        $result = self::$s3->getObjectAttributes([
            'Bucket' => self::$bucket,
            'Key' => 'attrs.txt',
            'ObjectAttributes' => ['ETag', 'ObjectSize', 'StorageClass', 'Checksum'],
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertSame(strlen($body), (int) $result['ObjectSize']);
        $this->assertSame('STANDARD_IA', $result['StorageClass']);
        $this->assertNotEmpty($result['ETag']);
        $this->assertNotEmpty($result['Checksum']['ChecksumSHA256'] ?? null);
        $this->assertSame('FULL_OBJECT', $result['Checksum']['ChecksumType'] ?? null);
    }

    public function test_get_object_attributes_reports_missing_requested_version(): void
    {
        $bucket = 'attributes-version-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);
        $first = self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'present', 'Body' => 'first']);
        self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'present', 'Body' => 'newest']);

        $previous = self::$s3->getObjectAttributes([
            'Bucket' => $bucket,
            'Key' => 'present',
            'VersionId' => $first['VersionId'],
            'ObjectAttributes' => ['ObjectSize'],
        ]);
        self::assertSame(5, (int) $previous['ObjectSize']);
        self::assertSame($first['VersionId'], $previous['VersionId']);

        try {
            self::$s3->getObjectAttributes([
                'Bucket' => $bucket,
                'Key' => 'present',
                'VersionId' => 'missing-version',
                'ObjectAttributes' => ['ETag'],
            ]);
            self::fail('A missing requested version must not resolve as the current object.');
        } catch (S3Exception $exception) {
            self::assertSame(404, $exception->getStatusCode());
            self::assertSame('NoSuchVersion', $exception->getAwsErrorCode());
        }
    }

    public function test_get_object_attributes_reports_explicit_delete_marker_headers(): void
    {
        $bucket = 'attributes-marker-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);
        $object = self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'deleted', 'Body' => 'body']);
        $deleted = self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'deleted']);

        try {
            try {
                self::$s3->getObjectAttributes([
                    'Bucket' => $bucket,
                    'Key' => 'deleted',
                    'ObjectAttributes' => ['ETag'],
                ]);
                self::fail('A current delete marker must not return object attributes.');
            } catch (S3Exception $error) {
                self::assertSame('NoSuchKey', $error->getAwsErrorCode());
                self::assertSame(404, $error->getStatusCode());
                $response = $error->getResponse();
                self::assertNotNull($response);
                self::assertSame('true', $response->getHeaderLine('x-amz-delete-marker'));
                self::assertSame($deleted['VersionId'], $response->getHeaderLine('x-amz-version-id'));
            }

            try {
                self::$s3->getObjectAttributes([
                    'Bucket' => $bucket,
                    'Key' => 'deleted',
                    'VersionId' => $deleted['VersionId'],
                    'ObjectAttributes' => ['ETag'],
                ]);
                self::fail('An explicit delete marker must not return object attributes.');
            } catch (S3Exception $error) {
                self::assertSame('MethodNotAllowed', $error->getAwsErrorCode());
                self::assertSame(405, $error->getStatusCode());
                $response = $error->getResponse();
                self::assertNotNull($response);
                self::assertSame('true', $response->getHeaderLine('x-amz-delete-marker'));
                self::assertSame($deleted['VersionId'], $response->getHeaderLine('x-amz-version-id'));
                self::assertNotSame('', $response->getHeaderLine('last-modified'));
            }
        } finally {
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'deleted', 'VersionId' => $deleted['VersionId']]);
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'deleted', 'VersionId' => $object['VersionId']]);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_get_object_attributes_honors_signed_conditional_headers(): void
    {
        $key = 'attributes-condition-' . bin2hex(random_bytes(4));
        $put = self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => 'condition-body']);
        $url = sprintf('http://%s:%d/%s/%s?attributes', self::$host, self::$port, self::$bucket, $key);
        $signer = new S3SignatureV4('s3', 'us-east-1');
        $credentials = new Credentials(self::$accessKey, self::$secretKey);
        $client = new Client(['http_errors' => false, 'timeout' => 5]);

        foreach ([
            ['if-match' => '"missing-etag"', 'expected' => 412],
            ['if-none-match' => $put['ETag'], 'expected' => 304],
            ['if-match' => $put['ETag'], 'if-unmodified-since' => 'Sat, 01 Jan 2000 00:00:00 GMT', 'expected' => 200],
        ] as $case) {
            $expected = $case['expected'];
            unset($case['expected']);
            $signed = $signer->signRequest(new Request('GET', $url, [
                'x-amz-object-attributes' => 'ETag,ObjectSize',
                ...$case,
            ]), $credentials);
            $response = $client->send($signed);
            self::assertSame($expected, $response->getStatusCode(), (string) $response->getBody());
        }
    }

    public function test_get_object_attributes_for_multipart_object_reports_part_count(): void
    {
        $create = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => 'attrs-multipart.bin',
        ]);
        $uploadId = $create['UploadId'];

        $part1 = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => 'attrs-multipart.bin',
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => str_repeat('A', 5 * 1024 * 1024),
        ]);
        $part2 = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => 'attrs-multipart.bin',
            'UploadId' => $uploadId,
            'PartNumber' => 2,
            'Body' => 'tail',
        ]);

        self::$s3->completeMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => 'attrs-multipart.bin',
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => [
                    ['PartNumber' => 1, 'ETag' => $part1['ETag']],
                    ['PartNumber' => 2, 'ETag' => $part2['ETag']],
                ],
            ],
        ]);

        $result = self::$s3->getObjectAttributes([
            'Bucket' => self::$bucket,
            'Key' => 'attrs-multipart.bin',
            'ObjectAttributes' => ['ETag', 'ObjectSize', 'ObjectParts'],
        ]);

        $this->assertSame(5 * 1024 * 1024 + 4, (int) $result['ObjectSize']);
        $this->assertSame(2, $result['ObjectParts']['TotalPartsCount']);
    }

    public function test_select_object_content_over_csv_via_aws_sdk(): void
    {
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'people.csv',
            'Body' => "name,age\nAda,37\nBob,41\nCyd,29\n",
            'ContentType' => 'text/csv',
        ]);

        $result = self::$s3->selectObjectContent([
            'Bucket' => self::$bucket,
            'Key' => 'people.csv',
            'ExpressionType' => 'SQL',
            'Expression' => 'SELECT name FROM s3object s WHERE age > 35',
            'InputSerialization' => [
                'CSV' => [
                    'FileHeaderInfo' => 'USE',
                    'RecordDelimiter' => "\n",
                    'FieldDelimiter' => ',',
                ],
            ],
            'OutputSerialization' => [
                'CSV' => [
                    'RecordDelimiter' => "\n",
                    'FieldDelimiter' => ',',
                ],
            ],
        ]);

        $records = '';
        foreach ($result['Payload'] as $event) {
            if (isset($event['Records']['Payload'])) {
                $records .= (string) $event['Records']['Payload'];
            }
        }

        $this->assertStringContainsString('Ada', $records);
        $this->assertStringContainsString('Bob', $records);
        $this->assertStringNotContainsString('Cyd', $records);
    }

    public function test_select_qualified_projection_returns_values_and_unsupported_expression_is_rejected(): void
    {
        $key = 'select-projection.csv';
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => "name,age\nAda,37\n",
        ]);

        $result = self::$s3->selectObjectContent([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ExpressionType' => 'SQL',
            'Expression' => 'SELECT s.name, s.age FROM s3object s',
            'InputSerialization' => ['CSV' => ['FileHeaderInfo' => 'USE']],
            'OutputSerialization' => ['CSV' => []],
        ]);
        $records = '';
        foreach ($result['Payload'] as $event) {
            $records .= (string) ($event['Records']['Payload'] ?? '');
        }
        self::assertSame("Ada,37\n", $records);

        $expression = 'SELECT LOWER(s.name) FROM s3object s';
        $command = self::$s3->getCommand('SelectObjectContent', [
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ExpressionType' => 'SQL',
            'Expression' => $expression,
            'InputSerialization' => ['CSV' => ['FileHeaderInfo' => 'USE']],
            'OutputSerialization' => ['CSV' => []],
        ]);
        $url = (string) self::$s3->createPresignedRequest($command, '+5 minutes')->getUri();
        $xml = str_replace('SELECT * FROM s3object', $expression, $this->validSelectXml());
        $response = (new Client(['http_errors' => false, 'timeout' => 5]))->post($url, [
            'body' => $xml,
            'headers' => ['content-type' => 'application/xml'],
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('<Code>InvalidArgument</Code>', (string) $response->getBody());
    }

    public function test_anonymous_select_is_denied_even_for_a_public_object(): void
    {
        $key = 'public-select.csv';
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => "name\nAda\n",
            'ACL' => 'public-read',
        ]);

        $client = new Client(['http_errors' => false, 'timeout' => 5]);
        $url = sprintf('http://%s:%d/%s/%s', self::$host, self::$port, self::$bucket, $key);
        self::assertSame(200, $client->get($url)->getStatusCode());

        $response = $client->post($url . '?select&select-type=2', [
            'body' => $this->validSelectXml(),
            'headers' => ['content-type' => 'application/xml'],
        ]);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('<Code>AccessDenied</Code>', (string) $response->getBody());
    }

    public function test_select_rejects_empty_record_delimiter_and_wrong_root(): void
    {
        $key = 'select-validation.csv';
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => "name\nAda\n"]);
        $command = self::$s3->getCommand('SelectObjectContent', [
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ExpressionType' => 'SQL',
            'Expression' => 'SELECT * FROM s3object',
            'InputSerialization' => ['CSV' => []],
            'OutputSerialization' => ['CSV' => []],
        ]);
        $url = (string) self::$s3->createPresignedRequest($command, '+5 minutes')->getUri();
        $client = new Client(['http_errors' => false, 'timeout' => 5]);

        foreach ([
            str_replace("<RecordDelimiter>\n</RecordDelimiter>", '<RecordDelimiter></RecordDelimiter>', $this->validSelectXml()),
            str_replace('SelectObjectContentRequest', 'WrongRoot', $this->validSelectXml()),
            str_replace('<InputSerialization><CSV>', '<InputSerialization><CompressionType>GZIP</CompressionType><CSV>', $this->validSelectXml()),
            str_replace('<InputSerialization><CSV>', '<InputSerialization><Parquet/><CSV>', $this->validSelectXml()),
        ] as $xml) {
            $response = $client->post($url, [
                'body' => $xml,
                'headers' => ['content-type' => 'application/xml'],
            ]);
            self::assertSame(400, $response->getStatusCode());
        }
    }

    public function test_select_xml_namespace_must_be_s3_or_absent_and_prefixed_s3_elements_work(): void
    {
        $key = 'select-namespaces.csv';
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => "name\nAda\n"]);
        $command = self::$s3->getCommand('SelectObjectContent', [
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ExpressionType' => 'SQL',
            'Expression' => 'SELECT * FROM s3object',
            'InputSerialization' => ['CSV' => []],
            'OutputSerialization' => ['CSV' => []],
        ]);
        $url = (string) self::$s3->createPresignedRequest($command, '+5 minutes')->getUri();
        $client = new Client(['http_errors' => false, 'timeout' => 5]);
        $valid = $this->validSelectXml();

        $unknownRoot = str_replace('http://s3.amazonaws.com/doc/2006-03-01/', 'http://example.test/foreign', $valid);
        $foreignChild = str_replace('<InputSerialization><CSV>', '<InputSerialization xmlns:x="http://example.test/foreign"><x:Unexpected/><CSV>', $valid);
        $nestedScalarCases = [
            str_replace('<Expression>SELECT * FROM s3object</Expression>', '<Expression>SELECT * FROM s3object<x:Unexpected xmlns:x="urn:foreign"/></Expression>', $valid),
            str_replace('<ExpressionType>SQL</ExpressionType>', '<ExpressionType>SQL<x:Unexpected xmlns:x="urn:foreign"/></ExpressionType>', $valid),
            str_replace('<InputSerialization><CSV>', '<InputSerialization><CompressionType>NONE<x:Unexpected xmlns:x="urn:foreign"/></CompressionType><CSV>', $valid),
            str_replace('<InputSerialization><CSV>', '<InputSerialization><CSV><FileHeaderInfo>NONE<x:Unexpected xmlns:x="urn:foreign"/></FileHeaderInfo>', $valid),
            str_replace('<InputSerialization><CSV>', '<InputSerialization><CSV><FieldDelimiter>,<x:Unexpected xmlns:x="urn:foreign"/></FieldDelimiter>', $valid),
            str_replace("<RecordDelimiter>\n</RecordDelimiter>", "<RecordDelimiter>\n<x:Unexpected xmlns:x=\"urn:foreign\"/></RecordDelimiter>", $valid),
            str_replace('<OutputSerialization><CSV/>', '<OutputSerialization><CSV><QuoteCharacter>"<x:Unexpected xmlns:x="urn:foreign"/></QuoteCharacter></CSV>', $valid),
            str_replace('<InputSerialization><CSV><RecordDelimiter>' . "\n" . '</RecordDelimiter></CSV>', '<InputSerialization><JSON><Type>LINES<x:Unexpected xmlns:x="urn:foreign"/></Type></JSON>', $valid),
        ];
        foreach ([$unknownRoot, $foreignChild, ...$nestedScalarCases] as $xml) {
            $response = $client->post($url, ['body' => $xml, 'headers' => ['content-type' => 'application/xml']]);
            self::assertSame(400, $response->getStatusCode());
            self::assertStringContainsString('<Code>MalformedXML</Code>', (string) $response->getBody());
        }

        $prefixed = str_replace(' xmlns="http://s3.amazonaws.com/doc/2006-03-01/"', ' xmlns:s3="http://s3.amazonaws.com/doc/2006-03-01/"', $valid);
        $prefixed = preg_replace('/<(\/)?(SelectObjectContentRequest|Expression|ExpressionType|InputSerialization|CSV|RecordDelimiter|OutputSerialization)(?=[\s>\/])/', '<$1s3:$2', $prefixed);
        self::assertNotNull($prefixed);
        $response = $client->post($url, ['body' => $prefixed, 'headers' => ['content-type' => 'application/xml']]);
        self::assertSame(200, $response->getStatusCode());
    }

    public function test_select_invalid_json_input_returns_a_client_error_inline(): void
    {
        $key = 'select-invalid.json';
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => '{invalid json']);
        $command = self::$s3->getCommand('SelectObjectContent', [
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ExpressionType' => 'SQL',
            'Expression' => 'SELECT * FROM s3object',
            'InputSerialization' => ['JSON' => ['Type' => 'DOCUMENT']],
            'OutputSerialization' => ['JSON' => []],
        ]);
        $url = (string) self::$s3->createPresignedRequest($command, '+5 minutes')->getUri();
        $xml = str_replace(
            '<InputSerialization><CSV><RecordDelimiter>' . "\n" . '</RecordDelimiter></CSV></InputSerialization>',
            '<InputSerialization><JSON><Type>DOCUMENT</Type></JSON></InputSerialization>',
            $this->validSelectXml(),
        );
        $xml = str_replace('<OutputSerialization><CSV/></OutputSerialization>', '<OutputSerialization><JSON/></OutputSerialization>', $xml);

        $response = (new Client(['http_errors' => false, 'timeout' => 5]))->post($url, [
            'body' => $xml,
            'headers' => ['content-type' => 'application/xml'],
        ]);
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('<Code>InvalidArgument</Code>', (string) $response->getBody());
        self::assertStringContainsString('Invalid JSON input for S3 Select.', (string) $response->getBody());
    }

    public function test_select_unterminated_csv_returns_sanitized_client_error(): void
    {
        $key = 'select-invalid.csv';
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => "name,note\nAda,\"secret unfinished\n"]);
        $command = self::$s3->getCommand('SelectObjectContent', [
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ExpressionType' => 'SQL',
            'Expression' => 'SELECT * FROM s3object',
            'InputSerialization' => ['CSV' => []],
            'OutputSerialization' => ['CSV' => []],
        ]);
        $url = (string) self::$s3->createPresignedRequest($command, '+5 minutes')->getUri();
        $response = (new Client(['http_errors' => false, 'timeout' => 5]))->post($url, [
            'body' => $this->validSelectXml(),
            'headers' => ['content-type' => 'application/xml'],
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('<Code>InvalidArgument</Code>', (string) $response->getBody());
        self::assertStringContainsString('Invalid CSV input or serialization for S3 Select.', (string) $response->getBody());
        self::assertStringNotContainsString('secret unfinished', (string) $response->getBody());
    }

    public function test_select_large_result_reassembles_multiple_records_frames_via_aws_sdk(): void
    {
        $key = 'select-many-records.csv';
        $content = str_repeat("abcdefghijklmno\n", 10_000);
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => $content]);

        $result = self::$s3->selectObjectContent([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ExpressionType' => 'SQL',
            'Expression' => 'SELECT * FROM s3object',
            'InputSerialization' => ['CSV' => ['FileHeaderInfo' => 'NONE']],
            'OutputSerialization' => ['CSV' => []],
        ]);

        $records = '';
        $recordFrames = 0;
        foreach ($result['Payload'] as $event) {
            if (isset($event['Records']['Payload'])) {
                ++$recordFrames;
                $records .= (string) $event['Records']['Payload'];
            }
        }

        self::assertGreaterThan(1, $recordFrames);
        self::assertSame($content, $records);
    }

    public function test_select_rejects_oversized_input_and_result_records(): void
    {
        $client = new Client(['http_errors' => false, 'timeout' => 10]);
        foreach ([
            ['key' => 'select-oversize-input.csv', 'body' => str_repeat('x', 1_048_577) . "\n", 'expression' => 'SELECT * FROM s3object'],
            ['key' => 'select-oversize-result.csv', 'body' => 'v' . "\n" . str_repeat('x', 600_000) . "\n", 'expression' => 'SELECT v AS first, v AS second FROM s3object s'],
        ] as $case) {
            self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $case['key'], 'Body' => $case['body']]);
            $command = self::$s3->getCommand('SelectObjectContent', [
                'Bucket' => self::$bucket,
                'Key' => $case['key'],
                'ExpressionType' => 'SQL',
                'Expression' => $case['expression'],
                'InputSerialization' => ['CSV' => []],
                'OutputSerialization' => ['CSV' => []],
            ]);
            $url = (string) self::$s3->createPresignedRequest($command, '+5 minutes')->getUri();
            $xml = str_replace('SELECT * FROM s3object', $case['expression'], $this->validSelectXml());
            if ($case['key'] === 'select-oversize-result.csv') {
                $xml = str_replace('<CSV><RecordDelimiter>', '<CSV><FileHeaderInfo>USE</FileHeaderInfo><RecordDelimiter>', $xml);
            }
            $response = $client->post($url, ['body' => $xml, 'headers' => ['content-type' => 'application/xml']]);

            self::assertSame(400, $response->getStatusCode(), $case['key']);
            self::assertStringContainsString('<Code>OverMaxRecordSize</Code>', (string) $response->getBody());
            self::assertStringNotContainsString('xxxx', (string) $response->getBody());
        }
    }

    public function test_select_rejects_small_row_memory_amplification_and_stays_available(): void
    {
        $key = 'select-many-short-rows.csv';
        $content = str_repeat("a,b\n", 500_000);
        self::assertSame(2_000_000, strlen($content));
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => $content]);

        $command = self::$s3->getCommand('SelectObjectContent', [
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ExpressionType' => 'SQL',
            'Expression' => 'SELECT * FROM s3object',
            'InputSerialization' => ['CSV' => []],
            'OutputSerialization' => ['CSV' => []],
        ]);
        $url = (string) self::$s3->createPresignedRequest($command, '+5 minutes')->getUri();
        $client = new Client(['http_errors' => false, 'timeout' => 15]);
        $response = $client->post($url, [
            'body' => $this->validSelectXml(),
            'headers' => ['content-type' => 'application/xml'],
        ]);

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('<Code>SlowDown</Code>', (string) $response->getBody());

        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => 'select-after-limit.csv', 'Body' => "a,b\n"]);
        $result = self::$s3->selectObjectContent([
            'Bucket' => self::$bucket,
            'Key' => 'select-after-limit.csv',
            'ExpressionType' => 'SQL',
            'Expression' => 'SELECT * FROM s3object',
            'InputSerialization' => ['CSV' => []],
            'OutputSerialization' => ['CSV' => []],
        ]);
        $records = '';
        foreach ($result['Payload'] as $event) {
            $records .= (string) ($event['Records']['Payload'] ?? '');
        }
        self::assertSame("a,b\n", $records);
    }

    public function test_select_rejects_excessive_or_chain_and_invalid_where_without_losing_service(): void
    {
        $key = 'select-sql-admission.csv';
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => "0\n"]);

        $client = new Client(['http_errors' => false, 'timeout' => 10]);
        foreach ([
            'SELECT * FROM s3object WHERE ' . implode(' OR ', array_fill(0, 2_500, '_1=1')),
            'SELECT * FROM s3object WHERE nonsense',
            'SELECT * FROM s3object WHERE _1 =',
            'SELECT * FROM s3object WHERE _1 = 0 OR nonsense',
        ] as $expression) {
            $command = self::$s3->getCommand('SelectObjectContent', [
                'Bucket' => self::$bucket,
                'Key' => $key,
                'ExpressionType' => 'SQL',
                'Expression' => $expression,
                'InputSerialization' => ['CSV' => []],
                'OutputSerialization' => ['CSV' => []],
            ]);
            $url = (string) self::$s3->createPresignedRequest($command, '+5 minutes')->getUri();
            $xml = str_replace('SELECT * FROM s3object', $expression, $this->validSelectXml());
            $response = $client->post($url, ['body' => $xml, 'headers' => ['content-type' => 'application/xml']]);

            self::assertSame(400, $response->getStatusCode());
            self::assertStringContainsString('<Code>InvalidArgument</Code>', (string) $response->getBody());
        }

        self::assertSame(200, self::$s3->headObject(['Bucket' => self::$bucket, 'Key' => $key])['@metadata']['statusCode']);
    }

    public function test_select_rejects_invalid_where_even_when_input_has_no_rows(): void
    {
        $key = 'select-empty-invalid-sql.csv';
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => '']);

        $expression = 'SELECT * FROM s3object WHERE missing IS NULL OR nonsense';
        $command = self::$s3->getCommand('SelectObjectContent', [
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ExpressionType' => 'SQL',
            'Expression' => $expression,
            'InputSerialization' => ['CSV' => []],
            'OutputSerialization' => ['CSV' => []],
        ]);
        $url = (string) self::$s3->createPresignedRequest($command, '+5 minutes')->getUri();
        $xml = str_replace('SELECT * FROM s3object', $expression, $this->validSelectXml());
        $response = (new Client(['http_errors' => false, 'timeout' => 10]))->post($url, [
            'body' => $xml,
            'headers' => ['content-type' => 'application/xml'],
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('<Code>InvalidArgument</Code>', (string) $response->getBody());
    }

    public function test_select_rejects_result_expansion_before_exhausting_memory(): void
    {
        $key = 'select-result-expansion.csv';
        $content = str_repeat(str_repeat('x', 1024) . "\n", 5_000);
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => $content]);

        $expression = 'SELECT ' . implode(', ', array_map(
            static fn(int $index): string => '_1 AS c' . $index,
            range(1, 8),
        )) . ' FROM s3object';
        $command = self::$s3->getCommand('SelectObjectContent', [
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ExpressionType' => 'SQL',
            'Expression' => $expression,
            'InputSerialization' => ['CSV' => []],
            'OutputSerialization' => ['CSV' => []],
        ]);
        $url = (string) self::$s3->createPresignedRequest($command, '+5 minutes')->getUri();
        $xml = str_replace('SELECT * FROM s3object', $expression, $this->validSelectXml());
        $response = (new Client(['http_errors' => false, 'timeout' => 15]))->post($url, [
            'body' => $xml,
            'headers' => ['content-type' => 'application/xml'],
        ]);

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('<Code>SlowDown</Code>', (string) $response->getBody());
        self::assertSame(200, self::$s3->headObject(['Bucket' => self::$bucket, 'Key' => $key])['@metadata']['statusCode']);
    }

    private function validSelectXml(): string
    {
        return '<SelectObjectContentRequest xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Expression>SELECT * FROM s3object</Expression><ExpressionType>SQL</ExpressionType>'
            . "<InputSerialization><CSV><RecordDelimiter>\n</RecordDelimiter></CSV></InputSerialization>"
            . '<OutputSerialization><CSV/></OutputSerialization></SelectObjectContentRequest>';
    }

    public function test_restore_object_sdk_route_rejects_non_archive_objects(): void
    {
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'standard-restore.txt',
            'Body' => 'not archived',
        ]);

        try {
            self::$s3->restoreObject([
                'Bucket' => self::$bucket,
                'Key' => 'standard-restore.txt',
                'RestoreRequest' => ['Days' => 1],
            ]);
            self::fail('Expected RestoreObject to reject a STANDARD object.');
        } catch (S3Exception $e) {
            self::assertSame(403, $e->getStatusCode());
            self::assertSame('InvalidObjectState', $e->getAwsErrorCode());
        }
    }

    public function test_restore_object_sdk_route_reports_missing_explicit_version(): void
    {
        $bucket = 'restore-version-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);
        self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'present', 'Body' => 'body']);

        try {
            self::$s3->restoreObject([
                'Bucket' => $bucket,
                'Key' => 'present',
                'VersionId' => 'missing-version',
                'RestoreRequest' => ['Days' => 1],
            ]);
            self::fail('An unknown explicit version must not start a restore.');
        } catch (S3Exception $exception) {
            self::assertSame(404, $exception->getStatusCode());
            self::assertSame('NoSuchVersion', $exception->getAwsErrorCode());
        }
    }
}
