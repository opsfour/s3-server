<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\Schema\SqliteSchema;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Exception\MalformedPolicyException;
use PHPUnit\Framework\TestCase;

final class PolicyMetadataStoreTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/s3-policy-metadata-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->store = new SqliteMetadataStore($this->path);
        $this->store->initialize();
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');

        parent::tearDown();
    }

    public function test_account_policy_round_trip_and_delete(): void
    {
        $policy = self::policyJson('s3:GetObject');

        $this->assertNull($this->store->getAccountPolicy('tenant-a'));

        $this->store->putAccountPolicy('tenant-a', $policy);
        $this->assertSame($policy, $this->store->getAccountPolicy('tenant-a'));

        $updated = self::policyJson('s3:PutObject');
        $this->store->putAccountPolicy('tenant-a', $updated);
        $this->assertSame($updated, $this->store->getAccountPolicy('tenant-a'));

        $this->store->deleteAccountPolicy('tenant-a');
        $this->assertNull($this->store->getAccountPolicy('tenant-a'));
    }

    public function test_named_policy_round_trip_and_delete(): void
    {
        $policy = self::policyJson('s3:ListBucket');

        $this->assertNull($this->store->getNamedPolicy('readonly'));

        $this->store->putNamedPolicy('readonly', $policy);
        $this->assertSame($policy, $this->store->getNamedPolicy('readonly'));

        $updated = self::policyJson('s3:GetObject');
        $this->store->putNamedPolicy('readonly', $updated);
        $this->assertSame($updated, $this->store->getNamedPolicy('readonly'));

        $this->store->deleteNamedPolicy('readonly');
        $this->assertNull($this->store->getNamedPolicy('readonly'));
    }

    public function test_invalid_direct_policy_replacement_preserves_existing_rows(): void
    {
        $valid = self::policyJson('s3:GetObject');
        $invalid = '{"Statement":[{"Effect":"Deny","Action":"s3:GetObject","Resource":"*","Condition":{"StringEquals":{"aws:UserAgent":[]}}}]}';
        $this->store->putAccountPolicy('tenant-a', $valid);
        $this->store->putNamedPolicy('readonly', $valid);

        foreach ([
            fn() => $this->store->putAccountPolicy('tenant-a', $invalid),
            fn() => $this->store->putNamedPolicy('readonly', $invalid),
            fn() => $this->store->putAccountPolicy('tenant-new', $invalid),
            fn() => $this->store->putNamedPolicy('new', $invalid),
        ] as $write) {
            try {
                $write();
                self::fail('Expected malformed direct policy to be rejected.');
            } catch (MalformedPolicyException) {
            }
        }

        self::assertSame($valid, $this->store->getAccountPolicy('tenant-a'));
        self::assertSame($valid, $this->store->getNamedPolicy('readonly'));
        self::assertNull($this->store->getAccountPolicy('tenant-new'));
        self::assertNull($this->store->getNamedPolicy('new'));
        $this->store->putAccountPolicy('tenant-a', '{"Statement":[]}');
        $this->store->putNamedPolicy('readonly', '{"Statement":[]}');
        self::assertSame('{"Statement":[]}', $this->store->getAccountPolicy('tenant-a'));
        self::assertSame('{"Statement":[]}', $this->store->getNamedPolicy('readonly'));
    }

    public function test_schema_migrates_to_current_policy_version(): void
    {
        $this->assertSame(SqliteSchema::VERSION, $this->schemaVersion());
    }

    private static function policyJson(string $action): string
    {
        return json_encode([
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Principal' => '*',
                    'Action' => $action,
                    'Resource' => '*',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function schemaVersion(): int
    {
        $pdo = $this->pdo();
        $stmt = $pdo->query('SELECT MAX(version) FROM s3_schema_version');
        $value = $stmt !== false ? $stmt->fetchColumn() : false;

        return $value !== false ? (int) $value : 0;
    }

    private function pdo(): \PDO
    {
        $reflection = new \ReflectionClass($this->store);
        $method = $reflection->getMethod('connection');

        /** @var \PDO $pdo */
        $pdo = $method->invoke($this->store);

        return $pdo;
    }
}
