<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlRequestParser;

final class PutBucketNotificationHandler implements RequestHandler
{
    private const int MAX_CONFIGURATIONS = 256;

    /** Portable limit of the MySQL notification destination column. */
    private const int MAX_DESTINATION_BYTES = 512;

    /** @var list<string> */
    private const array EMITTED_WEBHOOK_EVENTS = [
        's3:ObjectCreated:*',
        's3:ObjectCreated:Put',
        's3:ObjectCreated:Post',
        's3:ObjectCreated:Copy',
        's3:ObjectCreated:CompleteMultipartUpload',
        's3:ObjectRemoved:*',
        's3:ObjectRemoved:Delete',
        's3:ObjectRemoved:DeleteMarkerCreated',
        's3:ObjectRestore:Completed',
    ];

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly bool $requireHttps = true,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $ownerId = $request->getAttribute('ownerId');

        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        $body = \OpsFour\S3Server\Http\RequestBody::buffer($request);
        $configs = XmlRequestParser::parseNotificationConfiguration($body);
        if (count($configs) > self::MAX_CONFIGURATIONS) {
            throw new InvalidArgumentException('Notification configuration exceeds 256 entries per bucket.');
        }

        /** @var list<list<string>> $comparisonEvents */
        $comparisonEvents = [];
        $seenIds = [];
        foreach ($configs as &$config) {
            $id = $config['id'];
            if ($id === '' || strlen($id) > 255 || isset($seenIds["\0{$id}"])) {
                throw new InvalidArgumentException('Notification configuration IDs must be unique and between 1 and 255 bytes.');
            }
            $seenIds["\0{$id}"] = true;
            foreach ($config['events'] as $event) {
                if (! in_array($event, self::EMITTED_WEBHOOK_EVENTS, true)) {
                    throw new NotImplementedException("Notification event {$event} is not supported by the webhook dispatcher.");
                }
            }
            $comparisonEvents[] = array_values(array_unique($config['events']));

            $destination = $config['destinationArn'];
            if (strlen($destination) > self::MAX_DESTINATION_BYTES) {
                throw new InvalidArgumentException('Notification destination must be at most 512 bytes.');
            }
            if (str_starts_with(strtolower($destination), 'arn:')) {
                throw new NotImplementedException('SNS, SQS, and Lambda notification destinations are not supported.');
            }

            if (preg_match('/[\x00-\x20\x7F]/', $destination) === 1) {
                throw new InvalidArgumentException('Notification destination must not contain whitespace or control characters.');
            }

            $parts = parse_url($destination);
            if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === ''
                || isset($parts['user']) || isset($parts['pass'])) {
                throw new InvalidArgumentException('Notification destination must be an absolute HTTP(S) URL without user information.');
            }
            if (isset($parts['port']) && $parts['port'] < 1) {
                throw new InvalidArgumentException('Notification destination port must be between 1 and 65535.');
            }
            $scheme = strtolower($parts['scheme']);
            if (! in_array($scheme, ['http', 'https'], true)) {
                throw new NotImplementedException('Only HTTP(S) notification destinations are supported.');
            }
            if ($this->requireHttps && $scheme !== 'https') {
                throw new InvalidArgumentException('Notification destination must use HTTPS.');
            }
            $config['destinationArn'] = $scheme . substr($destination, strlen($parts['scheme']));
        }
        unset($config);

        foreach ($configs as $index => $config) {
            for ($previous = 0; $previous < $index; $previous++) {
                $other = $configs[$previous];
                if (! self::eventsOverlap($comparisonEvents[$index], $comparisonEvents[$previous])) {
                    continue;
                }

                [$prefix, $suffix] = self::filterBounds($config['filterRules']);
                [$otherPrefix, $otherSuffix] = self::filterBounds($other['filterRules']);
                $prefixesOverlap = str_starts_with($prefix, $otherPrefix) || str_starts_with($otherPrefix, $prefix);
                $suffixesOverlap = str_ends_with($suffix, $otherSuffix) || str_ends_with($otherSuffix, $suffix);
                if ($prefixesOverlap && $suffixesOverlap) {
                    throw new InvalidArgumentException('Notification filters overlap for the same event type.');
                }
            }
        }

        \OpsFour\S3Server\Metadata\BucketGenerationGuard::mutate(
            $this->metadata,
            $bucketInfo,
            (string) $ownerId,
            fn() => $this->metadata->putBucketNotification($bucket, $configs),
            $request,
        );

        return new Response(status: 200);
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private static function eventsOverlap(array $left, array $right): bool
    {
        foreach ($left as $first) {
            foreach ($right as $second) {
                if ($first === $second
                    || (str_ends_with($first, ':*') && str_starts_with($second, substr($first, 0, -1)))
                    || (str_ends_with($second, ':*') && str_starts_with($first, substr($second, 0, -1)))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{name: string, value: string}>|null  $rules
     * @return array{string, string}
     */
    private static function filterBounds(?array $rules): array
    {
        $prefix = '';
        $suffix = '';
        foreach ($rules ?? [] as $rule) {
            if ($rule['name'] === 'prefix') {
                $prefix = urldecode($rule['value']);
            } elseif ($rule['name'] === 'suffix') {
                $suffix = urldecode($rule['value']);
            }
        }

        return [$prefix, $suffix];
    }
}
