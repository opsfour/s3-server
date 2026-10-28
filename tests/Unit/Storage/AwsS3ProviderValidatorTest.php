<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OpsFour\S3Server\Storage\AwsS3ProviderAdmission;
use OpsFour\S3Server\Storage\AwsS3ProviderProfile;
use OpsFour\S3Server\Storage\AwsS3ProviderValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class AwsS3ProviderValidatorTest extends TestCase
{
    private const string OWNER = '123456789012';

    public function test_valid_general_purpose_bucket_is_admitted_by_read_only_owner_scoped_probes(): void
    {
        $requests = [];
        $client = self::client([
            new Response(200, ['x-amz-bucket-region' => 'us-east-1']),
            new Response(200, [], '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>'),
        ], $requests);

        $admission = (new AwsS3ProviderValidator())->validate(self::profile(), static fn() => $client);

        self::assertTrue($admission->configurationValid);
        self::assertTrue($admission->ownerCheckPassed);
        self::assertTrue($admission->providerAdmitted);
        self::assertSame('aws-s3', $admission->providerType());
        self::assertSame('us-east-1', $admission->configuredRegion);
        self::assertSame(AwsS3ProviderAdmission::VERSIONING_DISABLED, $admission->versioningState);
        self::assertSame('admitted', $admission->reason);
        self::assertFalse($admission->fenceComplete());
        self::assertFalse($admission->productionQualified());
        self::assertSame([
            'provider_type',
            'configured_region',
            'configuration_valid',
            'owner_check_passed',
            'versioning_state',
            'provider_admitted',
            'fence_complete',
            'production_qualified',
            'reason',
        ], array_keys($admission->toArray()));

        self::assertCount(2, $requests);
        self::assertSame(['HEAD', 'GET'], array_map(static fn(RequestInterface $request): string => $request->getMethod(), $requests));
        self::assertSame(self::OWNER, $requests[0]->getHeaderLine('x-amz-expected-bucket-owner'));
        self::assertSame(self::OWNER, $requests[1]->getHeaderLine('x-amz-expected-bucket-owner'));
        self::assertSame('versioning', $requests[1]->getUri()->getQuery());
    }

    public function test_invalid_configuration_never_constructs_a_client_or_sends_a_request(): void
    {
        $called = false;
        $admission = (new AwsS3ProviderValidator())->validate([
            'bucket' => 'arn:aws:s3:::secret-bucket',
            'region' => 'us-east-1',
            'expected_owner_id' => self::OWNER,
            'data_prefix' => 'data',
            'reserved_prefix' => 'data/attempts',
        ], static function () use (&$called): S3Client {
            $called = true;
            throw new \LogicException('must not be called');
        });

        self::assertFalse($called);
        self::assertFalse($admission->configurationValid);
        self::assertFalse($admission->providerAdmitted);
        self::assertSame('configuration-invalid', $admission->reason);
        self::assertSame('us-east-1', $admission->configuredRegion);
        self::assertStringNotContainsString('secret-bucket', $admission->reason);
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidConfigurations')]
    public function test_local_profile_matrix_is_rejected_before_provider_access(array $overrides): void
    {
        $config = [
            'bucket' => 'provider-bucket',
            'region' => 'us-east-1',
            'expected_owner_id' => self::OWNER,
            'data_prefix' => 'objects',
            'reserved_prefix' => '.opsfour/attempts',
        ];
        $called = false;

        $admission = (new AwsS3ProviderValidator())->validate($overrides + $config, static function () use (&$called): never {
            $called = true;
            throw new \LogicException('must not be called');
        });

        self::assertFalse($called);
        self::assertFalse($admission->configurationValid);
        self::assertSame('configuration-invalid', $admission->reason);
        if (isset($overrides['region']) && is_string($overrides['region'])
            && ! AwsS3ProviderProfile::isKnownCommercialRegion($overrides['region'])) {
            self::assertSame('invalid', $admission->configuredRegion);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'owner' => [['expected_owner_id' => 'owner-secret']];
        yield 'partition' => [['region' => 'us-gov-west-1']];
        yield 'unknown region' => [['region' => 'us-moon-1']];
        yield 'bucket ARN' => [['bucket' => 'arn:aws:s3:::bucket']];
        yield 'TLS-incompatible dotted bucket' => [['bucket' => 'provider.bucket']];
        yield 'access point alias' => [['bucket' => 'alias-s3alias']];
        yield 'MRAP alias' => [['bucket' => 'alias.mrap']];
        yield 'directory bucket' => [['bucket' => 'bucket--use1-az1--x-s3']];
        yield 'overlapping prefix' => [['reserved_prefix' => 'objects/attempts']];
        yield 'malformed UTF-8 data prefix' => [['data_prefix' => "objects-\xC3\x28"]];
        yield 'malformed UTF-8 reserved prefix' => [['reserved_prefix' => ".opsfour-\xC3\x28"]];
        yield 'reserved prefix exceeds derived-key budget' => [['reserved_prefix' => str_repeat('r', 701)]];
        yield 'custom endpoint' => [['endpoint' => 'https://s3.example.test']];
        yield 'inline credentials' => [['credentials' => ['key' => 'secret', 'secret' => 'secret']]];
        yield 'path style' => [['use_path_style_endpoint' => true]];
        yield 'retry override' => [['retries' => 0]];
        yield 'unbounded timeout' => [['request_timeout_seconds' => 0]];
    }

    /** @param list<Response|\Throwable> $responses */
    #[DataProvider('deniedProviderStates')]
    public function test_unsupported_provider_state_is_denied_with_a_closed_reason(
        array $responses,
        string $reason,
        string $versioningState,
        int $requestCount,
    ): void {
        $requests = [];
        $client = self::client($responses, $requests);
        $admission = (new AwsS3ProviderValidator())->validate(self::profile(), static fn() => $client);

        self::assertFalse($admission->providerAdmitted);
        self::assertSame($reason, $admission->reason);
        self::assertSame($versioningState, $admission->versioningState);
        self::assertCount($requestCount, $requests);
        self::assertStringNotContainsString('backing-private', $admission->reason);
        self::assertStringNotContainsString(self::OWNER, $admission->reason);
        self::assertStringNotContainsString(
            'private SDK detail',
            json_encode($admission->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    /** @return iterable<string, array{list<Response|\Throwable>, string, string, int}> */
    public static function deniedProviderStates(): iterable
    {
        yield 'inaccessible or wrong owner' => [[
            new Response(403, [], '<Error><Code>AccessDenied</Code><Message>private SDK detail</Message></Error>'),
        ], 'owner-or-access-check-failed', AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED, 1];
        yield 'wrong region result' => [[
            new Response(200, ['x-amz-bucket-region' => 'eu-west-1']),
        ], 'region-mismatch', AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED, 1];
        yield 'wrong region exception' => [[
            new Response(301, [
                'x-amz-bucket-region' => 'eu-west-1',
            ], '<Error><Code>PermanentRedirect</Code><Message>private SDK detail</Message></Error>'),
        ], 'region-mismatch', AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED, 1];
        yield 'bucket location type evidence' => [[
            new Response(200, [
                'x-amz-bucket-region' => 'us-east-1',
                'x-amz-bucket-location-type' => 'AvailabilityZone',
            ]),
        ], 'bucket-type-unsupported', AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED, 1];
        yield 'bucket location name evidence' => [[
            new Response(200, [
                'x-amz-bucket-region' => 'us-east-1',
                'x-amz-bucket-location-name' => 'use1-az1',
            ]),
        ], 'bucket-type-unsupported', AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED, 1];
        yield 'bucket ARN evidence' => [[
            new Response(200, [
                'x-amz-bucket-region' => 'us-east-1',
                'x-amz-bucket-arn' => 'arn:aws:s3express:us-east-1:123456789012:bucket/private',
            ]),
        ], 'bucket-type-unsupported', AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED, 1];
        yield 'access point alias evidence' => [[
            new Response(200, [
                'x-amz-bucket-region' => 'us-east-1',
                'x-amz-access-point-alias' => 'true',
            ]),
        ], 'bucket-type-unsupported', AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED, 1];
        yield 'versioning enabled' => [[
            new Response(200, ['x-amz-bucket-region' => 'us-east-1']),
            new Response(200, [], '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Status>Enabled</Status></VersioningConfiguration>'),
        ], 'versioning-enabled', AwsS3ProviderAdmission::VERSIONING_ENABLED, 2];
        yield 'versioning suspended' => [[
            new Response(200, ['x-amz-bucket-region' => 'us-east-1']),
            new Response(200, [], '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Status>Suspended</Status></VersioningConfiguration>'),
        ], 'versioning-suspended', AwsS3ProviderAdmission::VERSIONING_SUSPENDED, 2];
        yield 'versioning probe failure' => [[
            new Response(200, ['x-amz-bucket-region' => 'us-east-1']),
            new Response(500, [], '<Error><Code>InternalError</Code><Message>private SDK detail</Message></Error>'),
        ], 'versioning-check-failed', AwsS3ProviderAdmission::VERSIONING_UNKNOWN, 2];
        yield 'versioning unknown' => [[
            new Response(200, ['x-amz-bucket-region' => 'us-east-1']),
            new Response(200, [], '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Status>Unexpected</Status></VersioningConfiguration>'),
        ], 'versioning-unknown', AwsS3ProviderAdmission::VERSIONING_UNKNOWN, 2];
    }

    public function test_default_client_enforces_the_closed_qualified_endpoint_contract(): void
    {
        $validator = new AwsS3ProviderValidator();
        $config = $validator->clientConfiguration(self::profile());
        $client = $validator->createClient(self::profile());

        self::assertSame('us-east-1', $client->getRegion());
        self::assertArrayNotHasKey('endpoint', $config);
        self::assertArrayNotHasKey('credentials', $config);
        self::assertSame(0, $config['retries']);
        self::assertSame('https', $config['scheme']);
        self::assertTrue($config['ignore_configured_endpoint_urls']);
        self::assertFalse($config['bucket_endpoint']);
        self::assertFalse($config['use_path_style_endpoint']);
        self::assertFalse($config['use_arn_region']);
        self::assertFalse($config['use_accelerate_endpoint']);
        self::assertTrue($config['disable_multiregion_access_points']);
        self::assertFalse($config['use_fips_endpoint']);
        self::assertFalse($config['use_dual_stack_endpoint']);
        self::assertSame(['enabled' => false], $config['endpoint_discovery']);
        self::assertTrue($config['disable_express_session_auth']);
        self::assertFalse($config['s3_express_identity_provider']);
        self::assertSame('regional', $config['s3_us_east_1_regional_endpoint']);
        self::assertSame(5, $config['http']['connect_timeout']);
        self::assertSame(15, $config['http']['read_timeout']);
        self::assertSame(20, $config['http']['timeout']);
    }

    public function test_production_client_cannot_be_redirected_or_retried_by_environment_and_shared_config(): void
    {
        $configFile = tempnam(sys_get_temp_dir(), 'aws-qualified-config-');
        self::assertIsString($configFile);
        self::assertNotFalse(file_put_contents($configFile, <<<'INI'
[default]
endpoint_url = http://shared-config.invalid
use_fips_endpoint = true
use_dualstack_endpoint = true
s3_use_arn_region = true
s3_us_east_1_regional_endpoint = legacy
retry_mode = adaptive
max_attempts = 9
INI));

        $environment = [
            'AWS_CONFIG_FILE' => $configFile,
            'AWS_PROFILE' => 'default',
            'AWS_ENDPOINT_URL' => 'http://environment.invalid',
            'AWS_ENDPOINT_URL_S3' => 'http://environment-s3.invalid',
            'AWS_USE_FIPS_ENDPOINT' => 'true',
            'AWS_USE_DUALSTACK_ENDPOINT' => 'true',
            'AWS_S3_USE_ARN_REGION' => 'true',
            'AWS_S3_US_EAST_1_REGIONAL_ENDPOINT' => 'legacy',
            'AWS_S3_DISABLE_MULTIREGION_ACCESS_POINTS' => 'false',
            'AWS_S3_DISABLE_EXPRESS_SESSION_AUTH' => 'false',
            'AWS_MAX_ATTEMPTS' => '9',
            'AWS_RETRY_MODE' => 'adaptive',
            'AWS_ACCESS_KEY_ID' => 'test',
            'AWS_SECRET_ACCESS_KEY' => 'test',
            'AWS_EC2_METADATA_DISABLED' => 'true',
        ];
        $previous = [];
        foreach ($environment as $name => $value) {
            $previous[$name] = getenv($name);
            putenv("{$name}={$value}");
        }

        try {
            $requests = [];
            $handler = new GuzzleHandler(new Client(['handler' => HandlerStack::create(new MockHandler([
                new Response(500, [], '<Error><Code>InternalError</Code></Error>'),
            ]))]));
            $validator = new AwsS3ProviderValidator(
                static function (RequestInterface $request, array $options) use ($handler, &$requests) {
                    $requests[] = $request;

                    return $handler($request, $options);
                },
            );
            $admission = $validator->validate(self::profile());

            self::assertSame(AwsS3ProviderAdmission::REASON_OWNER_OR_ACCESS_CHECK_FAILED, $admission->reason);
            self::assertCount(1, $requests);
            self::assertSame('https', $requests[0]->getUri()->getScheme());
            self::assertSame('backing-private.s3.us-east-1.amazonaws.com', $requests[0]->getUri()->getHost());
            self::assertSame('/', $requests[0]->getUri()->getPath());
        } finally {
            foreach ($previous as $name => $value) {
                putenv($value === false ? $name : "{$name}={$value}");
            }
            @unlink($configFile);
        }
    }

    public function test_client_construction_failure_is_closed_and_secret_free(): void
    {
        $admission = (new AwsS3ProviderValidator())->validate(
            self::profile(),
            static fn() => throw new \RuntimeException('private-client-construction-detail'),
        );

        self::assertSame(AwsS3ProviderAdmission::REASON_CLIENT_UNAVAILABLE, $admission->reason);
        self::assertSame(AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED, $admission->versioningState);
        self::assertFalse($admission->ownerCheckPassed);
        self::assertStringNotContainsString(
            'private-client-construction-detail',
            json_encode($admission->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_missing_optional_sdk_is_a_closed_secret_free_denial(): void
    {
        $root = dirname(__DIR__, 3);
        $script = implode('', [
            'require ', var_export($root . '/src/Storage/AwsS3ProviderProfile.php', true), ';',
            'require ', var_export($root . '/src/Storage/AwsS3ProviderAdmission.php', true), ';',
            'require ', var_export($root . '/src/Storage/AwsS3ProviderProbe.php', true), ';',
            'require ', var_export($root . '/src/Storage/AwsS3ProviderValidator.php', true), ';',
            '$profile=new OpsFour\\S3Server\\Storage\\AwsS3ProviderProfile(',
            "'backing-private','us-east-1','123456789012','objects','.opsfour/attempts');",
            '$admission=(new OpsFour\\S3Server\\Storage\\AwsS3ProviderValidator())->validate($profile);',
            'echo json_encode($admission->toArray(), JSON_THROW_ON_ERROR);',
        ]);
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $error === false ? '' : $error);
        self::assertIsString($output);
        /** @var array<string, mixed> $admission */
        $admission = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(AwsS3ProviderAdmission::REASON_SDK_UNAVAILABLE, $admission['reason']);
        self::assertSame(AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED, $admission['versioning_state']);
        self::assertFalse($admission['provider_admitted']);
    }

    public function test_admission_factories_reject_untrusted_status_text_and_invalid_combinations(): void
    {
        foreach ([
            static fn() => AwsS3ProviderAdmission::admitted('private-region-secret'),
            static fn() => AwsS3ProviderAdmission::denied(
                'private-region-secret',
                false,
                false,
                AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED,
                AwsS3ProviderAdmission::REASON_CONFIGURATION_INVALID,
            ),
            static fn() => AwsS3ProviderAdmission::denied(
                'us-east-1',
                true,
                false,
                AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED,
                'private-reason-secret',
            ),
            static fn() => AwsS3ProviderAdmission::denied(
                'us-east-1',
                true,
                false,
                'private-versioning-secret',
                AwsS3ProviderAdmission::REASON_OWNER_OR_ACCESS_CHECK_FAILED,
            ),
            static fn() => AwsS3ProviderAdmission::denied(
                'us-east-1',
                true,
                true,
                AwsS3ProviderAdmission::VERSIONING_ENABLED,
                AwsS3ProviderAdmission::REASON_REGION_MISMATCH,
            ),
        ] as $factory) {
            try {
                $factory();
                self::fail('Admission factories must reject caller-controlled status text and invalid states.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringStartsWith('Invalid closed AWS S3 provider admission ', $error->getMessage());
                self::assertStringNotContainsString('private', $error->getMessage());
            }
        }
    }

    private static function profile(): AwsS3ProviderProfile
    {
        return new AwsS3ProviderProfile(
            bucket: 'backing-private',
            region: 'us-east-1',
            expectedOwnerId: self::OWNER,
            dataPrefix: 'objects',
            reservedPrefix: '.opsfour/attempts',
        );
    }

    /**
     * @param list<Response|\Throwable> $responses
     * @param list<RequestInterface> $requests
     */
    private static function client(array $responses, array &$requests): S3Client
    {
        $requests = [];
        $handler = new GuzzleHandler(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));

        return new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'https://s3.mock.invalid',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'retries' => 0,
            'http_handler' => static function (RequestInterface $request, array $options) use ($handler, &$requests) {
                $requests[] = $request;

                return $handler($request, $options);
            },
        ]);
    }
}
