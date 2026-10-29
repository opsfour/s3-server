<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use OpsFour\S3Server\S3ServerConfig;

/** Derives bounded, non-secret worker identities from one process incarnation. */
final readonly class ClusterWorkerIdentity
{
    private string $processId;
    private string $nodeId;
    private string $incarnationId;

    public function __construct(string $nodeId, string $incarnationId)
    {
        if ($nodeId === '' || $incarnationId === '') {
            throw new \InvalidArgumentException('Cluster worker identity requires node and incarnation IDs.');
        }
        $this->nodeId = $nodeId;
        $this->incarnationId = $incarnationId;
        // Reserve the delimiter and the full 32-character worker suffix while
        // retaining the complete process incarnation in the 192-byte token.
        $this->processId = substr($nodeId, 0, 126) . ':' . substr($incarnationId, 0, 32);
    }

    public static function fromConfig(S3ServerConfig $config): self
    {
        if (! $config->clusterEnabled() || $config->clusterNodeId === null || $config->clusterIncarnationId === null) {
            throw new \LogicException('Cluster worker identity requires enabled cluster configuration.');
        }

        return new self($config->clusterNodeId, $config->clusterIncarnationId);
    }

    public function assertBoundToConfig(S3ServerConfig $config): void
    {
        if (! $config->clusterEnabled() || $config->clusterNodeId === null || $config->clusterIncarnationId === null
            || ! hash_equals($config->clusterNodeId, $this->nodeId)
            || ! hash_equals($config->clusterIncarnationId, $this->incarnationId)) {
            throw new \LogicException('Cluster worker identity is not bound to this server configuration.');
        }
    }

    public function forKind(string $kind): string
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $kind) !== 1) {
            throw new \InvalidArgumentException('Cluster worker kind is invalid.');
        }

        return $this->processId . ':' . $kind;
    }
}
