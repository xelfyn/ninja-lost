<?php

declare(strict_types=1);

namespace App\Services\Battles;

final readonly class VerifiedBattleOutcome
{
    /** Constructed only by a trusted verifier; never hydrate this type from request JSON. */
    public function __construct(
        public string $battleId,
        public bool $won,
        public string $proofHash,
        public string $verifier,
        public string $evidenceHash,
    ) {}
}
