<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

interface ClusterAdmissionStore
{
    public function clusterDatabaseSchema(): string;

    public function clusterDatabaseIdentity(): string;

    public function initializeOrCompareClusterAdmission(ClusterCapabilityFingerprint $fingerprint): ClusterAdmissionResult;

    public function compareClusterAdmission(ClusterCapabilityFingerprint $fingerprint): ClusterAdmissionResult;
}
