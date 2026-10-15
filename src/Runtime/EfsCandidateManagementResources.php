<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use OpsFour\S3Server\Contracts\CredentialHealthProbe;
use OpsFour\S3Server\Handler\HealthCheckHandler;
use OpsFour\S3Server\S3ServerConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Listenerless candidate binding for one graph's API admission and readiness.
 * This does not attach routes or admit EFS serving.
 *
 * @internal
 */
final readonly class EfsCandidateManagementResources
{
    private function __construct(
        private EfsCandidateRuntimeResources $graph,
        private S3ServerConfig $config,
        private EfsHostEvidenceReader $reader,
        private EfsCandidateGraphAdmissionEvidence $graphAdmissionEvidence,
        private ?CredentialHealthProbe $credentialHealthProbe,
        public EfsCandidateServingAdmission $admission,
        public EfsCandidateReadinessHandler $readiness,
    ) {}

    public static function create(
        EfsCandidateRuntimeResources $graph,
        S3ServerConfig $config,
        LoggerInterface $logger = new NullLogger(),
        ?CredentialHealthProbe $credentialHealthProbe = null,
    ): self {
        if ($graph->buildConfig !== $config) {
            throw new \LogicException('EFS management candidate requires its open graph and exact configuration.');
        }
        $graph->assertOpenForAdmission();
        $graph->qualification->assertCandidateRuntimeInputs(
            $config,
            $graph->metadata,
            $graph->backend,
            $graph->tiers,
            $graph->scratch,
        );
        $reader = $graph->qualification->hostEvidenceReader();
        if ($graph->backend->hostEvidenceReader() !== $reader) {
            throw new \RuntimeException('EFS management candidate requires the graph backend reader.');
        }

        $graphAdmissionEvidence = new EfsCandidateGraphAdmissionEvidence($graph, $reader);
        $admission = new EfsCandidateServingAdmission($graphAdmissionEvidence);
        $health = new HealthCheckHandler(
            metadata: $graph->metadata,
            logger: $logger,
            storageTiers: $graph->tiers,
            rateLimitEnabled: $config->perClientRateLimit > 0,
            efsEvidence: $reader,
            admissionEvidence: $admission,
            credentialHealthProbe: $credentialHealthProbe,
        );
        $readiness = new EfsCandidateReadinessHandler($admission, $health);
        $admission->assertBoundTo($graphAdmissionEvidence);
        $readiness->assertBoundTo($admission);

        return new self(
            $graph,
            $config,
            $reader,
            $graphAdmissionEvidence,
            $credentialHealthProbe,
            $admission,
            $readiness,
        );
    }

    /** This proves identity only; production route attachment remains closed. */
    public function assertBoundTo(EfsCandidateRuntimeResources $graph, S3ServerConfig $config): void
    {
        if ($this->graph !== $graph || $this->config !== $config) {
            throw new \RuntimeException('EFS management candidate belongs to another or closed graph.');
        }
        $graph->assertOpenForAdmission();
        $graph->qualification->assertCandidateRuntimeInputs(
            $config,
            $graph->metadata,
            $graph->backend,
            $graph->tiers,
            $graph->scratch,
        );
        if ($graph->qualification->hostEvidenceReader() !== $this->reader
            || $graph->backend->hostEvidenceReader() !== $this->reader) {
            throw new \RuntimeException('EFS management candidate reader differs from its graph.');
        }
        $this->admission->assertBoundTo($this->graphAdmissionEvidence);
        $this->readiness->assertBoundTo($this->admission);
    }

    /** Bind readiness to the exact provider used by the candidate AuthMiddleware. */
    public function assertCredentialHealthProbe(CredentialHealthProbe $credentialHealthProbe): void
    {
        if ($this->credentialHealthProbe !== $credentialHealthProbe) {
            throw new \RuntimeException('EFS management credential probe differs from candidate authentication.');
        }
    }
}
