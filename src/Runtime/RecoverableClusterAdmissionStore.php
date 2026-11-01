<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** Admission store whose complete in-flight operation can be retired safely. */
interface RecoverableClusterAdmissionStore extends ClusterAdmissionStore
{
    /** Terminate current I/O and allow the next comparison to reconnect. */
    public function retireConnection(): void;

    /** Permanently release the lifecycle-owned admission connection. */
    public function close(): void;
}
