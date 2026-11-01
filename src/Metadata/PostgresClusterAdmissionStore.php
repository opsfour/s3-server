<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnection;
use Amp\Postgres\PostgresLink;
use OpsFour\S3Server\Metadata\Schema\MetadataSchemaGuard;
use OpsFour\S3Server\Runtime\ClusterAdmissionController;
use OpsFour\S3Server\Runtime\ClusterAdmissionResult;
use OpsFour\S3Server\Runtime\ClusterCapabilityFingerprint;
use OpsFour\S3Server\Runtime\RecoverableClusterAdmissionStore;

use function Amp\Postgres\connect;

/** One lifecycle-owned PostgreSQL connection dedicated to cluster admission. */
final class PostgresClusterAdmissionStore implements RecoverableClusterAdmissionStore
{
    private ?PostgresConnection $connection = null;
    private ?DeferredCancellation $connectCancellation = null;
    private int $generation = 0;
    private bool $closed = false;

    public function __construct(
        private readonly PostgresConfig $config,
        private readonly float $statementTimeoutSeconds = ClusterAdmissionController::DEFAULT_COMPARISON_TIMEOUT_SECONDS,
    ) {
        if (! is_finite($statementTimeoutSeconds) || $statementTimeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('PostgreSQL admission statement timeout must be finite and positive.');
        }
    }

    public static function fromDsn(
        string $dsn,
        float $statementTimeoutSeconds = ClusterAdmissionController::DEFAULT_COMPARISON_TIMEOUT_SECONDS,
    ): self {
        return new self(PostgresConfig::fromString($dsn), $statementTimeoutSeconds);
    }

    public function assertCurrentSchema(): void
    {
        MetadataSchemaGuard::assertCurrentPostgres($this->connection());
    }

    public function clusterDatabaseSchema(): string
    {
        $row = $this->connection()->query('SELECT current_schema() AS schema_name')->fetchRow();
        $schema = $row['schema_name'] ?? null;
        if (! is_string($schema) || $schema === '') {
            throw new \RuntimeException('PostgreSQL cluster schema identity is unavailable.');
        }

        return $schema;
    }

    public function clusterDatabaseIdentity(): string
    {
        $row = $this->connection()->query(
            'SELECT database_identity FROM s3_cluster_database_identity WHERE singleton_id = 1',
        )->fetchRow();
        $identity = $row['database_identity'] ?? null;
        if (! is_string($identity) || preg_match('/\A[a-f0-9]{32}\z/D', $identity) !== 1) {
            throw new \RuntimeException('PostgreSQL cluster database identity is missing or invalid. Run metadata:migrate.');
        }

        return $identity;
    }

    public function initializeOrCompareClusterAdmission(ClusterCapabilityFingerprint $fingerprint): ClusterAdmissionResult
    {
        $transaction = $this->connection()->beginTransaction();
        try {
            $digests = $fingerprint->digests();
            $transaction->execute(
                <<<'SQL'
                INSERT INTO s3_cluster_admission (
                    singleton_id, cluster_id, protocol_digest,
                    database_digest, authentication_digest, storage_digest, tiers_digest, encryption_digest,
                    compatibility_digest, roles_digest
                ) VALUES (1, $1, $2, $3, $4, $5, $6, $7, $8, $9)
                ON CONFLICT (singleton_id) DO NOTHING
                SQL,
                [
                    $fingerprint->clusterId,
                    $digests['protocol'],
                    $digests['database'],
                    $digests['authentication'],
                    $digests['storage'],
                    $digests['tiers'],
                    $digests['encryption'],
                    $digests['compatibility'],
                    $digests['roles'],
                ],
            );
            $result = $this->compareClusterAdmissionRow($transaction, $fingerprint);
            $transaction->commit();

            return $result;
        } catch (\Throwable $error) {
            try {
                if ($transaction->isActive()) {
                    $transaction->rollback();
                }
            } catch (\Throwable) {
                // Preserve the comparison or connection-retirement failure.
            }

            throw $error;
        }
    }

    public function compareClusterAdmission(ClusterCapabilityFingerprint $fingerprint): ClusterAdmissionResult
    {
        return $this->compareClusterAdmissionRow($this->connection(), $fingerprint);
    }

    public function retireConnection(): void
    {
        ++$this->generation;
        $this->connectCancellation?->cancel();
        $this->connectCancellation = null;
        $this->connection?->close();
        $this->connection = null;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->retireConnection();
    }

    private function connection(): PostgresConnection
    {
        if ($this->closed) {
            throw new \LogicException('PostgreSQL cluster admission store is closed.');
        }
        if ($this->connection !== null && ! $this->connection->isClosed()) {
            return $this->connection;
        }

        $generation = $this->generation;
        $deferred = new DeferredCancellation();
        $this->connectCancellation = $deferred;
        try {
            $connection = connect($this->config, $deferred->getCancellation());
        } finally {
            if ($this->connectCancellation === $deferred) {
                $this->connectCancellation = null;
            }
        }
        if ($this->generation !== $generation) {
            $connection->close();
            throw new CancelledException();
        }
        $statementTimeoutMilliseconds = max(1, (int) ceil($this->statementTimeoutSeconds * 1000));
        $connection->query("SET statement_timeout = {$statementTimeoutMilliseconds}");
        if ($this->generation !== $generation) {
            $connection->close();
            throw new CancelledException();
        }

        return $this->connection = $connection;
    }

    private function compareClusterAdmissionRow(
        PostgresLink $link,
        ClusterCapabilityFingerprint $fingerprint,
    ): ClusterAdmissionResult {
        $identityRow = $link->query(
            'SELECT database_identity FROM s3_cluster_database_identity WHERE singleton_id = 1',
        )->fetchRow();
        $databaseIdentity = $identityRow['database_identity'] ?? null;
        if (! is_string($databaseIdentity) || ! hash_equals($fingerprint->databaseIdentity, $databaseIdentity)) {
            return ClusterAdmissionResult::mismatch('database', $fingerprint->databaseIdentity);
        }

        $row = $link->query(
            'SELECT cluster_id, protocol_digest, database_digest, authentication_digest, storage_digest, tiers_digest, encryption_digest, compatibility_digest, roles_digest '
            . 'FROM s3_cluster_admission WHERE singleton_id = 1',
        )->fetchRow();
        if ($row === null) {
            return ClusterAdmissionResult::mismatch('database', '');
        }
        if (! is_string($row['cluster_id'] ?? null) || ! hash_equals($row['cluster_id'], $fingerprint->clusterId)) {
            return ClusterAdmissionResult::mismatch('cluster', $fingerprint->databaseIdentity);
        }
        foreach ($fingerprint->digests() as $category => $digest) {
            $column = $category . '_digest';
            if (! is_string($row[$column] ?? null) || ! hash_equals($row[$column], $digest)) {
                return ClusterAdmissionResult::mismatch($category, $fingerprint->databaseIdentity);
            }
        }

        return ClusterAdmissionResult::matched($fingerprint->databaseIdentity);
    }
}
