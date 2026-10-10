<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Client;

final class AuthorizationSecurityTest extends S3FunctionalTestCase
{
    private static string $bucket = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$bucket !== '') {
            return;
        }

        self::$bucket = 'auth-security-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => self::$bucket]);
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'protected.txt',
            'Body' => 'protected',
        ]);
    }

    public function test_anonymous_create_bucket_is_denied(): void
    {
        $bucket = 'anonymous-create-' . bin2hex(random_bytes(4));
        $response = $this->http()->put($this->url($bucket));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('<Code>AccessDenied</Code>', (string) $response->getBody());

        $listed = self::$s3->listBuckets();
        $names = array_column($listed['Buckets'] ?? [], 'Name');
        $this->assertNotContains($bucket, $names);
    }

    public function test_put_bucket_policy_rejects_structurally_malformed_document(): void
    {
        try {
            self::$s3->putBucketPolicy([
                'Bucket' => self::$bucket,
                'Policy' => json_encode(['Statement' => []], JSON_THROW_ON_ERROR),
            ]);
            $this->fail('Expected malformed bucket policy to be rejected.');
        } catch (S3Exception $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('MalformedPolicy', $e->getAwsErrorCode());
        }
    }

    public function test_invalid_policy_conditions_cannot_replace_existing_policy_or_lock_out_owner(): void
    {
        $validPolicy = json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => 'Allow',
                'Principal' => ['AWS' => 'tenant:reader'],
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::' . self::$bucket . '/protected.txt',
            ]],
        ], JSON_THROW_ON_ERROR);
        self::$s3->putBucketPolicy(['Bucket' => self::$bucket, 'Policy' => $validPolicy]);

        try {
            foreach ([
                ['StringEquals' => ['aws:SourceVpc' => 'vpc-fixed']],
                ['ArnLike' => ['aws:SourceArn' => 'arn:aws:s3:::example/*']],
                ['Null' => ['aws:PrincipalArn' => 'maybe']],
                ['Bool' => ['aws:SecureTransport' => 'maybe']],
                ['NotIpAddress' => ['aws:SourceIp' => '192.0.2.0/99']],
                ['NumericLessThan' => ['s3:max-keys' => 'many']],
                ['DateLessThan' => ['aws:CurrentTime' => 'never-ever']],
                ['DateLessThan' => ['aws:CurrentTime' => 'tomorrow']],
            ] as $condition) {
                $invalidPolicy = json_encode([
                    'Version' => '2012-10-17',
                    'Statement' => [[
                        'Effect' => 'Allow',
                        'Principal' => '*',
                        'Action' => 's3:GetObject',
                        'Resource' => 'arn:aws:s3:::' . self::$bucket . '/*',
                        'Condition' => $condition,
                    ]],
                ], JSON_THROW_ON_ERROR);

                try {
                    self::$s3->putBucketPolicy(['Bucket' => self::$bucket, 'Policy' => $invalidPolicy]);
                    $this->fail('Invalid policy condition was stored.');
                } catch (S3Exception $exception) {
                    $this->assertSame(400, $exception->getStatusCode());
                    $this->assertSame('MalformedPolicy', $exception->getAwsErrorCode());
                }
                $this->assertSame($validPolicy, (string) self::$s3->getBucketPolicy(['Bucket' => self::$bucket])['Policy']);
            }
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
        }
    }

    public function test_bucket_policy_above_20_kib_is_rejected_without_replacing_existing_policy(): void
    {
        $policy = json_encode([
            'Statement' => [[
                'Effect' => 'Allow',
                'Principal' => ['AWS' => 'tenant:reader'],
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::' . self::$bucket . '/protected.txt',
            ]],
        ], JSON_THROW_ON_ERROR);
        self::$s3->putBucketPolicy(['Bucket' => self::$bucket, 'Policy' => $policy]);
        try {
            $oversized = $policy . str_repeat(' ', 20_481 - strlen($policy));
            try {
                self::$s3->putBucketPolicy(['Bucket' => self::$bucket, 'Policy' => $oversized]);
                self::fail('Expected an oversized bucket policy to be rejected.');
            } catch (S3Exception $exception) {
                self::assertSame('EntityTooLarge', $exception->getAwsErrorCode());
            }
            self::assertSame($policy, (string) self::$s3->getBucketPolicy(['Bucket' => self::$bucket])['Policy']);
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
        }
    }

    public function test_negated_bucket_policy_condition_denies_put_when_header_is_missing(): void
    {
        $key = 'negated-condition-' . bin2hex(random_bytes(5)) . '.txt';
        $policy = json_encode([
            'Statement' => [[
                'Effect' => 'Deny',
                'Principal' => '*',
                'Action' => 's3:PutObject',
                'Resource' => 'arn:aws:s3:::' . self::$bucket . '/' . $key,
                'Condition' => ['StringNotEquals' => ['s3:x-amz-acl' => 'public-read']],
            ]],
        ], JSON_THROW_ON_ERROR);
        self::$s3->putBucketPolicy(['Bucket' => self::$bucket, 'Policy' => $policy]);
        try {
            try {
                self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => 'blocked']);
                self::fail('Expected the missing ACL header to match StringNotEquals Deny.');
            } catch (S3Exception $exception) {
                self::assertSame('AccessDenied', $exception->getAwsErrorCode());
            }
            try {
                self::$s3->getObject(['Bucket' => self::$bucket, 'Key' => $key]);
                self::fail('Denied write created an object.');
            } catch (S3Exception $exception) {
                self::assertSame('NoSuchKey', $exception->getAwsErrorCode());
            }
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
        }
    }

    public function test_block_public_policy_allows_bounded_source_ip_policy_and_rejects_broad_range(): void
    {
        self::$s3->putPublicAccessBlock([
            'Bucket' => self::$bucket,
            'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => false,
                'IgnorePublicAcls' => false,
                'BlockPublicPolicy' => true,
                'RestrictPublicBuckets' => false,
            ],
        ]);

        $statement = [
            'Effect' => 'Allow',
            'Principal' => '*',
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::' . self::$bucket . '/protected.txt',
            'Condition' => ['IpAddress' => ['aws:SourceIp' => '192.0.2.0/24']],
        ];

        try {
            self::$s3->putBucketPolicy([
                'Bucket' => self::$bucket,
                'Policy' => json_encode(['Version' => '2012-10-17', 'Statement' => [$statement]], JSON_THROW_ON_ERROR),
            ]);
            $status = self::$s3->getBucketPolicyStatus(['Bucket' => self::$bucket]);
            $this->assertFalse($status['PolicyStatus']['IsPublic']);

            $statement['Condition']['IpAddress']['aws:SourceIp'] = '0.0.0.0/1';
            try {
                self::$s3->putBucketPolicy([
                    'Bucket' => self::$bucket,
                    'Policy' => json_encode(['Version' => '2012-10-17', 'Statement' => [$statement]], JSON_THROW_ON_ERROR),
                ]);
                $this->fail('A broad source IP policy was accepted despite BlockPublicPolicy.');
            } catch (S3Exception $exception) {
                $this->assertSame(403, $exception->getStatusCode());
                $this->assertSame('AccessDenied', $exception->getAwsErrorCode());
            }
            $status = self::$s3->getBucketPolicyStatus(['Bucket' => self::$bucket]);
            $this->assertFalse($status['PolicyStatus']['IsPublic']);
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
            self::$s3->deletePublicAccessBlock(['Bucket' => self::$bucket]);
        }
    }

    public function test_bucket_policy_explicit_deny_applies_to_owner(): void
    {
        self::$s3->putBucketPolicy([
            'Bucket' => self::$bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Deny',
                    'Principal' => '*',
                    'Action' => 's3:DeleteObject',
                    'Resource' => 'arn:aws:s3:::' . self::$bucket . '/protected.txt',
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            self::$s3->deleteObject([
                'Bucket' => self::$bucket,
                'Key' => 'protected.txt',
            ]);
            $this->fail('Expected explicit bucket-policy deny to reject the bucket owner.');
        } catch (S3Exception $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('AccessDenied', $e->getAwsErrorCode());
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
        }
    }

    public function test_bucket_policy_resource_treats_square_brackets_as_literal_key_characters(): void
    {
        $key = 'report[1].txt';
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => 'protected']);
        self::$s3->putBucketPolicy([
            'Bucket' => self::$bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Deny',
                    'Principal' => '*',
                    'Action' => 's3:DeleteObject',
                    'Resource' => 'arn:aws:s3:::' . self::$bucket . '/' . $key,
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            try {
                self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
                $this->fail('Expected literal bracket resource deny to reject deletion.');
            } catch (S3Exception $e) {
                $this->assertSame('AccessDenied', $e->getAwsErrorCode());
            }
            $this->assertSame('protected', (string) self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
            ])['Body']);
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
        }
    }

    public function test_invalid_public_access_block_boolean_cannot_disable_protection(): void
    {
        self::$s3->putPublicAccessBlock([
            'Bucket' => self::$bucket,
            'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => true,
                'IgnorePublicAcls' => true,
                'BlockPublicPolicy' => true,
                'RestrictPublicBuckets' => true,
            ],
        ]);
        $xml = '<PublicAccessBlockConfiguration><BlockPublicAcls>tru</BlockPublicAcls></PublicAccessBlockConfiguration>';
        $command = self::$s3->getCommand('PutPublicAccessBlock', [
            'Bucket' => self::$bucket,
            'PublicAccessBlockConfiguration' => ['BlockPublicAcls' => true],
            'ContentMD5' => base64_encode(md5($xml, true)),
        ]);
        $signed = self::$s3->createPresignedRequest($command, '+5 minutes');

        try {
            $response = (new Client(['http_errors' => false]))->put((string) $signed->getUri(), [
                'body' => $xml,
                'headers' => $signed->getHeaders(),
            ]);
            $this->assertSame(400, $response->getStatusCode());
            $block = self::$s3->getPublicAccessBlock(['Bucket' => self::$bucket]);
            $this->assertTrue($block['PublicAccessBlockConfiguration']['BlockPublicAcls']);
        } finally {
            self::$s3->deletePublicAccessBlock(['Bucket' => self::$bucket]);
        }
    }

    public function test_acl_headers_and_body_cannot_be_combined_or_change_grants(): void
    {
        self::$s3->putBucketAcl(['Bucket' => self::$bucket, 'ACL' => 'private']);
        self::$s3->putObjectAcl(['Bucket' => self::$bucket, 'Key' => 'protected.txt', 'ACL' => 'private']);

        foreach (['PutBucketAcl', 'PutObjectAcl'] as $operation) {
            $arguments = ['Bucket' => self::$bucket, 'ACL' => 'public-read'];
            if ($operation === 'PutObjectAcl') {
                $arguments['Key'] = 'protected.txt';
            }
            $xml = '<AccessControlPolicy/>';
            $arguments['ContentMD5'] = base64_encode(md5($xml, true));
            $command = self::$s3->getCommand($operation, $arguments);
            $signed = self::$s3->createPresignedRequest($command, '+5 minutes');
            $response = (new Client(['http_errors' => false]))->put((string) $signed->getUri(), [
                'body' => $xml,
                'headers' => $signed->getHeaders(),
            ]);
            $this->assertSame(400, $response->getStatusCode(), $operation . ': ' . (string) $response->getBody());
            $this->assertStringContainsString('<Code>InvalidArgument</Code>', (string) $response->getBody());
        }

        $bucketAcl = self::$s3->getBucketAcl(['Bucket' => self::$bucket]);
        $objectAcl = self::$s3->getObjectAcl(['Bucket' => self::$bucket, 'Key' => 'protected.txt']);
        $this->assertCount(1, $bucketAcl['Grants']);
        $this->assertCount(1, $objectAcl['Grants']);
    }

    public function test_wrong_xml_root_cannot_clear_bucket_tags(): void
    {
        self::$s3->putBucketTagging([
            'Bucket' => self::$bucket,
            'Tagging' => ['TagSet' => [['Key' => 'retain', 'Value' => 'yes']]],
        ]);
        $xml = '<WrongRoot/>';
        $command = self::$s3->getCommand('PutBucketTagging', [
            'Bucket' => self::$bucket,
            'Tagging' => ['TagSet' => [['Key' => 'retain', 'Value' => 'yes']]],
            'ContentMD5' => base64_encode(md5($xml, true)),
        ]);
        $signed = self::$s3->createPresignedRequest($command, '+5 minutes');

        try {
            $response = (new Client(['http_errors' => false]))->put((string) $signed->getUri(), [
                'body' => $xml,
                'headers' => $signed->getHeaders(),
            ]);
            $this->assertSame(400, $response->getStatusCode());
            $tags = self::$s3->getBucketTagging(['Bucket' => self::$bucket]);
            $this->assertSame([['Key' => 'retain', 'Value' => 'yes']], $tags['TagSet']);
        } finally {
            self::$s3->deleteBucketTagging(['Bucket' => self::$bucket]);
        }
    }

    public function test_missing_tag_set_cannot_clear_bucket_tags(): void
    {
        self::$s3->putBucketTagging([
            'Bucket' => self::$bucket,
            'Tagging' => ['TagSet' => [['Key' => 'retain', 'Value' => 'yes']]],
        ]);

        try {
            $response = $this->sendRawBucketConfiguration(
                'PutBucketTagging',
                ['Tagging' => ['TagSet' => [['Key' => 'retain', 'Value' => 'yes']]]],
                '<Tagging/>',
            );
            $this->assertSame(400, $response->getStatusCode());
            $this->assertSame(
                [['Key' => 'retain', 'Value' => 'yes']],
                self::$s3->getBucketTagging(['Bucket' => self::$bucket])['TagSet'],
            );
        } finally {
            self::$s3->deleteBucketTagging(['Bucket' => self::$bucket]);
        }
    }

    public function test_invalid_cors_configuration_preserves_existing_rules(): void
    {
        $configuration = ['CORSRules' => [[
            'AllowedOrigins' => ['https://example.com'],
            'AllowedMethods' => ['GET'],
        ]]];
        self::$s3->putBucketCors(['Bucket' => self::$bucket, 'CORSConfiguration' => $configuration]);

        try {
            foreach (['<CORSConfiguration/>',
                '<CORSConfiguration><CORSRule><AllowedOrigin>*</AllowedOrigin></CORSRule></CORSConfiguration>',
                '<CORSConfiguration><CORSRule><AllowedOrigin>*</AllowedOrigin><AllowedMethod>PATCH</AllowedMethod></CORSRule></CORSConfiguration>',
                '<CORSConfiguration><CORSRule><AllowedOrigin>*</AllowedOrigin><AllowedMethod>GET</AllowedMethod><MaxAgeSeconds>5</MaxAgeSeconds><MaxAgeSeconds>0</MaxAgeSeconds></CORSRule></CORSConfiguration>',
            ] as $xml) {
                $response = $this->sendRawBucketConfiguration(
                    'PutBucketCors',
                    ['CORSConfiguration' => $configuration],
                    $xml,
                );
                $this->assertSame(400, $response->getStatusCode());
                $rules = self::$s3->getBucketCors(['Bucket' => self::$bucket])['CORSRules'];
                $this->assertSame(['https://example.com'], $rules[0]['AllowedOrigins']);
            }
        } finally {
            self::$s3->deleteBucketCors(['Bucket' => self::$bucket]);
        }
    }

    public function test_missing_encryption_rule_is_rejected_before_encryption_service_check(): void
    {
        $configuration = ['Rules' => [[
            'ApplyServerSideEncryptionByDefault' => ['SSEAlgorithm' => 'AES256'],
        ]]];

        foreach (['<ServerSideEncryptionConfiguration/>',
            '<ServerSideEncryptionConfiguration><Rule><BucketKeyEnabled>invalid</BucketKeyEnabled></Rule></ServerSideEncryptionConfiguration>',
            '<ServerSideEncryptionConfiguration><Rule><ApplyServerSideEncryptionByDefault><SSEAlgorithm>AES256</SSEAlgorithm></ApplyServerSideEncryptionByDefault><BucketKeyEnabled>invalid</BucketKeyEnabled></Rule></ServerSideEncryptionConfiguration>',
        ] as $xml) {
            $response = $this->sendRawBucketConfiguration(
                'PutBucketEncryption',
                ['ServerSideEncryptionConfiguration' => $configuration],
                $xml,
            );
            $this->assertSame(400, $response->getStatusCode());
        }
    }

    public function test_invalid_logging_xml_cannot_disable_logging(): void
    {
        $status = ['LoggingEnabled' => [
            'TargetBucket' => self::$bucket,
            'TargetPrefix' => 'access/',
        ]];
        self::$s3->putBucketLogging(['Bucket' => self::$bucket, 'BucketLoggingStatus' => $status]);

        try {
            foreach ([
                ['<WrongRoot/>', 400],
                ['<BucketLoggingStatus><Unexpected/></BucketLoggingStatus>', 400],
                ['<BucketLoggingStatus><LoggingEnabled><TargetBucket>' . self::$bucket . '</TargetBucket></LoggingEnabled></BucketLoggingStatus>', 400],
                ['<BucketLoggingStatus><LoggingEnabled><TargetBucket>' . self::$bucket . '</TargetBucket><TargetPrefix>other/</TargetPrefix><Unexpected/></LoggingEnabled></BucketLoggingStatus>', 400],
                ['<BucketLoggingStatus><LoggingEnabled><TargetBucket>' . self::$bucket . '</TargetBucket><TargetPrefix>other/</TargetPrefix><TargetGrants/></LoggingEnabled></BucketLoggingStatus>', 501],
                ['<BucketLoggingStatus><LoggingEnabled><TargetBucket>' . self::$bucket . '</TargetBucket><TargetPrefix>other/</TargetPrefix><TargetObjectKeyFormat><PartitionedPrefix><PartitionDateSource>EventTime</PartitionDateSource></PartitionedPrefix></TargetObjectKeyFormat></LoggingEnabled></BucketLoggingStatus>', 501],
                ['<BucketLoggingStatus><LoggingEnabled><TargetBucket>' . self::$bucket . '</TargetBucket><TargetPrefix>other/</TargetPrefix></LoggingEnabled><LoggingEnabled><TargetBucket>' . self::$bucket . '</TargetBucket><TargetPrefix>other/</TargetPrefix></LoggingEnabled></BucketLoggingStatus>', 400],
            ] as [$xml, $expectedStatus]) {
                $response = $this->sendRawBucketConfiguration(
                    'PutBucketLogging',
                    ['BucketLoggingStatus' => $status],
                    $xml,
                );
                $this->assertSame($expectedStatus, $response->getStatusCode());
                $logging = self::$s3->getBucketLogging(['Bucket' => self::$bucket]);
                $this->assertSame('access/', $logging['LoggingEnabled']['TargetPrefix']);
            }
        } finally {
            self::$s3->putBucketLogging(['Bucket' => self::$bucket, 'BucketLoggingStatus' => []]);
        }
    }

    public function test_logging_target_must_share_source_region(): void
    {
        $target = 'logging-other-region-' . bin2hex(random_bytes(4));
        self::$s3->createBucket([
            'Bucket' => $target,
            'CreateBucketConfiguration' => ['LocationConstraint' => 'eu-west-1'],
        ]);

        try {
            try {
                self::$s3->putBucketLogging([
                    'Bucket' => self::$bucket,
                    'BucketLoggingStatus' => ['LoggingEnabled' => [
                        'TargetBucket' => $target,
                        'TargetPrefix' => 'access/',
                    ]],
                ]);
                self::fail('A cross-region logging target must be rejected.');
            } catch (S3Exception $exception) {
                self::assertSame(400, $exception->getStatusCode());
                self::assertSame('InvalidRequest', $exception->getAwsErrorCode());
            }
            self::assertNull(self::$s3->getBucketLogging(['Bucket' => self::$bucket])['LoggingEnabled']);
        } finally {
            self::deleteBucketWithDiagnostics($target);
        }
    }

    public function test_object_lock_bucket_cannot_be_logging_target(): void
    {
        $target = 'logging-locked-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $target, 'ObjectLockEnabledForBucket' => true]);

        try {
            try {
                self::$s3->putBucketLogging([
                    'Bucket' => self::$bucket,
                    'BucketLoggingStatus' => ['LoggingEnabled' => [
                        'TargetBucket' => $target,
                        'TargetPrefix' => 'access/',
                    ]],
                ]);
                self::fail('An Object Lock bucket must not receive access logs.');
            } catch (S3Exception $exception) {
                self::assertSame(400, $exception->getStatusCode());
                self::assertSame('InvalidRequest', $exception->getAwsErrorCode());
            }
            self::assertNull(self::$s3->getBucketLogging(['Bucket' => self::$bucket])['LoggingEnabled']);
        } finally {
            self::deleteBucketWithDiagnostics($target);
        }
    }

    public function test_object_lock_cannot_be_disabled_or_given_invalid_default_retention(): void
    {
        $bucket = 'lock-configuration-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket, 'ObjectLockEnabledForBucket' => true]);
        $configuration = [
            'ObjectLockEnabled' => 'Enabled',
            'Rule' => ['DefaultRetention' => ['Mode' => 'GOVERNANCE', 'Days' => 2]],
        ];
        self::$s3->putObjectLockConfiguration([
            'Bucket' => $bucket,
            'ObjectLockConfiguration' => $configuration,
        ]);

        try {
            foreach (['<ObjectLockConfiguration><ObjectLockEnabled>Disabled</ObjectLockEnabled></ObjectLockConfiguration>',
                '<ObjectLockConfiguration><Rule><DefaultRetention><Mode>GOVERNANCE</Mode></DefaultRetention></Rule></ObjectLockConfiguration>',
                '<ObjectLockConfiguration><Rule><DefaultRetention><Mode>COMPLIANCE</Mode><Days>0</Days></DefaultRetention></Rule></ObjectLockConfiguration>',
                '<ObjectLockConfiguration><Rule><DefaultRetention><Mode>GOVERNANCE</Mode><Days>2</Days><Years>1</Years></DefaultRetention></Rule></ObjectLockConfiguration>',
            ] as $xml) {
                $response = $this->sendRawBucketConfiguration(
                    'PutObjectLockConfiguration',
                    ['ObjectLockConfiguration' => $configuration],
                    $xml,
                    $bucket,
                );
                $this->assertSame(400, $response->getStatusCode());
                $lock = self::$s3->getObjectLockConfiguration(['Bucket' => $bucket])['ObjectLockConfiguration'];
                $this->assertSame('Enabled', $lock['ObjectLockEnabled']);
                $this->assertSame(2, $lock['Rule']['DefaultRetention']['Days']);
            }
        } finally {
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_object_lock_subresources_reject_a_bucket_without_object_lock(): void
    {
        $bucket = 'lock-disabled-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'plain.txt', 'Body' => 'plain']);

        try {
            $operations = [
                'GetObjectRetention' => static fn() => self::$s3->getObjectRetention(['Bucket' => $bucket, 'Key' => 'plain.txt']),
                'GetObjectLegalHold' => static fn() => self::$s3->getObjectLegalHold(['Bucket' => $bucket, 'Key' => 'plain.txt']),
                'PutObjectRetention' => static fn() => self::$s3->putObjectRetention([
                    'Bucket' => $bucket,
                    'Key' => 'plain.txt',
                    'Retention' => [
                        'Mode' => 'GOVERNANCE',
                        'RetainUntilDate' => new \DateTimeImmutable('+1 day'),
                    ],
                ]),
                'PutObjectLegalHold' => static fn() => self::$s3->putObjectLegalHold([
                    'Bucket' => $bucket,
                    'Key' => 'plain.txt',
                    'LegalHold' => ['Status' => 'ON'],
                ]),
            ];
            foreach ($operations as $name => $operation) {
                try {
                    $operation();
                    $this->fail("{$name} must reject a bucket without Object Lock.");
                } catch (S3Exception $e) {
                    $this->assertSame(400, $e->getStatusCode(), $name);
                    $this->assertSame('InvalidRequest', $e->getAwsErrorCode(), $name);
                }
            }
        } finally {
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'plain.txt']);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_object_lock_reads_distinguish_missing_object_settings(): void
    {
        $bucket = 'lock-unset-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket, 'ObjectLockEnabledForBucket' => true]);
        $put = self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'unlocked.txt', 'Body' => 'plain']);

        try {
            foreach (['getObjectRetention', 'getObjectLegalHold'] as $operation) {
                try {
                    self::$s3->{$operation}(['Bucket' => $bucket, 'Key' => 'unlocked.txt']);
                    $this->fail("{$operation} must report missing object settings.");
                } catch (S3Exception $e) {
                    $this->assertSame(404, $e->getStatusCode(), $operation);
                    $this->assertSame('NoSuchObjectLockConfiguration', $e->getAwsErrorCode(), $operation);
                }
            }
        } finally {
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'unlocked.txt', 'VersionId' => $put['VersionId']]);
            self::deleteBucketWithDiagnostics($bucket);
        }
    }

    public function test_duplicate_legal_hold_status_cannot_remove_existing_hold(): void
    {
        $bucket = 'legal-hold-validation-' . bin2hex(random_bytes(4));
        $key = 'held.txt';
        self::$s3->createBucket(['Bucket' => $bucket, 'ObjectLockEnabledForBucket' => true]);
        $versionId = null;

        try {
            $versionId = self::$s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'held'])['VersionId'];
            self::$s3->putObjectLegalHold([
                'Bucket' => $bucket,
                'Key' => $key,
                'VersionId' => $versionId,
                'LegalHold' => ['Status' => 'ON'],
            ]);

            $response = $this->sendRawBucketConfiguration(
                'PutObjectLegalHold',
                ['Key' => $key, 'VersionId' => $versionId, 'LegalHold' => ['Status' => 'OFF']],
                '<LegalHold><Status>OFF</Status><Status>ON</Status></LegalHold>',
                $bucket,
            );
            self::assertSame(400, $response->getStatusCode());
            self::assertSame('ON', self::$s3->getObjectLegalHold([
                'Bucket' => $bucket,
                'Key' => $key,
                'VersionId' => $versionId,
            ])['LegalHold']['Status']);
        } finally {
            if ($versionId !== null) {
                self::$s3->putObjectLegalHold([
                    'Bucket' => $bucket,
                    'Key' => $key,
                    'VersionId' => $versionId,
                    'LegalHold' => ['Status' => 'OFF'],
                ]);
                self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $versionId]);
            }
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_invalid_lifecycle_rules_cannot_replace_existing_rule(): void
    {
        $configuration = ['Rules' => [[
            'ID' => 'retain',
            'Status' => 'Enabled',
            'Prefix' => 'tmp/',
            'Expiration' => ['Days' => 7],
        ]]];
        self::$s3->putBucketLifecycle([
            'Bucket' => self::$bucket,
            'LifecycleConfiguration' => $configuration,
        ]);

        try {
            $ruleStart = '<LifecycleConfiguration><Rule><ID>bad</ID><Status>Enabled</Status><Prefix>tmp/</Prefix>';
            $ruleEnd = '</Rule></LifecycleConfiguration>';
            foreach (['<LifecycleConfiguration/>',
                '<LifecycleConfiguration><Rule><ID>bad</ID><Prefix></Prefix><Expiration><Days>1</Days></Expiration></Rule></LifecycleConfiguration>',
                '<LifecycleConfiguration><Rule><ID>bad</ID><Status>Enabld</Status><Prefix></Prefix><Expiration><Days>1</Days></Expiration></Rule></LifecycleConfiguration>',
                $ruleStart . '<Expiration><Days>abc</Days></Expiration>' . $ruleEnd,
                $ruleStart . '<Expiration><Days>0</Days></Expiration>' . $ruleEnd,
                $ruleStart . '<Expiration><Days>1</Days><ExpiredObjectDeleteMarker>true</ExpiredObjectDeleteMarker></Expiration>' . $ruleEnd,
                $ruleStart . '<Transition><Days>1junk</Days><StorageClass>STANDARD_IA</StorageClass></Transition>' . $ruleEnd,
                $ruleStart . '<NoncurrentVersionTransition><NoncurrentDays>abc</NoncurrentDays><StorageClass>STANDARD_IA</StorageClass></NoncurrentVersionTransition>' . $ruleEnd,
                $ruleStart . '<NoncurrentVersionExpiration><NoncurrentDays>abc</NoncurrentDays></NoncurrentVersionExpiration>' . $ruleEnd,
                $ruleStart . '<AbortIncompleteMultipartUpload><DaysAfterInitiation>abc</DaysAfterInitiation></AbortIncompleteMultipartUpload>' . $ruleEnd,
                $ruleStart . '<Expiration><Date>tomorrow</Date></Expiration>' . $ruleEnd,
                $ruleStart . '<Expiration><Date>2026-02-30T00:00:00Z</Date></Expiration>' . $ruleEnd,
                $ruleStart . '<Transition><Date>tomorrow</Date><StorageClass>STANDARD_IA</StorageClass></Transition>' . $ruleEnd,
                '<LifecycleConfiguration><Rule><ID>bad</ID><Status>Enabled</Status><Filter><ObjectSizeGreaterThan>100</ObjectSizeGreaterThan></Filter><Expiration><Days>1</Days></Expiration></Rule></LifecycleConfiguration>',
                '<LifecycleConfiguration><Rule><ID>bad</ID><Status>Enabled</Status><Filter><And><ObjectSizeGreaterThan>100</ObjectSizeGreaterThan></And></Filter><Expiration><Days>1</Days></Expiration></Rule></LifecycleConfiguration>',
                '<LifecycleConfiguration><Rule><ID>bad</ID><Status>Enabled</Status><Filter><And/></Filter><Expiration><Days>1</Days></Expiration></Rule></LifecycleConfiguration>',
                '<LifecycleConfiguration><Rule><ID>bad</ID><Status>Enabled</Status><Filter><Tag><Key/></Tag></Filter><Expiration><Days>1</Days></Expiration></Rule></LifecycleConfiguration>',
                '<LifecycleConfiguration><Rule><ID>bad</ID><Status>Enabled</Status><Filter><Prefix>safe/</Prefix><Tag><Key>class</Key><Value>temp</Value></Tag></Filter><Expiration><Days>1</Days></Expiration></Rule></LifecycleConfiguration>',
                '<LifecycleConfiguration><Rule><ID>bad</ID><Status>Enabled</Status><Prefix>safe/</Prefix><Filter><Prefix>other/</Prefix></Filter><Expiration><Days>1</Days></Expiration></Rule></LifecycleConfiguration>',
                '<LifecycleConfiguration><Rule><ID>bad</ID><Status>Enabled</Status><Filter><Tag><Key>class</Key><Value>temp</Value></Tag></Filter><AbortIncompleteMultipartUpload><DaysAfterInitiation>1</DaysAfterInitiation></AbortIncompleteMultipartUpload></Rule></LifecycleConfiguration>',
                '<LifecycleConfiguration><Rule><ID>bad</ID><Status>Enabled</Status><Filter><Tag><Key>class</Key><Value>temp</Value></Tag></Filter><Expiration><ExpiredObjectDeleteMarker>true</ExpiredObjectDeleteMarker></Expiration></Rule></LifecycleConfiguration>',
                '<LifecycleConfiguration><Rule><ID>bad</ID><Status>Enabled</Status><Prefix>safe/</Prefix><Unknown/><Expiration><Days>1</Days></Expiration></Rule></LifecycleConfiguration>',
                $ruleStart . '<Expiration><Days>1</Days><Unknown/></Expiration>' . $ruleEnd,
                $ruleStart . '<Expiration><Days>1</Days><Days>7</Days></Expiration>' . $ruleEnd,
                $ruleStart . '<NoncurrentVersionExpiration><NoncurrentDays>1</NoncurrentDays><NewerNoncurrentVersions>2</NewerNoncurrentVersions></NoncurrentVersionExpiration>' . $ruleEnd,
                $ruleStart . '<AbortIncompleteMultipartUpload><DaysAfterInitiation>1</DaysAfterInitiation><Unknown/></AbortIncompleteMultipartUpload>' . $ruleEnd,
                $ruleStart . '<Transition><Days>1</Days><StorageClass>GLACIER</StorageClass></Transition>' . $ruleEnd,
                $ruleStart . '<NoncurrentVersionTransition><NoncurrentDays>1</NoncurrentDays><StorageClass>GLACIER</StorageClass></NoncurrentVersionTransition>' . $ruleEnd,
            ] as $xml) {
                $response = $this->sendRawBucketConfiguration(
                    'PutBucketLifecycle',
                    ['LifecycleConfiguration' => $configuration],
                    $xml,
                );
                $this->assertSame(400, $response->getStatusCode());
                $rules = self::$s3->getBucketLifecycle(['Bucket' => self::$bucket])['Rules'];
                $this->assertSame('retain', $rules[0]['ID']);
                $this->assertSame(7, $rules[0]['Expiration']['Days']);
            }
        } finally {
            self::$s3->deleteBucketLifecycle(['Bucket' => self::$bucket]);
        }
    }

    public function test_invalid_website_xml_cannot_replace_existing_configuration(): void
    {
        $configuration = ['IndexDocument' => ['Suffix' => 'home.html']];
        self::$s3->putBucketWebsite(['Bucket' => self::$bucket, 'WebsiteConfiguration' => $configuration]);

        try {
            foreach ([
                '<WebsiteConfiguration/>',
                '<WebsiteConfiguration><IndexDocument><Suffix>folder/home.html</Suffix></IndexDocument></WebsiteConfiguration>',
                '<WebsiteConfiguration><RedirectAllRequestsTo><Protocol>https</Protocol></RedirectAllRequestsTo></WebsiteConfiguration>',
                '<WebsiteConfiguration><RedirectAllRequestsTo><HostName>example.com</HostName><Protocol>ftp</Protocol></RedirectAllRequestsTo></WebsiteConfiguration>',
                '<WebsiteConfiguration><IndexDocument><Suffix>home.html</Suffix></IndexDocument><RedirectAllRequestsTo><HostName>example.com</HostName></RedirectAllRequestsTo></WebsiteConfiguration>',
                '<WebsiteConfiguration><IndexDocument><Suffix>home.html</Suffix></IndexDocument><RoutingRules><RoutingRule><Condition><KeyPrefixEquals>old/</KeyPrefixEquals></Condition></RoutingRule></RoutingRules></WebsiteConfiguration>',
                '<WebsiteConfiguration><IndexDocument><Suffix>home.html</Suffix></IndexDocument><RoutingRules><RoutingRule><Condition><HttpErrorCodeReturnedEquals>404junk</HttpErrorCodeReturnedEquals></Condition><Redirect><HostName>example.com</HostName></Redirect></RoutingRule></RoutingRules></WebsiteConfiguration>',
                '<WebsiteConfiguration><IndexDocument><Suffix>home.html</Suffix></IndexDocument><RoutingRules><RoutingRule><Redirect><Protocol>javascript</Protocol></Redirect></RoutingRule></RoutingRules></WebsiteConfiguration>',
                '<WebsiteConfiguration><IndexDocument><Suffix>home.html</Suffix></IndexDocument><RoutingRules><RoutingRule><Redirect><ReplaceKeyWith>a</ReplaceKeyWith><ReplaceKeyPrefixWith>b</ReplaceKeyPrefixWith></Redirect></RoutingRule></RoutingRules></WebsiteConfiguration>',
                '<WebsiteConfiguration><IndexDocument><Suffix>home.html</Suffix><Suffix>other.html</Suffix></IndexDocument></WebsiteConfiguration>',
                '<WebsiteConfiguration><IndexDocument><Suffix>home.html</Suffix></IndexDocument><IndexDocument><Suffix>other.html</Suffix></IndexDocument></WebsiteConfiguration>',
                '<WebsiteConfiguration><RedirectAllRequestsTo><HostName>example.com</HostName><Unknown>ignored</Unknown></RedirectAllRequestsTo></WebsiteConfiguration>',
                '<WebsiteConfiguration><IndexDocument><Suffix>home.html</Suffix></IndexDocument><RoutingRules><Unknown/></RoutingRules></WebsiteConfiguration>',
                '<WebsiteConfiguration><IndexDocument><Suffix>home.html</Suffix></IndexDocument><RoutingRules><RoutingRule><Redirect><Unknown/></Redirect></RoutingRule></RoutingRules></WebsiteConfiguration>',
                '<WebsiteConfiguration><IndexDocument><Suffix>home.html</Suffix></IndexDocument><RoutingRules><RoutingRule><Condition><KeyPrefixEquals>a/</KeyPrefixEquals><Unknown/></Condition><Redirect><HostName>example.com</HostName></Redirect></RoutingRule></RoutingRules></WebsiteConfiguration>',
                '<WebsiteConfiguration><IndexDocument><Suffix>home.html</Suffix></IndexDocument><RoutingRules><RoutingRule><Condition><HttpErrorCodeReturnedEquals>500</HttpErrorCodeReturnedEquals></Condition><Redirect><HostName>example.com</HostName></Redirect></RoutingRule></RoutingRules></WebsiteConfiguration>',
            ] as $xml) {
                $response = $this->sendRawBucketConfiguration(
                    'PutBucketWebsite',
                    ['WebsiteConfiguration' => $configuration],
                    $xml,
                );
                $this->assertSame(400, $response->getStatusCode());
                $website = self::$s3->getBucketWebsite(['Bucket' => self::$bucket]);
                $this->assertSame('home.html', $website['IndexDocument']['Suffix']);
            }
        } finally {
            self::$s3->deleteBucketWebsite(['Bucket' => self::$bucket]);
        }
    }

    public function test_invalid_notification_xml_cannot_replace_existing_configuration(): void
    {
        $configuration = ['TopicConfigurations' => [[
            'Id' => 'retain',
            'TopicArn' => 'https://notifications.invalid/retain',
            'Events' => ['s3:ObjectCreated:*'],
        ]]];
        self::$s3->putBucketNotificationConfiguration([
            'Bucket' => self::$bucket,
            'NotificationConfiguration' => $configuration,
        ]);

        try {
            foreach ([
                '<NotificationConfiguration><UnsupportedConfiguration/></NotificationConfiguration>',
                '<NotificationConfiguration xmlns:x="urn:foreign"><x:TopicConfiguration><x:Topic>https://notifications.invalid/other</x:Topic><x:Event>s3:ObjectCreated:Put</x:Event></x:TopicConfiguration></NotificationConfiguration>',
                '<NotificationConfiguration><TopicConfiguration><Event>s3:ObjectCreated:*</Event></TopicConfiguration></NotificationConfiguration>',
                '<NotificationConfiguration><TopicConfiguration><Topic>arn:aws:sns:us-east-1:123456789012:other</Topic></TopicConfiguration>',
                '<NotificationConfiguration><TopicConfiguration><Topic>arn:aws:sns:us-east-1:123456789012:other</Topic><Event>s3:ObjectCreated:*</Event><Filter/></TopicConfiguration></NotificationConfiguration>',
                '<NotificationConfiguration><TopicConfiguration><Topic>arn:aws:sns:us-east-1:123456789012:other</Topic><Event>s3:ObjectCreated:*</Event><Filter><S3Key><FilterRule><Name>typo</Name><Value>x</Value></FilterRule></S3Key></Filter></TopicConfiguration></NotificationConfiguration>',
                '<NotificationConfiguration><TopicConfiguration><Topic>arn:aws:sns:us-east-1:123456789012:other</Topic><Topic>arn:aws:sns:us-east-1:123456789012:wrong</Topic><Event>s3:ObjectCreated:*</Event></TopicConfiguration></NotificationConfiguration>',
                '<NotificationConfiguration><TopicConfiguration><Topic>arn:aws:sns:us-east-1:123456789012:other</Topic><Event>s3:ObjectCreated:*</Event><Filter><S3Key><FilterRule><Name>prefix</Name><Value>safe/</Value></FilterRule><Unsupported/></S3Key></Filter></TopicConfiguration></NotificationConfiguration>',
                '<NotificationConfiguration><TopicConfiguration><Topic>arn:aws:sns:us-east-1:123456789012:other</Topic><Event>s3:ObjectCreated:*</Event><Filter><S3Key><FilterRule><Name>prefix</Name><Value>safe/</Value><Value>other/</Value></FilterRule></S3Key></Filter></TopicConfiguration></NotificationConfiguration>',
                '<NotificationConfiguration><TopicConfiguration><Topic>arn:aws:sns:us-east-1:123456789012:other</Topic><Event>s3:ObjectCreated:*</Event><Filter><S3Key><FilterRule><Name>prefix</Name><Value>safe/</Value></FilterRule></S3Key><Unsupported/></Filter></TopicConfiguration></NotificationConfiguration>',
                '<NotificationConfiguration><TopicConfiguration><Topic>https://notifications.invalid/other</Topic><Event>s3:ObjectCreated:Put</Event><Filter><S3Key><FilterRule><Name>prefix</Name><Value>one/</Value></FilterRule><FilterRule><Name>prefix</Name><Value>two/</Value></FilterRule></S3Key></Filter></TopicConfiguration></NotificationConfiguration>',
                '<NotificationConfiguration><TopicConfiguration><Topic>https://notifications.invalid/other</Topic><Event>s3:ObjectCreated:Put</Event><Filter><S3Key><FilterRule><Name>suffix</Name><Value>%2A.txt</Value></FilterRule></S3Key></Filter></TopicConfiguration></NotificationConfiguration>',
            ] as $xml) {
                $response = $this->sendRawBucketConfiguration(
                    'PutBucketNotificationConfiguration',
                    ['NotificationConfiguration' => $configuration],
                    $xml,
                );
                $this->assertSame(400, $response->getStatusCode());
                $notification = self::$s3->getBucketNotificationConfiguration(['Bucket' => self::$bucket]);
                $this->assertSame('retain', $notification['TopicConfigurations'][0]['Id']);
            }
        } finally {
            self::$s3->putBucketNotificationConfiguration([
                'Bucket' => self::$bucket,
                'NotificationConfiguration' => [],
            ]);
        }
    }

    public function test_unsupported_notification_destinations_cannot_replace_working_webhook(): void
    {
        $working = ['TopicConfigurations' => [[
            'Id' => 'working-webhook',
            'TopicArn' => 'https://notifications.invalid/working',
            'Events' => ['s3:ObjectCreated:*'],
        ]]];
        self::$s3->putBucketNotificationConfiguration([
            'Bucket' => self::$bucket,
            'NotificationConfiguration' => $working,
        ]);

        try {
            foreach ([
                ['TopicConfigurations', 'TopicArn', 'arn:aws:sns:us-east-1:123456789012:topic'],
                ['QueueConfigurations', 'QueueArn', 'arn:aws:sqs:us-east-1:123456789012:queue'],
                ['LambdaFunctionConfigurations', 'LambdaFunctionArn', 'arn:aws:lambda:us-east-1:123456789012:function:notify'],
                ['TopicConfigurations', 'TopicArn', 'ftp://notifications.invalid/unsupported'],
            ] as [$collection, $destinationField, $destination]) {
                try {
                    self::$s3->putBucketNotificationConfiguration([
                        'Bucket' => self::$bucket,
                        'NotificationConfiguration' => [
                            $collection => [[
                                'Id' => 'unsupported',
                                $destinationField => $destination,
                                'Events' => ['s3:ObjectCreated:*'],
                            ]],
                        ],
                    ]);
                    $this->fail("Expected unsupported destination {$destination} to be rejected.");
                } catch (S3Exception $e) {
                    $this->assertSame(501, $e->getStatusCode());
                    $this->assertSame('NotImplemented', $e->getAwsErrorCode());
                }
                $current = self::$s3->getBucketNotificationConfiguration(['Bucket' => self::$bucket]);
                $this->assertSame('working-webhook', $current['TopicConfigurations'][0]['Id']);
            }

            try {
                self::$s3->putBucketNotificationConfiguration([
                    'Bucket' => self::$bucket,
                    'NotificationConfiguration' => ['TopicConfigurations' => [[
                        'Id' => 'userinfo',
                        'TopicArn' => 'https://user:secret@notifications.invalid/webhook',
                        'Events' => ['s3:ObjectCreated:*'],
                    ]]],
                ]);
                $this->fail('A notification destination with URL credentials must be rejected.');
            } catch (S3Exception $e) {
                $this->assertSame(400, $e->getStatusCode());
            }
            $current = self::$s3->getBucketNotificationConfiguration(['Bucket' => self::$bucket]);
            $this->assertSame('working-webhook', $current['TopicConfigurations'][0]['Id']);

            try {
                self::$s3->putBucketNotificationConfiguration([
                    'Bucket' => self::$bucket,
                    'NotificationConfiguration' => ['TopicConfigurations' => [[
                        'Id' => 'plaintext',
                        'TopicArn' => 'http://notifications.invalid/webhook',
                        'Events' => ['s3:ObjectCreated:*'],
                    ]]],
                ]);
                $this->fail('A plaintext destination must be rejected when HTTPS is required.');
            } catch (S3Exception $e) {
                $this->assertSame(400, $e->getStatusCode());
                $this->assertSame('InvalidArgument', $e->getAwsErrorCode());
            }
            $current = self::$s3->getBucketNotificationConfiguration(['Bucket' => self::$bucket]);
            $this->assertSame('working-webhook', $current['TopicConfigurations'][0]['Id']);

            self::$s3->putBucketNotificationConfiguration([
                'Bucket' => self::$bucket,
                'NotificationConfiguration' => ['TopicConfigurations' => [[
                    'Id' => 'normalized-scheme',
                    'TopicArn' => 'HTTPS://notifications.invalid/normalized',
                    'Events' => ['s3:ObjectCreated:*'],
                ]]],
            ]);
            $normalized = self::$s3->getBucketNotificationConfiguration(['Bucket' => self::$bucket]);
            $this->assertSame('https://notifications.invalid/normalized', $normalized['TopicConfigurations'][0]['TopicArn']);
        } finally {
            self::$s3->putBucketNotificationConfiguration([
                'Bucket' => self::$bucket,
                'NotificationConfiguration' => [],
            ]);
        }
    }

    public function test_unsupported_notification_events_cannot_replace_working_webhook(): void
    {
        $working = ['TopicConfigurations' => [[
            'Id' => 'working-events',
            'TopicArn' => 'https://notifications.invalid/working',
            'Events' => ['s3:ObjectCreated:Put'],
        ]]];
        self::$s3->putBucketNotificationConfiguration([
            'Bucket' => self::$bucket,
            'NotificationConfiguration' => $working,
        ]);

        try {
            foreach ([
                's3:ObjectRestore:Post',
                's3:LifecycleTransition',
                's3:ObjectTagging:Put',
                's3:Imaginary:Action',
                's3:*',
            ] as $event) {
                $xml = '<NotificationConfiguration><TopicConfiguration><Id>unsupported</Id>'
                    . '<Topic>https://notifications.invalid/unsupported</Topic>'
                    . '<Event>' . $event . '</Event></TopicConfiguration></NotificationConfiguration>';
                $response = $this->sendRawBucketConfiguration(
                    'PutBucketNotificationConfiguration',
                    ['NotificationConfiguration' => $working],
                    $xml,
                );
                $this->assertSame(501, $response->getStatusCode(), $event);
                $this->assertStringContainsString('<Code>NotImplemented</Code>', (string) $response->getBody());
                $current = self::$s3->getBucketNotificationConfiguration(['Bucket' => self::$bucket]);
                $this->assertSame('working-events', $current['TopicConfigurations'][0]['Id']);
            }

            self::$s3->putBucketNotificationConfiguration([
                'Bucket' => self::$bucket,
                'NotificationConfiguration' => ['TopicConfigurations' => [[
                    'Id' => 'marker-events',
                    'TopicArn' => 'https://notifications.invalid/markers',
                    'Events' => ['s3:ObjectRemoved:DeleteMarkerCreated'],
                ]]],
            ]);
            $current = self::$s3->getBucketNotificationConfiguration(['Bucket' => self::$bucket]);
            $this->assertSame('marker-events', $current['TopicConfigurations'][0]['Id']);
        } finally {
            self::$s3->putBucketNotificationConfiguration([
                'Bucket' => self::$bucket,
                'NotificationConfiguration' => [],
            ]);
        }
    }

    public function test_overlapping_notification_filters_are_rejected_without_replacing_configuration(): void
    {
        $working = ['TopicConfigurations' => [[
            'Id' => 'working-filters',
            'TopicArn' => 'https://notifications.invalid/working',
            'Events' => ['s3:ObjectCreated:Put'],
        ]]];
        self::$s3->putBucketNotificationConfiguration([
            'Bucket' => self::$bucket,
            'NotificationConfiguration' => $working,
        ]);

        $first = [
            'Id' => 'images-jpg',
            'TopicArn' => 'https://notifications.invalid/images',
            'Events' => ['s3:ObjectCreated:*'],
            'Filter' => ['Key' => ['FilterRules' => [
                ['Name' => 'prefix', 'Value' => 'images/'],
                ['Name' => 'suffix', 'Value' => '.jpg'],
            ]]],
        ];
        $overlapping = [
            'Id' => 'cats-jpg',
            'TopicArn' => 'https://notifications.invalid/cats',
            'Events' => ['s3:ObjectCreated:Put'],
            'Filter' => ['Key' => ['FilterRules' => [
                ['Name' => 'prefix', 'Value' => 'images/cats/'],
                ['Name' => 'suffix', 'Value' => '.jpg'],
            ]]],
        ];

        try {
            try {
                self::$s3->putBucketNotificationConfiguration([
                    'Bucket' => self::$bucket,
                    'NotificationConfiguration' => ['TopicConfigurations' => [$first, $overlapping]],
                ]);
                $this->fail('Overlapping filters for the same event must be rejected.');
            } catch (S3Exception $e) {
                $this->assertSame(400, $e->getStatusCode());
                $this->assertSame('InvalidArgument', $e->getAwsErrorCode());
            }
            $current = self::$s3->getBucketNotificationConfiguration(['Bucket' => self::$bucket]);
            $this->assertSame('working-filters', $current['TopicConfigurations'][0]['Id']);

            $disjoint = $overlapping;
            $disjoint['Id'] = 'cats-png';
            $disjoint['Filter']['Key']['FilterRules'][1]['Value'] = '.png';
            $differentEvent = $first;
            $differentEvent['Id'] = 'images-jpg-deletes';
            $differentEvent['Events'] = ['s3:ObjectRemoved:Delete'];
            self::$s3->putBucketNotificationConfiguration([
                'Bucket' => self::$bucket,
                'NotificationConfiguration' => ['TopicConfigurations' => [$first, $disjoint, $differentEvent]],
            ]);
            $current = self::$s3->getBucketNotificationConfiguration(['Bucket' => self::$bucket]);
            $this->assertCount(3, $current['TopicConfigurations']);
            $ids = array_column($current['TopicConfigurations'], 'Id');
            sort($ids);
            $this->assertSame(['cats-png', 'images-jpg', 'images-jpg-deletes'], $ids);
        } finally {
            self::$s3->putBucketNotificationConfiguration([
                'Bucket' => self::$bucket,
                'NotificationConfiguration' => [],
            ]);
        }
    }

    public function test_expected_bucket_owner_headers_block_reads_writes_and_copies(): void
    {
        $owner = self::$s3->getBucketAcl(['Bucket' => self::$bucket])['Owner']['ID'];
        $wrongOwner = $owner . '-wrong';

        self::$s3->headBucket(['Bucket' => self::$bucket, 'ExpectedBucketOwner' => $owner]);
        self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'protected.txt',
            'ExpectedBucketOwner' => $owner,
        ]);

        foreach ([
            static fn() => self::$s3->headBucket(['Bucket' => self::$bucket, 'ExpectedBucketOwner' => $wrongOwner]),
            static fn() => self::$s3->getObject(['Bucket' => self::$bucket, 'Key' => 'protected.txt', 'ExpectedBucketOwner' => $wrongOwner]),
            static fn() => self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => 'owner-guard-write.txt', 'Body' => 'blocked', 'ExpectedBucketOwner' => $wrongOwner]),
            static fn() => self::$s3->copyObject(['Bucket' => self::$bucket, 'Key' => 'owner-guard-copy.txt', 'CopySource' => self::$bucket . '/protected.txt', 'ExpectedSourceBucketOwner' => $wrongOwner]),
            static fn() => self::$s3->copyObject(['Bucket' => self::$bucket, 'Key' => 'owner-guard-copy.txt', 'CopySource' => self::$bucket . '/protected.txt', 'ExpectedBucketOwner' => $wrongOwner]),
        ] as $index => $operation) {
            try {
                $operation();
                $this->fail('A mismatched expected bucket owner was accepted.');
            } catch (S3Exception $exception) {
                $this->assertSame(403, $exception->getStatusCode());
                $this->assertSame($index === 0 ? null : 'AccessDenied', $exception->getAwsErrorCode());
            }
        }

        foreach (['owner-guard-write.txt', 'owner-guard-copy.txt'] as $key) {
            $this->assertFalse(self::$s3->doesObjectExistV2(self::$bucket, $key));
        }
    }

    public function test_expected_bucket_owner_guards_bucket_configuration_reads_and_deletes(): void
    {
        $bucket = 'owner-config-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        $owner = self::$s3->getBucketAcl(['Bucket' => $bucket])['Owner']['ID'];
        $wrongOwner = $owner . '-wrong';

        try {
            self::$s3->putBucketCors([
                'Bucket' => $bucket,
                'CORSConfiguration' => ['CORSRules' => [[
                    'AllowedOrigins' => ['https://example.com'],
                    'AllowedMethods' => ['GET'],
                ]]],
            ]);
            self::$s3->putBucketTagging([
                'Bucket' => $bucket,
                'Tagging' => ['TagSet' => [['Key' => 'guard', 'Value' => 'retained']]],
            ]);
            self::$s3->putBucketLifecycleConfiguration([
                'Bucket' => $bucket,
                'LifecycleConfiguration' => ['Rules' => [[
                    'ID' => 'retain',
                    'Status' => 'Enabled',
                    'Filter' => ['Prefix' => 'logs/'],
                    'Expiration' => ['Days' => 30],
                ]]],
            ]);
            self::$s3->putBucketWebsite([
                'Bucket' => $bucket,
                'WebsiteConfiguration' => ['IndexDocument' => ['Suffix' => 'index.html']],
            ]);

            foreach ([
                'GetBucketAcl', 'DeleteBucket',
                'GetBucketCors', 'DeleteBucketCors',
                'GetBucketTagging', 'DeleteBucketTagging',
                'GetBucketLifecycleConfiguration', 'DeleteBucketLifecycle',
                'GetBucketEncryption', 'DeleteBucketEncryption',
                'GetBucketWebsite', 'DeleteBucketWebsite',
            ] as $operation) {
                try {
                    self::$s3->execute(self::$s3->getCommand($operation, [
                        'Bucket' => $bucket,
                        'ExpectedBucketOwner' => $wrongOwner,
                    ]));
                    $this->fail($operation . ' accepted a mismatched expected bucket owner.');
                } catch (S3Exception $exception) {
                    $this->assertSame(403, $exception->getStatusCode(), $operation);
                    $this->assertSame('AccessDenied', $exception->getAwsErrorCode(), $operation);
                }
            }

            $this->assertSame('https://example.com', self::$s3->getBucketCors(['Bucket' => $bucket])['CORSRules'][0]['AllowedOrigins'][0]);
            $this->assertSame('retained', self::$s3->getBucketTagging(['Bucket' => $bucket])['TagSet'][0]['Value']);
            $this->assertSame('retain', self::$s3->getBucketLifecycleConfiguration(['Bucket' => $bucket])['Rules'][0]['ID']);
            $this->assertSame('index.html', self::$s3->getBucketWebsite(['Bucket' => $bucket])['IndexDocument']['Suffix']);
            $this->assertSame($owner, self::$s3->getBucketAcl(['Bucket' => $bucket])['Owner']['ID']);
            $this->assertSame(200, self::$s3->headBucket(['Bucket' => $bucket])['@metadata']['statusCode']);
        } finally {
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_upload_part_copy_checks_expected_source_bucket_owner_before_writing_part(): void
    {
        $owner = self::$s3->getBucketAcl(['Bucket' => self::$bucket])['Owner']['ID'];
        $key = 'owner-guard-part-copy.txt';
        $uploadId = self::$s3->createMultipartUpload(['Bucket' => self::$bucket, 'Key' => $key])['UploadId'];

        try {
            try {
                self::$s3->uploadPartCopy([
                    'Bucket' => self::$bucket,
                    'Key' => $key,
                    'UploadId' => $uploadId,
                    'PartNumber' => 1,
                    'CopySource' => self::$bucket . '/protected.txt',
                    'ExpectedSourceBucketOwner' => $owner . '-wrong',
                ]);
                $this->fail('UploadPartCopy accepted a mismatched source bucket owner.');
            } catch (S3Exception $exception) {
                $this->assertSame(403, $exception->getStatusCode());
                $this->assertSame('AccessDenied', $exception->getAwsErrorCode());
            }

            $this->assertEmpty(self::$s3->listParts([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ])['Parts'] ?? []);
        } finally {
            self::$s3->abortMultipartUpload(['Bucket' => self::$bucket, 'Key' => $key, 'UploadId' => $uploadId]);
        }
    }

    /** @param array<string, mixed> $parameters */
    private function sendRawBucketConfiguration(string $operation, array $parameters, string $xml, ?string $bucket = null): \Psr\Http\Message\ResponseInterface
    {
        if (! in_array($operation, ['CreateBucket', 'PutBucketNotificationConfiguration'], true)) {
            $parameters['ContentMD5'] = base64_encode(md5($xml, true));
        }
        $command = self::$s3->getCommand($operation, ['Bucket' => $bucket ?? self::$bucket] + $parameters);
        $signed = self::$s3->createPresignedRequest($command, '+5 minutes');

        return (new Client(['http_errors' => false]))->put((string) $signed->getUri(), [
            'body' => $xml,
            'headers' => $signed->getHeaders(),
        ]);
    }

    public function test_invalid_create_bucket_configuration_does_not_create_a_bucket(): void
    {
        foreach ([
            '<CreateBucketConfiguration><Unknown>us-west-2</Unknown></CreateBucketConfiguration>',
            '<CreateBucketConfiguration><LocationConstraint/></CreateBucketConfiguration>',
            '<CreateBucketConfiguration xmlns:x="urn:other"><LocationConstraint>us-west-2<x:Ignored/></LocationConstraint></CreateBucketConfiguration>',
            '<CreateBucketConfiguration><LocationConstraint>' . str_repeat('a', 65) . '</LocationConstraint></CreateBucketConfiguration>',
            '<CreateBucketConfiguration><LocationConstraint>us-west-2</LocationConstraint><LocationConstraint>eu-west-1</LocationConstraint></CreateBucketConfiguration>',
        ] as $xml) {
            $bucket = 'invalid-create-' . bin2hex(random_bytes(5));
            $response = $this->sendRawBucketConfiguration('CreateBucket', [], $xml, $bucket);
            self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
            self::assertNotContains($bucket, array_column(self::$s3->listBuckets()['Buckets'], 'Name'));
        }
    }

    public function test_mfa_delete_configuration_is_not_silently_accepted(): void
    {
        try {
            self::$s3->putBucketVersioning([
                'Bucket' => self::$bucket,
                'VersioningConfiguration' => ['Status' => 'Enabled', 'MFADelete' => 'Enabled'],
            ]);
            $this->fail('MFA Delete must not report success without being implemented.');
        } catch (S3Exception $e) {
            $this->assertSame(501, $e->getStatusCode());
        }

        $versioning = self::$s3->getBucketVersioning(['Bucket' => self::$bucket]);
        $this->assertArrayNotHasKey('Status', $versioning);
    }

    public function test_batch_delete_respects_explicit_deny_for_each_object(): void
    {
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => 'batch-allowed.txt', 'Body' => 'allowed']);
        self::$s3->putBucketPolicy([
            'Bucket' => self::$bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Deny',
                    'Principal' => '*',
                    'Action' => 's3:DeleteObject',
                    'Resource' => 'arn:aws:s3:::' . self::$bucket . '/protected.txt',
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            $result = self::$s3->deleteObjects([
                'Bucket' => self::$bucket,
                'Delete' => ['Objects' => [
                    ['Key' => 'protected.txt'],
                    ['Key' => 'batch-allowed.txt'],
                ]],
            ]);

            $this->assertSame(['protected.txt'], array_column($result['Errors'] ?? [], 'Key'));
            $this->assertSame(['batch-allowed.txt'], array_column($result['Deleted'] ?? [], 'Key'));
            $this->assertSame('protected', (string) self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => 'protected.txt',
            ])['Body']);
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'batch-allowed.txt']);
        }
    }

    public function test_specific_version_delete_requires_delete_object_version_permission(): void
    {
        $bucket = 'auth-version-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);
        $put = self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'versioned.txt', 'Body' => 'keep']);
        $versionId = $put['VersionId'];
        self::$s3->putBucketPolicy([
            'Bucket' => $bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Deny',
                    'Principal' => '*',
                    'Action' => 's3:DeleteObjectVersion',
                    'Resource' => 'arn:aws:s3:::' . $bucket . '/versioned.txt',
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            try {
                self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'versioned.txt', 'VersionId' => $versionId]);
                $this->fail('Expected version-specific delete to be denied.');
            } catch (S3Exception $e) {
                $this->assertSame('AccessDenied', $e->getAwsErrorCode());
            }
            $batch = self::$s3->deleteObjects([
                'Bucket' => $bucket,
                'Delete' => ['Objects' => [['Key' => 'versioned.txt', 'VersionId' => $versionId]]],
            ]);
            $this->assertSame(['versioned.txt'], array_column($batch['Errors'] ?? [], 'Key'));
            $this->assertSame('keep', (string) self::$s3->getObject([
                'Bucket' => $bucket,
                'Key' => 'versioned.txt',
                'VersionId' => $versionId,
            ])['Body']);

            self::$s3->putBucketPolicy([
                'Bucket' => $bucket,
                'Policy' => json_encode([
                    'Version' => '2012-10-17',
                    'Statement' => [[
                        'Effect' => 'Deny',
                        'Principal' => '*',
                        'Action' => 's3:GetObjectVersion',
                        'Resource' => 'arn:aws:s3:::' . $bucket . '/versioned.txt',
                    ]],
                ], JSON_THROW_ON_ERROR),
            ]);
            try {
                self::$s3->getObject(['Bucket' => $bucket, 'Key' => 'versioned.txt', 'VersionId' => $versionId]);
                $this->fail('Expected version-specific read to be denied.');
            } catch (S3Exception $e) {
                $this->assertSame('AccessDenied', $e->getAwsErrorCode());
            }
            try {
                self::$s3->copyObject([
                    'Bucket' => $bucket,
                    'Key' => 'copy.txt',
                    'CopySource' => $bucket . '/versioned.txt?versionId=' . rawurlencode($versionId),
                ]);
                $this->fail('Expected copy from the denied source version to be rejected.');
            } catch (S3Exception $e) {
                $this->assertSame('AccessDenied', $e->getAwsErrorCode());
            }
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => $bucket]);
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'versioned.txt', 'VersionId' => $versionId]);
            self::deleteBucketWithDiagnostics($bucket);
        }
    }

    public function test_versioned_acl_and_tagging_subresources_require_version_actions(): void
    {
        $bucket = 'auth-version-meta-' . bin2hex(random_bytes(4));
        $key = 'history.txt';
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);
        $oldVersion = self::$s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'old'])['VersionId'];
        self::$s3->putObjectTagging([
            'Bucket' => $bucket,
            'Key' => $key,
            'VersionId' => $oldVersion,
            'Tagging' => ['TagSet' => [['Key' => 'state', 'Value' => 'retained']]],
        ]);
        self::$s3->putObjectAcl([
            'Bucket' => $bucket,
            'Key' => $key,
            'VersionId' => $oldVersion,
            'ACL' => 'public-read',
        ]);
        $newVersion = self::$s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'new'])['VersionId'];

        try {
            foreach ([
                's3:GetObjectVersionAcl' => static fn() => self::$s3->getObjectAcl(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $oldVersion]),
                's3:PutObjectVersionAcl' => static fn() => self::$s3->putObjectAcl(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $oldVersion, 'ACL' => 'private']),
                's3:GetObjectVersionTagging' => static fn() => self::$s3->getObjectTagging(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $oldVersion]),
                's3:PutObjectVersionTagging' => static fn() => self::$s3->putObjectTagging([
                    'Bucket' => $bucket,
                    'Key' => $key,
                    'VersionId' => $oldVersion,
                    'Tagging' => ['TagSet' => [['Key' => 'state', 'Value' => 'changed']]],
                ]),
                's3:DeleteObjectVersionTagging' => static fn() => self::$s3->deleteObjectTagging(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $oldVersion]),
            ] as $action => $operation) {
                self::$s3->putBucketPolicy([
                    'Bucket' => $bucket,
                    'Policy' => json_encode([
                        'Version' => '2012-10-17',
                        'Statement' => [[
                            'Effect' => 'Deny',
                            'Principal' => '*',
                            'Action' => $action,
                            'Resource' => 'arn:aws:s3:::' . $bucket . '/' . $key,
                        ]],
                    ], JSON_THROW_ON_ERROR),
                ]);

                try {
                    try {
                        $operation();
                        $this->fail($action . ' deny was bypassed.');
                    } catch (S3Exception $exception) {
                        $this->assertSame(403, $exception->getStatusCode(), $action);
                        $this->assertSame('AccessDenied', $exception->getAwsErrorCode(), $action);
                    }
                } finally {
                    self::$s3->deleteBucketPolicy(['Bucket' => $bucket]);
                }
            }

            self::$s3->putBucketPolicy([
                'Bucket' => $bucket,
                'Policy' => json_encode([
                    'Version' => '2012-10-17',
                    'Statement' => [[
                        'Effect' => 'Deny',
                        'Principal' => '*',
                        'Action' => 's3:GetObjectVersion',
                        'Resource' => 'arn:aws:s3:::' . $bucket . '/' . $key,
                    ]],
                ], JSON_THROW_ON_ERROR),
            ]);
            try {
                foreach ([
                    'HeadObject' => static fn() => self::$s3->headObject(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $oldVersion]),
                    'GetObjectAttributes' => static fn() => self::$s3->getObjectAttributes([
                        'Bucket' => $bucket,
                        'Key' => $key,
                        'VersionId' => $oldVersion,
                        'ObjectAttributes' => ['ETag'],
                    ]),
                ] as $operationName => $operation) {
                    try {
                        $operation();
                        $this->fail($operationName . ' bypassed the version read deny.');
                    } catch (S3Exception $exception) {
                        $this->assertSame(403, $exception->getStatusCode(), $operationName);
                    }
                }
            } finally {
                self::$s3->deleteBucketPolicy(['Bucket' => $bucket]);
            }

            $acl = self::$s3->getObjectAcl(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $oldVersion]);
            $publicReadGrants = array_filter(
                $acl['Grants'],
                static fn(array $grant): bool => ($grant['Grantee']['URI'] ?? null) === 'http://acs.amazonaws.com/groups/global/AllUsers'
                    && $grant['Permission'] === 'READ',
            );
            $this->assertCount(1, $publicReadGrants);
            $this->assertSame('retained', self::$s3->getObjectTagging([
                'Bucket' => $bucket,
                'Key' => $key,
                'VersionId' => $oldVersion,
            ])['TagSet'][0]['Value']);
        } finally {
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $oldVersion]);
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $newVersion]);
            self::deleteBucketWithDiagnostics($bucket);
        }
    }

    public function test_get_object_attributes_requires_its_second_iam_action_for_current_and_explicit_versions(): void
    {
        $bucket = 'attributes-policy-' . bin2hex(random_bytes(4));
        $key = 'attributes.txt';
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);
        $old = self::$s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'old']);
        $current = self::$s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'current']);

        try {
            foreach ([
                ['s3:GetObjectAttributes', null],
                ['s3:GetObjectVersionAttributes', $old['VersionId']],
            ] as [$action, $versionId]) {
                self::$s3->putBucketPolicy([
                    'Bucket' => $bucket,
                    'Policy' => json_encode([
                        'Version' => '2012-10-17',
                        'Statement' => [[
                            'Effect' => 'Deny',
                            'Principal' => '*',
                            'Action' => $action,
                            'Resource' => 'arn:aws:s3:::' . $bucket . '/' . $key,
                        ]],
                    ], JSON_THROW_ON_ERROR),
                ]);
                $request = ['Bucket' => $bucket, 'Key' => $key];
                if ($versionId !== null) {
                    $request['VersionId'] = $versionId;
                }
                self::assertSame(200, self::$s3->getObject($request)['@metadata']['statusCode']);
                try {
                    self::$s3->getObjectAttributes($request + ['ObjectAttributes' => ['ETag']]);
                    self::fail($action . ' Deny was bypassed.');
                } catch (S3Exception $exception) {
                    self::assertSame(403, $exception->getStatusCode(), $action);
                    self::assertSame('AccessDenied', $exception->getAwsErrorCode(), $action);
                }
                self::$s3->deleteBucketPolicy(['Bucket' => $bucket]);
            }
        } finally {
            try {
                self::$s3->deleteBucketPolicy(['Bucket' => $bucket]);
            } catch (S3Exception) {
            }
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $old['VersionId']]);
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key, 'VersionId' => $current['VersionId']]);
            self::deleteBucketWithDiagnostics($bucket);
        }
    }

    public function test_put_object_with_tags_respects_put_object_tagging_deny(): void
    {
        $key = 'tagged-denied.txt';
        self::$s3->putBucketPolicy([
            'Bucket' => self::$bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Deny',
                    'Principal' => '*',
                    'Action' => 's3:PutObjectTagging',
                    'Resource' => 'arn:aws:s3:::' . self::$bucket . '/' . $key,
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            try {
                self::$s3->putObject([
                    'Bucket' => self::$bucket,
                    'Key' => $key,
                    'Body' => 'denied',
                    'Tagging' => 'private=yes',
                ]);
                $this->fail('Expected tagging deny to reject the PUT.');
            } catch (S3Exception $e) {
                $this->assertSame('AccessDenied', $e->getAwsErrorCode());
            }
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
        }
    }

    public function test_copy_object_with_tags_respects_put_object_tagging_deny(): void
    {
        $key = 'copy-tag-denied.txt';
        self::$s3->putBucketPolicy([
            'Bucket' => self::$bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Deny',
                    'Principal' => '*',
                    'Action' => 's3:PutObjectTagging',
                    'Resource' => 'arn:aws:s3:::' . self::$bucket . '/' . $key,
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            try {
                self::$s3->copyObject([
                    'Bucket' => self::$bucket,
                    'Key' => $key,
                    'CopySource' => self::$bucket . '/protected.txt',
                    'TaggingDirective' => 'REPLACE',
                    'Tagging' => 'private=yes',
                ]);
                $this->fail('Expected tagging deny to reject the copy.');
            } catch (S3Exception $e) {
                $this->assertSame('AccessDenied', $e->getAwsErrorCode());
            }
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
        }
    }

    public function test_copy_tag_policy_uses_only_tags_written_by_replace_directive(): void
    {
        $source = 'copy-tag-policy-source.txt';
        $destination = 'copy-tag-policy-destination.txt';
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $source,
            'Body' => 'source',
            'Tagging' => 'project=forbidden',
        ]);
        self::$s3->putBucketPolicy([
            'Bucket' => self::$bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Deny',
                    'Principal' => '*',
                    'Action' => 's3:PutObject',
                    'Resource' => 'arn:aws:s3:::' . self::$bucket . '/' . $destination,
                    'Condition' => ['StringNotEquals' => ['s3:RequestObjectTag/project' => 'approved']],
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            try {
                self::$s3->copyObject([
                    'Bucket' => self::$bucket,
                    'Key' => $destination,
                    'CopySource' => self::$bucket . '/' . $source,
                    'TaggingDirective' => 'COPY',
                    'Tagging' => 'project=approved',
                ]);
                self::fail('COPY must not authorize from a tagging header it ignores.');
            } catch (S3Exception $exception) {
                self::assertSame('AccessDenied', $exception->getAwsErrorCode());
            }

            self::$s3->copyObject([
                'Bucket' => self::$bucket,
                'Key' => $destination,
                'CopySource' => self::$bucket . '/' . $source,
                'TaggingDirective' => 'REPLACE',
                'Tagging' => 'project=approved',
            ]);
            self::assertSame('approved', self::$s3->getObjectTagging([
                'Bucket' => self::$bucket,
                'Key' => $destination,
            ])['TagSet'][0]['Value']);
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $source]);
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $destination]);
        }
    }

    public function test_put_object_with_acl_respects_put_object_acl_deny(): void
    {
        $key = 'acl-denied.txt';
        self::$s3->putBucketPolicy([
            'Bucket' => self::$bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Deny',
                    'Principal' => '*',
                    'Action' => 's3:PutObjectAcl',
                    'Resource' => 'arn:aws:s3:::' . self::$bucket . '/' . $key,
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            try {
                self::$s3->putObject([
                    'Bucket' => self::$bucket,
                    'Key' => $key,
                    'Body' => 'denied',
                    'ACL' => 'private',
                ]);
                $this->fail('Expected ACL deny to reject the PUT.');
            } catch (S3Exception $e) {
                $this->assertSame('AccessDenied', $e->getAwsErrorCode());
            }
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
        }
    }

    public function test_multipart_initiation_with_tags_respects_put_object_tagging_deny(): void
    {
        $key = 'multipart-tag-denied.txt';
        self::$s3->putBucketPolicy([
            'Bucket' => self::$bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Deny',
                    'Principal' => '*',
                    'Action' => 's3:PutObjectTagging',
                    'Resource' => 'arn:aws:s3:::' . self::$bucket . '/' . $key,
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            try {
                self::$s3->createMultipartUpload([
                    'Bucket' => self::$bucket,
                    'Key' => $key,
                    'Tagging' => 'private=yes',
                ]);
                $this->fail('Expected tagging deny to reject multipart initiation.');
            } catch (S3Exception $e) {
                $this->assertSame('AccessDenied', $e->getAwsErrorCode());
            }
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
        }
    }

    public function test_multipart_initiation_with_acl_respects_put_object_acl_deny(): void
    {
        $key = 'multipart-acl-denied.txt';
        self::$s3->putBucketPolicy([
            'Bucket' => self::$bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Deny',
                    'Principal' => '*',
                    'Action' => 's3:PutObjectAcl',
                    'Resource' => 'arn:aws:s3:::' . self::$bucket . '/' . $key,
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            try {
                self::$s3->createMultipartUpload([
                    'Bucket' => self::$bucket,
                    'Key' => $key,
                    'ACL' => 'private',
                ]);
                $this->fail('Expected ACL deny to reject multipart initiation.');
            } catch (S3Exception $e) {
                $this->assertSame('AccessDenied', $e->getAwsErrorCode());
            }
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
        }
    }

    public function test_multipart_completion_rechecks_put_object_permission_and_preserves_retry(): void
    {
        $key = 'multipart-completion-policy.txt';
        $uploadId = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Tagging' => 'stage=ready',
            'ACL' => 'private',
        ])['UploadId'];
        $part = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => 'retry payload',
        ]);
        $completion = [
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => $part['ETag']]]],
        ];

        self::$s3->putBucketPolicy([
            'Bucket' => self::$bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Deny',
                    'Principal' => '*',
                    'Action' => 's3:PutObject',
                    'Resource' => 'arn:aws:s3:::' . self::$bucket . '/' . $key,
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            try {
                self::$s3->completeMultipartUpload($completion);
                $this->fail('Expected a revoked PutObject permission to reject completion.');
            } catch (S3Exception $e) {
                $this->assertSame('AccessDenied', $e->getAwsErrorCode());
            }
            $parts = self::$s3->listParts([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);
            $this->assertCount(1, $parts['Parts']);
            $this->assertSame($part['ETag'], $parts['Parts'][0]['ETag']);
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
        }

        try {
            self::$s3->completeMultipartUpload($completion);
            $this->assertSame('retry payload', (string) self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
            ])['Body']);
            $this->assertSame('ready', self::$s3->getObjectTagging([
                'Bucket' => self::$bucket,
                'Key' => $key,
            ])['TagSet'][0]['Value']);
        } finally {
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
        }
    }

    public function test_restrict_public_buckets_blocks_anonymous_public_policy_access(): void
    {
        self::$s3->putBucketPolicy([
            'Bucket' => self::$bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Allow',
                    'Principal' => '*',
                    'Action' => 's3:GetObject',
                    'Resource' => 'arn:aws:s3:::' . self::$bucket . '/*',
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::$s3->putPublicAccessBlock([
            'Bucket' => self::$bucket,
            'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => false,
                'IgnorePublicAcls' => false,
                'BlockPublicPolicy' => false,
                'RestrictPublicBuckets' => true,
            ],
        ]);

        try {
            $response = $this->http()->get($this->url(self::$bucket . '/protected.txt'));
            $this->assertSame(403, $response->getStatusCode());

            $ownerRead = self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => 'protected.txt',
            ]);
            $this->assertSame('protected', (string) $ownerRead['Body']);
        } finally {
            self::$s3->deletePublicAccessBlock(['Bucket' => self::$bucket]);
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
        }
    }

    public function test_block_public_acls_rejects_public_acl_during_put_object(): void
    {
        self::$s3->putPublicAccessBlock([
            'Bucket' => self::$bucket,
            'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => true,
                'IgnorePublicAcls' => false,
                'BlockPublicPolicy' => false,
                'RestrictPublicBuckets' => false,
            ],
        ]);

        try {
            self::$s3->putObject([
                'Bucket' => self::$bucket,
                'Key' => 'blocked-public-acl.txt',
                'Body' => 'must not be stored',
                'ACL' => 'public-read',
            ]);
            $this->fail('Expected BlockPublicAcls to reject PutObject.');
        } catch (S3Exception $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('AccessDenied', $e->getAwsErrorCode());
        } finally {
            self::$s3->deletePublicAccessBlock(['Bucket' => self::$bucket]);
        }
    }

    public function test_block_public_policy_rejects_not_principal_policy_that_includes_anonymous_users(): void
    {
        self::$s3->putPublicAccessBlock([
            'Bucket' => self::$bucket,
            'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => false,
                'IgnorePublicAcls' => false,
                'BlockPublicPolicy' => true,
                'RestrictPublicBuckets' => false,
            ],
        ]);

        try {
            self::$s3->putBucketPolicy([
                'Bucket' => self::$bucket,
                'Policy' => json_encode([
                    'Version' => '2012-10-17',
                    'Statement' => [[
                        'Effect' => 'Allow',
                        'NotPrincipal' => ['AWS' => 'tenant:owner'],
                        'Action' => 's3:GetObject',
                        'Resource' => 'arn:aws:s3:::' . self::$bucket . '/*',
                    ]],
                ], JSON_THROW_ON_ERROR),
            ]);
            $this->fail('Expected BlockPublicPolicy to reject anonymous NotPrincipal access.');
        } catch (S3Exception $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('AccessDenied', $e->getAwsErrorCode());
        } finally {
            self::$s3->deletePublicAccessBlock(['Bucket' => self::$bucket]);
            try {
                self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
            } catch (S3Exception) {
            }
        }
    }

    private function http(): Client
    {
        return new Client(['http_errors' => false, 'timeout' => 10]);
    }

    private function url(string $path): string
    {
        return sprintf('http://%s:%d/%s', self::$host, self::$port, $path);
    }
}
