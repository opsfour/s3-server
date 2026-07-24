<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Notification;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as HttpRequest;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\StaticSocketConnector;
use League\Uri\BaseUri;
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

    private const int MAX_REDIRECTS = 3;

    private readonly ?HttpClient $httpClient;

    private ?DeferredCancellation $deferredCancellation = null;

    /** @var Future<void>|null */
    private ?Future $loopFuture = null;

    /** @var array<string, array{failures: int, cooldownUntil: float}> Per-destination circuit breakers. */
    private array $circuitBreakers = [];

    private const int CIRCUIT_BREAKER_THRESHOLD = 5;

    private const float CIRCUIT_BREAKER_COOLDOWN = 60.0;

    private const int MAX_CIRCUIT_BREAKERS = 10_000;

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly float $pollInterval = 2.0,
        ?HttpClient $httpClient = null,
        private readonly ?MetricsCollector $metrics = null,
        private readonly bool $requireHttps = true,
    ) {
        $this->httpClient = $httpClient;
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;
        $this->deferredCancellation = new DeferredCancellation();
        $this->loopFuture = \Amp\async(
            $this->loop(...),
            $this->deferredCancellation->getCancellation(),
        );
    }

    public function stop(float $timeoutSeconds = 10): void
    {
        $this->running = false;
        $this->deferredCancellation?->cancel();

        try {
            $this->loopFuture?->await(new \Amp\TimeoutCancellation(max(0.001, $timeoutSeconds)));
        } catch (\Amp\CancelledException) {
            $this->logger->warning('Notification processor did not stop within {timeout}s; waiting before dependency shutdown.', $this->logContext([
                'event' => 'processor_stop_timeout',
                'timeout' => $timeoutSeconds,
            ]));

            try {
                $this->loopFuture->await();
            } catch (\Throwable $e) {
                $this->logger->error('Notification processor did not stop cleanly.', $this->logContext([
                    'event' => 'processor_stop_failed',
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Notification processor did not stop cleanly.', $this->logContext([
                'event' => 'processor_stop_failed',
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]));
        } finally {
            $this->loopFuture = null;
            $this->deferredCancellation = null;
        }
    }

    private function loop(Cancellation $cancellation): void
    {
        $idleCount = 0;

        try {
            while ($this->running) {
                $cancellation->throwIfRequested();
                try {
                    $items = $this->metadata->dequeueNotifications(50);
                } catch (\Throwable $e) {
                    $this->metrics?->recordNotificationEvent('dequeue', 'failed');
                    $this->logger->error('Notification dequeue failed.', $this->logContext([
                        'event' => 'dequeue_failed',
                        'exception' => $e::class,
                        'error' => $e->getMessage(),
                    ]));
                    \Amp\delay($this->pollInterval * 5, cancellation: $cancellation);
                    continue;
                }

                if ($items === []) {
                    // Exponential backoff when idle: 2s → 4s → 8s → max 30s.
                    $idleCount = min($idleCount + 1, 10);
                    $delay = min(30.0, $this->pollInterval * (2 ** min($idleCount - 1, 4)));
                    \Amp\delay($delay, cancellation: $cancellation);
                    continue;
                }

                $idleCount = 0;

                $futures = [];
                foreach ($items as $item) {
                    $futures[] = \Amp\async(fn() => $this->process($item, $cancellation));
                }

                try {
                    \Amp\Future\await($futures, $cancellation);
                } catch (\Amp\CancelledException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    $this->logger->error('Notification batch processing failed.', $this->logContext([
                        'event' => 'batch_failed',
                        'exception' => $e::class,
                        'error' => $e->getMessage(),
                    ]));
                }
            }
        } catch (\Amp\CancelledException) {
            // Normal shutdown.
        }
    }

    /** @param array{id: int, bucket: string, key_name: string, event_name: string, destination_url: string, payload_json: string, attempts: int, max_attempts: int} $item */
    private function process(array $item, ?Cancellation $cancellation = null): void
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
            $success = $this->sendWebhook($destination, $item['payload_json'], cancellation: $cancellation);

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
        } catch (UnsafeNotificationDestination $e) {
            $this->recordFailure($destination);
            $this->metadata->updateNotificationStatus($id, 'dead_letter', $e->getMessage());
            $this->metrics?->recordNotificationDelivery('blocked');
            $this->metrics?->recordNotificationDelivery('dead_letter');
            $this->logger->warning('Notification delivery blocked by destination safety policy.', $this->itemLogContext($item, [
                'event' => 'delivery_blocked',
                'reason' => 'unsafe_destination',
                'status' => 'dead_letter',
                'error' => $e->getMessage(),
            ]));
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

    private function sendWebhook(
        string $url,
        string $payload,
        ?Cancellation $cancellation = null,
    ): bool {
        $currentUrl = $url;
        $method = 'POST';

        try {
            for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
                $resolvedIp = $this->resolveAndValidateUrl($currentUrl, $cancellation);
                $request = new HttpRequest($currentUrl, $method);
                if ($method === 'POST') {
                    $request->setBody($payload);
                    $request->setHeader('Content-Type', 'application/json');
                }
                $request->setTransferTimeout(10);
                $request->setTcpConnectTimeout(5);
                $request->setTlsHandshakeTimeout(5);

                $client = $this->httpClient ?? $this->createPinnedHttpClient($currentUrl, $resolvedIp);
                $response = $client->request($request, $cancellation);
                $status = $response->getStatus();

                if ($status >= 200 && $status < 300) {
                    $response->getBody()->close();

                    return true;
                }

                if (! in_array($status, [301, 302, 303, 307, 308], true)) {
                    $response->getBody()->close();

                    return false;
                }

                $locations = $response->getHeaderArray('location');
                $response->getBody()->close();
                if (count($locations) !== 1 || $redirects === self::MAX_REDIRECTS) {
                    return false;
                }

                $currentUrl = BaseUri::from($currentUrl)->resolve($locations[0])->getUriString();
                if (in_array($status, [301, 302, 303], true)) {
                    $method = 'GET';
                }
            }
        } catch (UnsafeNotificationDestination $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning('Webhook request failed.', $this->logContext([
                'event' => 'webhook_request_failed',
                'destination' => $this->sanitizeUrlForLog($currentUrl),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]));
        }

        return false;
    }

    private function resolveAndValidateUrl(string $url, ?Cancellation $cancellation = null): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeNotificationDestination('Notification destination must be an absolute HTTP(S) URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeNotificationDestination('Notification destination URL scheme must be HTTP or HTTPS.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeNotificationDestination('Notification destination must not contain user information.');
        }

        if ($this->requireHttps && $scheme !== 'https') {
            throw new UnsafeNotificationDestination('Notification destination must use HTTPS.');
        }

        $host = $parts['host'];
        if ($host === '') {
            throw new UnsafeNotificationDestination('Notification destination host is empty.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new UnsafeNotificationDestination('Notification destination resolves to a private or reserved IP address.');
            }

            return $host;
        }

        try {
            $records = \Amp\Dns\resolve($host, cancellation: $cancellation);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Notification destination DNS resolution failed.', 0, $e);
        }

        if (empty($records)) {
            throw new \RuntimeException('Notification destination DNS resolution returned no addresses.');
        }

        foreach ($records as $record) {
            $ip = $record->getValue();
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new UnsafeNotificationDestination('Notification destination resolves to a private or reserved IP address.');
            }
        }

        return $records[0]->getValue();
    }

    private function createPinnedHttpClient(string $url, string $ip): HttpClient
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'])) {
            throw new UnsafeNotificationDestination('Notification destination URL is invalid.');
        }

        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $address = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? "tcp://[{$ip}]:{$port}"
            : "tcp://{$ip}:{$port}";
        $connector = new StaticSocketConnector($address, new DnsSocketConnector());
        $pool = new UnlimitedConnectionPool(new DefaultConnectionFactory($connector));

        return (new HttpClientBuilder())
            ->usingPool($pool)
            ->followRedirects(0)
            ->retry(0)
            ->build();
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
            if (count($this->circuitBreakers) >= self::MAX_CIRCUIT_BREAKERS) {
                array_shift($this->circuitBreakers);
                $this->metrics?->recordNotificationEvent('circuit_breaker', 'evicted');
            }
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
