<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Notification;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as HttpRequest;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Background processor that delivers queued notifications with retry and dead-letter.
 *
 * Polls the notification queue, delivers webhooks with SSRF protection,
 * and applies exponential backoff on failures.
 */
final class NotificationProcessor
{
    private bool $running = false;

    private readonly HttpClient $httpClient;

    /** @var array<string, array{failures: int, cooldownUntil: float}> Per-destination circuit breakers. */
    private array $circuitBreakers = [];

    private const int CIRCUIT_BREAKER_THRESHOLD = 5;

    private const float CIRCUIT_BREAKER_COOLDOWN = 60.0;

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly float $pollInterval = 2.0,
        ?HttpClient $httpClient = null,
        private readonly ?MetricsCollector $metrics = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClientBuilder::buildDefault();
    }

    public function start(): void
    {
        $this->running = true;
        \Amp\async($this->loop(...));
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function loop(): void
    {
        $idleCount = 0;

        while ($this->running) {
            try {
                $items = $this->metadata->dequeueNotifications(50);
            } catch (\Throwable $e) {
                $this->metrics?->recordNotificationEvent('dequeue', 'failed');
                $this->logger->error('Notification dequeue failed.', $this->logContext([
                    'event' => 'dequeue_failed',
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]));
                \Amp\delay($this->pollInterval * 5);
                continue;
            }

            if ($items === []) {
                // Exponential backoff when idle: 2s → 4s → 8s → max 30s
                $idleCount = min($idleCount + 1, 10);
                $delay = min(30.0, $this->pollInterval * (2 ** min($idleCount - 1, 4)));
                \Amp\delay($delay);
                continue;
            }

            $idleCount = 0; // Reset on activity

            $futures = [];
            foreach ($items as $item) {
                $futures[] = \Amp\async(fn() => $this->process($item));
            }

            try {
                \Amp\Future\await($futures);
            } catch (\Throwable $e) {
                $this->logger->error('Notification batch processing failed.', $this->logContext([
                    'event' => 'batch_failed',
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]));
            }
        }
    }

    /** @param array{id: int, bucket: string, key_name: string, event_name: string, destination_url: string, payload_json: string, attempts: int, max_attempts: int} $item */
    private function process(array $item): void
    {
        $id = (int) $item['id'];
        $destination = $item['destination_url'];
        $attempts = (int) $item['attempts'];
        $maxAttempts = (int) $item['max_attempts'];

        // Check circuit breaker.
        if ($this->isCircuitOpen($destination)) {
            // Put back as pending with backoff — do NOT increment attempts (no delivery was attempted).
            $nextAttempt = microtime(true) + min(300, 2 ** $attempts);
            $this->metadata->updateNotificationStatus($id, 'pending', 'Circuit breaker open', $nextAttempt, incrementAttempts: false);
            $this->metrics?->recordNotificationDelivery('circuit_open');
            $this->logger->warning('Notification delivery deferred because the circuit breaker is open.', $this->itemLogContext($item, [
                'event' => 'delivery_deferred_circuit_open',
                'next_attempt_at' => $nextAttempt,
                'attempts' => $attempts,
                'max_attempts' => $maxAttempts,
            ]));
            return;
        }

        try {
            // SSRF protection: resolve DNS and validate.
            $resolvedIp = $this->resolveAndValidateUrl($destination);
            if ($resolvedIp === null) {
                $this->recordFailure($destination);
                $this->metadata->updateNotificationStatus($id, 'dead_letter', 'SSRF: private/reserved IP');
                $this->metrics?->recordNotificationDelivery('blocked');
                $this->metrics?->recordNotificationDelivery('dead_letter');
                $this->logger->warning('Notification delivery blocked by destination safety policy.', $this->itemLogContext($item, [
                    'event' => 'delivery_blocked',
                    'reason' => 'private_or_reserved_destination',
                    'status' => 'dead_letter',
                ]));
                return;
            }

            // Pin URL to resolved IP.
            $pinnedUrl = $this->pinUrlToIp($destination, $resolvedIp);
            $success = $this->sendWebhook($pinnedUrl, $item['payload_json'], $destination);

            if ($success) {
                $this->metadata->updateNotificationStatus($id, 'sent');
                $this->recordSuccess($destination);
                $this->metrics?->recordNotificationDelivery('sent');
                $this->logger->info('Notification delivered.', $this->itemLogContext($item, [
                    'event' => 'delivery_sent',
                    'status' => 'sent',
                    'attempt' => $attempts + 1,
                    'max_attempts' => $maxAttempts,
                ]));
            } else {
                $this->recordFailure($destination);
                $nextAttempts = $attempts + 1;
                if ($nextAttempts >= $maxAttempts) {
                    $this->metadata->updateNotificationStatus($id, 'dead_letter', 'Max attempts exceeded');
                    $this->metrics?->recordNotificationDelivery('dead_letter');
                    $this->logger->warning('Notification moved to dead letter.', $this->itemLogContext($item, [
                        'event' => 'delivery_dead_letter',
                        'reason' => 'max_attempts_exceeded',
                        'status' => 'dead_letter',
                        'attempt' => $nextAttempts,
                        'max_attempts' => $maxAttempts,
                    ]));
                } else {
                    $backoff = min(300.0, 2 ** $nextAttempts);
                    $nextAttemptAt = microtime(true) + $backoff;
                    $this->metadata->updateNotificationStatus(
                        $id,
                        'pending',
                        'Delivery failed',
                        $nextAttemptAt,
                    );
                    $this->metrics?->recordNotificationDelivery('retry');
                    $this->logger->warning('Notification delivery failed and will be retried.', $this->itemLogContext($item, [
                        'event' => 'delivery_retry_scheduled',
                        'reason' => 'delivery_failed',
                        'status' => 'pending',
                        'attempt' => $nextAttempts,
                        'max_attempts' => $maxAttempts,
                        'backoff_seconds' => $backoff,
                        'next_attempt_at' => $nextAttemptAt,
                    ]));
                }
            }
        } catch (\Throwable $e) {
            $nextAttempts = $attempts + 1;
            if ($nextAttempts >= $maxAttempts) {
                $this->metadata->updateNotificationStatus($id, 'dead_letter', $e->getMessage());
                $this->metrics?->recordNotificationDelivery('dead_letter');
                $this->logger->error('Notification processing failed and moved to dead letter.', $this->itemLogContext($item, [
                    'event' => 'delivery_dead_letter',
                    'reason' => 'processing_exception',
                    'status' => 'dead_letter',
                    'attempt' => $nextAttempts,
                    'max_attempts' => $maxAttempts,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]));
            } else {
                $backoff = min(300.0, 2 ** $nextAttempts);
                $nextAttemptAt = microtime(true) + $backoff;
                $this->metadata->updateNotificationStatus($id, 'pending', $e->getMessage(), $nextAttemptAt);
                $this->metrics?->recordNotificationDelivery('retry');
                $this->logger->error('Notification processing failed and will be retried.', $this->itemLogContext($item, [
                    'event' => 'delivery_retry_scheduled',
                    'reason' => 'processing_exception',
                    'status' => 'pending',
                    'attempt' => $nextAttempts,
                    'max_attempts' => $maxAttempts,
                    'backoff_seconds' => $backoff,
                    'next_attempt_at' => $nextAttemptAt,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]));
            }
        }
    }

    private function sendWebhook(string $url, string $payload, ?string $originalUrl = null): bool
    {
        try {
            $request = new HttpRequest($url, 'POST');
            $request->setBody($payload);
            $request->setHeader('Content-Type', 'application/json');
            $request->setTransferTimeout(10);

            if ($originalUrl !== null) {
                $originalHost = parse_url($originalUrl, PHP_URL_HOST);
                if ($originalHost !== null && $originalHost !== false) {
                    $request->setHeader('Host', $originalHost);
                }
            }

            $response = $this->httpClient->request($request);

            return $response->getStatus() < 400;
        } catch (\Throwable $e) {
            $this->logger->warning('Webhook request failed.', $this->logContext([
                'event' => 'webhook_request_failed',
                'destination' => $this->sanitizeUrlForLog($originalUrl ?? $url),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]));
            return false;
        }
    }

    private function resolveAndValidateUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
            return $host;
        }

        try {
            $records = \Amp\Dns\resolve($host);
        } catch (\Throwable) {
            return null;
        }

        if (empty($records)) {
            return null;
        }

        foreach ($records as $record) {
            $ip = $record->getValue();
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }

        return $records[0]->getValue();
    }

    private function pinUrlToIp(string $url, string $ip): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return $url;
        }

        $replacement = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[$ip]" : $ip;
        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        $userInfo = '';
        if (isset($parts['user'])) {
            $userInfo = $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@';
        }

        return $scheme . '://' . $userInfo . $replacement . $port . $path . $query . $fragment;
    }

    private function sanitizeUrlForLog(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['user'])) {
            return $url;
        }

        $scheme = ($parts['scheme'] ?? 'https') . '://';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $scheme . $host . $port . $path . $query;
    }

    private function isCircuitOpen(string $destination): bool
    {
        if (!isset($this->circuitBreakers[$destination])) {
            return false;
        }

        $cb = $this->circuitBreakers[$destination];

        if ($cb['failures'] < self::CIRCUIT_BREAKER_THRESHOLD) {
            return false;
        }

        if (microtime(true) >= $cb['cooldownUntil']) {
            unset($this->circuitBreakers[$destination]);
            return false;
        }

        return true;
    }

    private function recordFailure(string $destination): void
    {
        if (!isset($this->circuitBreakers[$destination])) {
            $this->circuitBreakers[$destination] = ['failures' => 0, 'cooldownUntil' => 0.0];
        }

        $this->circuitBreakers[$destination]['failures']++;

        if ($this->circuitBreakers[$destination]['failures'] >= self::CIRCUIT_BREAKER_THRESHOLD) {
            $this->circuitBreakers[$destination]['cooldownUntil'] = microtime(true) + self::CIRCUIT_BREAKER_COOLDOWN;
            $this->metrics?->recordNotificationEvent('circuit_breaker', 'opened');
            $this->logger->warning('Notification destination circuit breaker opened.', $this->logContext([
                'event' => 'circuit_breaker_opened',
                'destination' => $this->sanitizeUrlForLog($destination),
                'failures' => $this->circuitBreakers[$destination]['failures'],
                'cooldown_until' => $this->circuitBreakers[$destination]['cooldownUntil'],
                'cooldown_seconds' => self::CIRCUIT_BREAKER_COOLDOWN,
            ]));
        }
    }

    private function recordSuccess(string $destination): void
    {
        unset($this->circuitBreakers[$destination]);
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function itemLogContext(array $item, array $context = []): array
    {
        return $this->logContext([
            'notification_id' => (int) $item['id'],
            'bucket' => (string) $item['bucket'],
            'key' => (string) $item['key_name'],
            'event_name' => (string) $item['event_name'],
            'destination' => $this->sanitizeUrlForLog((string) $item['destination_url']),
        ] + $context);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function logContext(array $context = []): array
    {
        return ['component' => 'notification'] + $context;
    }
}
