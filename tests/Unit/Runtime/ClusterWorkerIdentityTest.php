<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Runtime\ClusterWorkerIdentity;
use OpsFour\S3Server\S3ServerConfig;
use PHPUnit\Framework\TestCase;

final class ClusterWorkerIdentityTest extends TestCase
{
    public function test_process_incarnation_derives_distinct_bounded_worker_kinds(): void
    {
        $config = new S3ServerConfig(
            storagePath: '/',
            clusterId: 'cluster-a',
            clusterNodeId: str_repeat('n', 128),
            clusterDatabaseId: str_repeat('d', 32),
        );
        $identity = ClusterWorkerIdentity::fromConfig($config);
        $notification = $identity->forKind('notification');
        $lifecycle = $identity->forKind('lifecycle');

        self::assertNotSame($notification, $lifecycle);
        self::assertLessThanOrEqual(192, strlen($notification));
        self::assertStringEndsWith(':notification', $notification);
        self::assertStringContainsString(':' . $config->clusterIncarnationId . ':', $notification);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9][A-Za-z0-9._:-]+\z/D', $notification);
        self::assertSame($notification, ClusterWorkerIdentity::fromConfig($config)->forKind('notification'));
    }
}
