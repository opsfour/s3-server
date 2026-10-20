<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use Amp\Cancellation;
use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnection;
use Amp\Mysql\SocketMysqlConnector;
use Amp\Sql\Common\RetrySqlConnector;
use Amp\Sql\SqlConfig;
use Amp\Sql\SqlConnector;

/**
 * Configures every newly opened MySQL connection to return TIMESTAMP values in UTC.
 *
 * @implements SqlConnector<MysqlConfig, MysqlConnection>
 */
final readonly class UtcMysqlConnector implements SqlConnector
{
    /** @var SqlConnector<MysqlConfig, MysqlConnection> */
    private SqlConnector $connector;

    /** @param SqlConnector<MysqlConfig, MysqlConnection>|null $connector */
    public function __construct(?SqlConnector $connector = null)
    {
        $this->connector = $connector ?? new RetrySqlConnector(new SocketMysqlConnector());
    }

    public function connect(SqlConfig $config, ?Cancellation $cancellation = null): MysqlConnection
    {
        if (! $config instanceof MysqlConfig) {
            throw new \TypeError(sprintf('Must provide an instance of %s to MySQL connectors', MysqlConfig::class));
        }

        $connection = $this->connector->connect($config, $cancellation);
        if (! $connection instanceof MysqlConnection) {
            $connection->close();

            throw new \TypeError(sprintf('MySQL connector must return an instance of %s', MysqlConnection::class));
        }

        try {
            $connection->query("SET time_zone = '+00:00'");
        } catch (\Throwable $error) {
            $connection->close();

            throw $error;
        }

        return $connection;
    }
}
