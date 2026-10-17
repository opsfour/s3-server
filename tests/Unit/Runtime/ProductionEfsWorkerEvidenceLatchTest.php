<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\TaskFailureThrowable;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\EfsStorageEvidence;
use OpsFour\S3Server\Runtime\ProductionEfsHostPin;
use OpsFour\S3Server\Runtime\ProductionEfsIdentity;
use OpsFour\S3Server\Runtime\ProductionEfsWatcherEvidenceFileReader;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerEvidenceException;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerEvidenceLatch;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerEvidenceScope;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerStorageEvidence;
use OpsFour\S3Server\Storage\LocalEfsStoredPathCheck;
use OpsFour\S3Server\Tests\Support\ProductionEfsEvidenceLatchWorkerTask;
use OpsFour\S3Server\Tests\Support\ProductionEfsStorageEvidenceWorkerTask;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductionEfsWorkerEvidenceLatchTest extends TestCase
{
    private const int NOW_NS = 1_000_000_000_000;

    private string $base;

    private string $evidenceDirectory;

    private string $path;

    protected function setUp(): void
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        self::assertIsString($temporaryRoot);
        $this->base = $temporaryRoot . '/s3-production-worker-latch-' . bin2hex(random_bytes(6));
        $this->evidenceDirectory = $this->base . '/evidence';
        self::assertTrue(mkdir($this->base, 0o700));
        self::assertTrue(mkdir($this->evidenceDirectory, 0o755));
        $this->path = $this->evidenceDirectory . '/'
            . ProductionEfsWatcherEvidenceFileReader::fileNameFor($this->identity(), $this->hostPin());
        $this->writeRecord();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->evidenceDirectory . '/*') ?: [] as $path) {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->evidenceDirectory)) {
            rmdir($this->evidenceDirectory);
        }
        if (is_dir($this->base)) {
            rmdir($this->base);
        }
    }

    public function test_revocation_survives_repair_between_tasks_until_the_worker_is_restarted(): void
    {
        $scope = $this->scope();
        self::assertTrue(unlink($this->path));

        $pool = new ContextWorkerPool(1);
        try {
            $first = $pool->submit(new ProductionEfsEvidenceLatchWorkerTask($scope))->getFuture()->await();
            self::assertFalse($first['accepted']);
            self::assertSame('Production EFS worker evidence validation failed.', $first['error']);

            $this->writeRecord();
            $second = $pool->submit(new ProductionEfsEvidenceLatchWorkerTask($scope))->getFuture()->await();
            self::assertSame($first['pid'], $second['pid']);
            self::assertFalse($second['accepted']);
            self::assertSame('Production EFS worker evidence validation failed.', $second['error']);

            $serialized = serialize($scope);
            $otherScope = unserialize(str_replace(str_repeat('a', 32), str_repeat('f', 32), $serialized));
            self::assertInstanceOf(ProductionEfsWorkerEvidenceScope::class, $otherScope);
            $different = $pool->submit(new ProductionEfsEvidenceLatchWorkerTask($otherScope))->getFuture()->await();
            self::assertSame($first['pid'], $different['pid']);
            self::assertFalse($different['accepted']);
            self::assertSame('Production EFS worker evidence validation failed.', $different['error']);

            $poisoned = $pool->submit(new ProductionEfsEvidenceLatchWorkerTask($scope))->getFuture()->await();
            self::assertSame($first['pid'], $poisoned['pid']);
            self::assertFalse($poisoned['accepted']);
            self::assertSame('Production EFS worker evidence validation failed.', $poisoned['error']);
        } finally {
            $pool->shutdown();
        }

        $replacement = new ContextWorkerPool(1);
        try {
            $third = $replacement->submit(new ProductionEfsEvidenceLatchWorkerTask($scope))->getFuture()->await();
            self::assertTrue($third['accepted']);
            self::assertSame('', $third['error']);
        } finally {
            $replacement->shutdown();
        }
    }

    public function test_scope_binding_compares_numeric_hex_fields_byte_for_byte(): void
    {
        $serialized = serialize($this->scope());
        $firstScope = unserialize(str_replace(
            str_repeat('a', 32),
            '10000000000000000000000000000000',
            $serialized,
        ));
        $secondScope = unserialize(str_replace(
            str_repeat('a', 32),
            '00000000000000000000000000001e31',
            $serialized,
        ));
        self::assertInstanceOf(ProductionEfsWorkerEvidenceScope::class, $firstScope);
        self::assertInstanceOf(ProductionEfsWorkerEvidenceScope::class, $secondScope);
        self::assertTrue($firstScope == $secondScope);
        self::assertNotSame(get_object_vars($firstScope), get_object_vars($secondScope));
        $this->writeRecord('10000000000000000000000000000000');

        $pool = new ContextWorkerPool(1);
        try {
            $first = $pool->submit(new ProductionEfsEvidenceLatchWorkerTask($firstScope))->getFuture()->await();
            self::assertTrue($first['accepted']);
            self::assertSame('', $first['error']);

            $second = $pool->submit(new ProductionEfsEvidenceLatchWorkerTask($secondScope))->getFuture()->await();
            self::assertSame($first['pid'], $second['pid']);
            self::assertFalse($second['accepted']);
            self::assertSame('Production EFS worker evidence validation failed.', $second['error']);

            $poisoned = $pool->submit(new ProductionEfsEvidenceLatchWorkerTask($firstScope))->getFuture()->await();
            self::assertSame($first['pid'], $poisoned['pid']);
            self::assertFalse($poisoned['accepted']);
            self::assertSame('Production EFS worker evidence validation failed.', $poisoned['error']);
        } finally {
            $pool->shutdown();
        }
    }

    public function test_dedicated_failure_class_crosses_the_amp_process_channel_without_details(): void
    {
        $scope = $this->scope();
        self::assertTrue(unlink($this->path));

        $pool = new ContextWorkerPool(1);
        try {
            $pool->submit(new ProductionEfsEvidenceLatchWorkerTask($scope, true))->getFuture()->await();
            self::fail('Invalid production EFS evidence crossed the worker boundary as success.');
        } catch (TaskFailureThrowable $error) {
            self::assertSame(ProductionEfsWorkerEvidenceException::class, $error->getOriginalClassName());
            self::assertSame('Production EFS worker evidence validation failed.', $error->getOriginalMessage());
            self::assertStringNotContainsString($this->base, $error->getOriginalMessage());
            self::assertStringNotContainsString($scope->deploymentId, $error->getOriginalMessage());
        } finally {
            $pool->shutdown();
        }
    }

    public function test_public_api_exposes_only_scope_bound_validation(): void
    {
        $method = new \ReflectionMethod(ProductionEfsWorkerEvidenceLatch::class, 'assertFresh');
        self::assertTrue($method->isPublic());
        self::assertTrue($method->isStatic());
        self::assertSame(1, $method->getNumberOfParameters());
        $parameter = $method->getParameters()[0]->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $parameter);
        self::assertSame(ProductionEfsWorkerEvidenceScope::class, $parameter->getName());
        $return = $method->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $return);
        self::assertSame('void', $return->getName());
        self::assertFalse((new \ReflectionMethod(
            ProductionEfsWorkerEvidenceLatch::class,
            'assertFreshWithIo',
        ))->isPublic());
    }

    public function test_storage_adapter_revalidates_in_a_real_worker_and_binds_the_scope_root(): void
    {
        $scope = $this->scope();
        $pool = new ContextWorkerPool(1);
        try {
            $result = $pool->submit(new ProductionEfsStorageEvidenceWorkerTask($scope))->getFuture()->await();
            self::assertGreaterThan(0, $result['pid']);
            self::assertSame($scope->storageRoot, $result['storageRoot']);
            self::assertSame(2, $result['successfulChecks']);
            self::assertTrue($result['rejectedAfterRemoval']);
        } finally {
            $pool->shutdown();
        }
    }

    public function test_storage_adapter_is_accepted_by_root_bound_physical_fences(): void
    {
        $scope = $this->scope();
        $evidence = new ProductionEfsWorkerStorageEvidence($scope);
        self::assertSame($scope->storageRoot, $evidence->storageRoot());

        new LocalEfsStoredPathCheck($scope->storageRoot, $evidence);

        $this->expectException(\InvalidArgumentException::class);
        new LocalEfsStoredPathCheck($scope->storageRoot . '/other', $evidence);
    }

    public function test_physical_fence_rejects_an_untrusted_interface_implementation(): void
    {
        $untrusted = new readonly class implements EfsStorageEvidence {
            public function assertFresh(): void {}

            public function storageRoot(): string
            {
                return '/untrusted';
            }
        };

        $this->expectException(\TypeError::class);
        (new \ReflectionClass(LocalEfsStoredPathCheck::class))->newInstance('/untrusted', $untrusted);
    }

    /** @param class-string $class */
    #[DataProvider('closedPhysicalEvidenceBoundaries')]
    public function test_physical_evidence_boundary_accepts_only_the_two_final_readers(
        string $class,
        string $parameterName,
    ): void {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        self::assertInstanceOf(\ReflectionMethod::class, $constructor);
        $parameter = array_values(array_filter(
            $constructor->getParameters(),
            static fn(\ReflectionParameter $parameter): bool => $parameter->getName() === $parameterName,
        ))[0] ?? null;
        self::assertInstanceOf(\ReflectionParameter::class, $parameter);
        $type = $parameter->getType();
        self::assertInstanceOf(\ReflectionUnionType::class, $type);
        $names = [];
        foreach ($type->getTypes() as $namedType) {
            if (! $namedType instanceof \ReflectionNamedType) {
                throw new \LogicException('Physical evidence boundary contains a nested intersection.');
            }
            $names[] = $namedType->getName();
        }
        sort($names);
        $expected = [EfsHostEvidenceReader::class, ProductionEfsWorkerStorageEvidence::class];
        if ($parameter->allowsNull()) {
            $expected[] = 'null';
        }
        sort($expected);
        self::assertSame($expected, $names);
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function closedPhysicalEvidenceBoundaries(): iterable
    {
        yield 'bucket namespace' => [\OpsFour\S3Server\Storage\LocalEfsBucketNamespace::class, 'evidence'];
        yield 'chunk read' => [\OpsFour\S3Server\Storage\LocalEfsChunkReadFence::class, 'evidence'];
        yield 'delete' => [\OpsFour\S3Server\Storage\LocalEfsDeleteFence::class, 'evidence'];
        yield 'stored path' => [LocalEfsStoredPathCheck::class, 'evidence'];
        yield 'temp cleanup' => [\OpsFour\S3Server\Storage\LocalEfsTempCleanup::class, 'evidence'];
        yield 'temp size' => [\OpsFour\S3Server\Storage\LocalEfsTempSizeFence::class, 'evidence'];
        yield 'encrypted completion' => [\OpsFour\S3Server\Storage\LocalEncryptedMultipartCompletionFence::class, 'evidence'];
        yield 'assembly' => [\OpsFour\S3Server\Storage\LocalMultipartAssemblyFence::class, 'evidence'];
        yield 'part write' => [\OpsFour\S3Server\Storage\LocalMultipartPartWriteFence::class, 'evidence'];
        yield 'multipart path' => [\OpsFour\S3Server\Storage\LocalMultipartPathFence::class, 'efsEvidence'];
        yield 'object write' => [\OpsFour\S3Server\Storage\LocalObjectWriteFence::class, 'efsEvidence'];
    }

    private function scope(): ProductionEfsWorkerEvidenceScope
    {
        $reader = new ProductionEfsWatcherEvidenceFileReader(
            $this->identity(),
            $this->hostPin(),
            static fn(): int => self::NOW_NS,
            static fn(): string => '11111111-1234-1234-1234-123456789abc',
            fn(): string => self::mountInfo($this->evidenceDirectory),
            static fn(string $path): array|false => self::rootStat(@lstat($path)),
            static fn($handle): array|false => self::rootStat(@fstat($handle)),
        );

        return $reader->workerScope();
    }

    private function identity(?string $deploymentId = null): ProductionEfsIdentity
    {
        $deploymentId ??= str_repeat('a', 32);

        return ProductionEfsIdentity::fromJson(json_encode([
            'schemaVersion' => 2,
            'profile' => 'production',
            'deploymentId' => $deploymentId,
            'instanceId' => str_repeat('b', 32),
            'efsId' => 'fs-0123456789abcdef0',
            'sourceSha256' => str_repeat('c', 64),
            'metadataDriver' => 'postgres',
            'mountPoint' => '/nonexistent-efs',
            'storageRoot' => '/nonexistent-efs/objects',
            'scratchPath' => '/tmp/opsfour-s3',
            'evidenceDirectory' => $this->evidenceDirectory,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function hostPin(?string $deploymentId = null): ProductionEfsHostPin
    {
        $deploymentId ??= str_repeat('a', 32);

        return ProductionEfsHostPin::fromJson(json_encode([
            'schemaVersion' => 2,
            'profile' => 'production',
            'deploymentId' => $deploymentId,
            'instanceId' => str_repeat('b', 32),
            'containerId' => str_repeat('d', 64),
            'bootId' => '11111111-1234-1234-1234-123456789abc',
            'hostMountId' => '39',
            'storageDev' => '2048',
            'storageIno' => '3001',
            'epoch' => str_repeat('e', 32),
        ], JSON_THROW_ON_ERROR));
    }

    private function writeRecord(?string $deploymentId = null): void
    {
        $deploymentId ??= str_repeat('a', 32);
        $path = $this->evidenceDirectory . '/'
            . ProductionEfsWatcherEvidenceFileReader::fileNameFor(
                $this->identity($deploymentId),
                $this->hostPin($deploymentId),
            );
        self::assertIsInt(file_put_contents($path, json_encode([
            'schemaVersion' => 2,
            'profile' => 'production',
            'deploymentId' => $deploymentId,
            'instanceId' => str_repeat('b', 32),
            'efsId' => 'fs-0123456789abcdef0',
            'sourceSha256' => str_repeat('c', 64),
            'metadataDriver' => 'postgres',
            'mountPoint' => '/nonexistent-efs',
            'storageRoot' => '/nonexistent-efs/objects',
            'containerId' => str_repeat('d', 64),
            'bootId' => '11111111-1234-1234-1234-123456789abc',
            'hostMountId' => '39',
            'storageDev' => '2048',
            'storageIno' => '3001',
            'epoch' => str_repeat('e', 32),
            'issuedMonotonicNs' => self::NOW_NS - 1_000_000_000,
            'expiresMonotonicNs' => self::NOW_NS + 19_000_000_000,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n"));
        self::assertTrue(chmod($path, 0o644));
    }

    private static function mountInfo(string $evidenceDirectory): string
    {
        return "1 0 0:1 / / rw - ext4 /dev/root rw\n"
            . "2 1 0:2 /evidence {$evidenceDirectory} ro - tmpfs tmpfs rw\n";
    }

    /** @param array<array-key, int>|false $entry
     *  @return array<array-key, int>|false
     */
    private static function rootStat(array|false $entry): array|false
    {
        if ($entry === false) {
            return false;
        }
        $entry['uid'] = 0;
        $entry['mode'] &= ~0o022;

        return $entry;
    }
}
