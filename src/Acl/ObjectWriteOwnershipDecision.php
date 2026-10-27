<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Acl;

/** The immutable destination identity and ACL selected before object bytes are written. */
final readonly class ObjectWriteOwnershipDecision
{
    /**
     * @param list<array{granteeType: string, granteeId: string, permission: string}> $aclGrants
     */
    public function __construct(
        public string $ownerId,
        public array $aclGrants,
        public string $quotaPrincipalId,
        public string $ownershipMode,
    ) {}

    public function equals(self $other): bool
    {
        return $this->ownerId === $other->ownerId
            && $this->aclGrants === $other->aclGrants
            && $this->quotaPrincipalId === $other->quotaPrincipalId
            && $this->ownershipMode === $other->ownershipMode;
    }
}
