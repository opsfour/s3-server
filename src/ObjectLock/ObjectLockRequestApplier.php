<?php

declare(strict_types=1);

namespace OpsFour\S3Server\ObjectLock;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Http\Iso8601Timestamp;
use OpsFour\S3Server\Metadata\MetadataStore;

/**
 * Applies explicit Object Lock headers or a bucket default to a new version.
 */
final readonly class ObjectLockRequestApplier
{
    public function __construct(
        private MetadataStore $metadata,
    ) {}

    /**
     * @param array{mode?: ?string, retainUntilDate?: ?string, legalHold?: ?string} $headers
     * @return array{mode: ?string, retainUntilDate: ?string, legalHold: ?string}
     */
    public function validate(
        Request $request,
        string $bucket,
        array $headers = [],
    ): array {
        $mode = array_key_exists('mode', $headers)
            ? $headers['mode']
            : $request->getHeader('x-amz-object-lock-mode');
        $retainUntil = array_key_exists('retainUntilDate', $headers)
            ? $headers['retainUntilDate']
            : $request->getHeader('x-amz-object-lock-retain-until-date');
        $legalHold = array_key_exists('legalHold', $headers)
            ? $headers['legalHold']
            : $request->getHeader('x-amz-object-lock-legal-hold');
        $hasHeaders = $mode !== null || $retainUntil !== null || $legalHold !== null;
        $config = $this->metadata->getObjectLockConfig($bucket);

        if ($config === null) {
            if ($hasHeaders) {
                throw new InvalidArgumentException('Object Lock is not enabled for this bucket.');
            }

            return [
                'mode' => null,
                'retainUntilDate' => null,
                'legalHold' => null,
            ];
        }
        if ($this->metadata->getBucketVersioning($bucket) !== 'Enabled') {
            throw new InvalidArgumentException('Object Lock requires bucket versioning to be enabled.');
        }
        if (($mode === null) !== ($retainUntil === null)) {
            throw new InvalidArgumentException(
                'x-amz-object-lock-mode and x-amz-object-lock-retain-until-date must be provided together.',
            );
        }

        if ($mode !== null && $retainUntil !== null) {
            $mode = strtoupper($mode);
            if (! in_array($mode, ['GOVERNANCE', 'COMPLIANCE'], true)) {
                throw new InvalidArgumentException('Object Lock mode must be GOVERNANCE or COMPLIANCE.');
            }
            $retainUntilDate = Iso8601Timestamp::parse($retainUntil);
            if ($retainUntilDate === null) {
                throw new InvalidArgumentException('Invalid Object Lock retain-until date.');
            }
            if ($retainUntilDate <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
                throw new InvalidArgumentException('Object Lock retain-until date must be in the future.');
            }
            $retainUntil = $retainUntilDate
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(\DateTimeInterface::ATOM);
        }

        if ($legalHold !== null) {
            $legalHold = strtoupper($legalHold);
            if (! in_array($legalHold, ['ON', 'OFF'], true)) {
                throw new InvalidArgumentException('Object Lock legal hold must be ON or OFF.');
            }
        }

        return [
            'mode' => $mode,
            'retainUntilDate' => $retainUntil,
            'legalHold' => $legalHold,
        ];
    }

    /**
     * @param array{mode?: ?string, retainUntilDate?: ?string, legalHold?: ?string} $headers
     */
    public function apply(
        Request $request,
        string $bucket,
        string $key,
        ?string $versionId,
        array $headers = [],
    ): void {
        $validated = $this->validate($request, $bucket, $headers);
        $mode = $validated['mode'];
        $retainUntil = $validated['retainUntilDate'];
        $legalHold = $validated['legalHold'];
        $config = $this->metadata->getObjectLockConfig($bucket);

        if ($config === null) {
            return;
        }
        if ($versionId === null || $versionId === 'null') {
            throw new InvalidArgumentException('Object Lock requires bucket versioning to be enabled.');
        }

        if ($mode !== null && $retainUntil !== null) {
            $this->metadata->putObjectRetention(
                $bucket,
                $key,
                $mode,
                $retainUntil,
                $versionId,
            );
        } elseif (isset($config['rule']['defaultRetention'])) {
            $default = $config['rule']['defaultRetention'];
            $retainUntilDate = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if (isset($default['days'])) {
                $retainUntilDate = $retainUntilDate->modify('+' . $default['days'] . ' days');
            } elseif (isset($default['years'])) {
                $retainUntilDate = $retainUntilDate->modify('+' . $default['years'] . ' years');
            } else {
                throw new InvalidArgumentException('Object Lock default retention requires Days or Years.');
            }
            $this->metadata->putObjectRetention(
                $bucket,
                $key,
                $default['mode'],
                $retainUntilDate->format(\DateTimeInterface::ATOM),
                $versionId,
            );
        }

        if ($legalHold !== null) {
            $this->metadata->putObjectLegalHold($bucket, $key, $legalHold, $versionId);
        }
    }
}
