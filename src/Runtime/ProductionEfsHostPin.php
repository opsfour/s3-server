<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/**
 * Inert expected host identity. A future bootstrap must obtain these values
 * independently of the heartbeat; this value performs no host inspection.
 */
final readonly class ProductionEfsHostPin
{
    public function __construct(
        public string $containerId,
        public string $bootId,
        public string $hostMountId,
        public string $storageDev,
        public string $storageIno,
        public string $epoch,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $containerId) !== 1
            || preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $bootId) !== 1
            || ! self::positiveDecimal($hostMountId)
            || ! self::positiveDecimal($storageDev)
            || ! self::positiveDecimal($storageIno)
            || preg_match('/^[a-f0-9]{32}$/D', $epoch) !== 1) {
            throw new \InvalidArgumentException('Production EFS host pin is invalid.');
        }
    }

    private static function positiveDecimal(string $value): bool
    {
        return preg_match('/^[1-9][0-9]*$/D', $value) === 1;
    }
}
