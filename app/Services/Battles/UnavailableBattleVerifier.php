<?php

declare(strict_types=1);

namespace App\Services\Battles;

final class UnavailableBattleVerifier implements BattleOutcomeVerifier
{
    public function verify(string $battleId, array $serverTerms, array $untrustedProof): VerifiedBattleOutcome
    {
        abort(503, 'Verifier combat server belum terpasang. Hasil client tidak dapat menerbitkan reward.');
    }
}
