<?php

declare(strict_types=1);

namespace App\Services\Battles;

interface BattleOutcomeVerifier
{
    /**
     * Verify against authoritative combat evidence, not a client win flag/hash.
     * Must be deterministic, local and side-effect-free: called under DB locks and
     * may run again after a deadlock. Never perform network I/O or issue rewards here.
     * No production verifier is provided by this settlement integration.
     */
    public function verify(string $battleId, array $serverTerms, array $untrustedProof): VerifiedBattleOutcome;
}
