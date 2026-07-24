<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;

final class MetricsHandler implements RequestHandler
{
    public function __construct(
        private readonly MetricsCollector $metrics,
        private readonly ?MetadataStore $metadata = null,
        private readonly ?string $bearerToken = null,
    ) {}

    public function handleRequest(Request $request): Response
    {
        if ($this->bearerToken !== null && $this->bearerToken !== '') {
            $authorization = $request->getHeader('authorization') ?? '';
            $provided = str_starts_with(strtolower($authorization), 'bearer ')
                ? trim(substr($authorization, 7))
                : '';
            if (! hash_equals($this->bearerToken, $provided)) {
                return new Response(
                    status: 401,
                    headers: [
                        'Content-Type' => 'text/plain; charset=utf-8',
                        'WWW-Authenticate' => 'Bearer',
                    ],
                    body: 'Unauthorized',
                );
            }
        }

        if ($this->metadata !== null) {
            if ($this->metadata instanceof ObservedMetadataStore) {
                $this->metrics->setNotificationQueueStats($this->metadata->getNotificationQueueStats());

                return $this->response();
            }

            $startedAt = hrtime(true);
            try {
                $this->metrics->setNotificationQueueStats($this->metadata->getNotificationQueueStats());
                $this->metrics->recordBackendOperation(
                    backend: 'metadata',
                    driver: $this->metadataDriver(),
                    operation: 'getNotificationQueueStats',
                    durationNs: hrtime(true) - $startedAt,
                );
            } catch (\Throwable $e) {
                $this->metrics->recordBackendOperation(
                    backend: 'metadata',
                    driver: $this->metadataDriver(),
                    operation: 'getNotificationQueueStats',
                    durationNs: hrtime(true) - $startedAt,
                    success: false,
                    exception: $e::class,
                );

                throw $e;
            }
        }

        return $this->response();
    }

    private function response(): Response
    {
        return new Response(
            status: 200,
            headers: ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8'],
            body: $this->metrics->renderPrometheus(),
        );
    }

    private function metadataDriver(): string
    {
        if ($this->metadata === null) {
            return 'none';
        }

        $parts = explode('\\', $this->metadata::class);

        return $parts[array_key_last($parts)] ?? $this->metadata::class;
    }
}
