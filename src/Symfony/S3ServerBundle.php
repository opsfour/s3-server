<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Symfony;

use OpsFour\S3Server\Symfony\DependencyInjection\S3ServerExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class S3ServerBundle extends Bundle
{
    public function getContainerExtension(): ExtensionInterface
    {
        if ($this->extension instanceof ExtensionInterface) {
            return $this->extension;
        }

        $this->extension = new S3ServerExtension();

        return $this->extension;
    }
}
