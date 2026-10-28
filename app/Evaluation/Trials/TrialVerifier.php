<?php

declare(strict_types=1);
namespace App\Evaluation\Trials;

use App\Content\SkillBlueprint;
use App\Evaluation\TrainingRuntime;
use App\Services\Battles\BattleOutcomeVerifier;
use App\Services\Battles\BattleService;
use App\Services\Battles\VerifiedBattleOutcome;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** No container-wide binding: only TrialService supplies this verifier to the existing settlement service. */
final class TrialVerifier implements BattleOutcomeVerifier
{
    public function verify(string $battleId, array $serverTerms, array $untrustedProof): VerifiedBattleOutcome
    {
        abort_unless(TrialBoundary::enabled(), 404);
        $link = DB::table('nl_trial_runs')->where('battle_id', $battleId)->lockForUpdate()->first();
        abort_unless($link && $untrustedProof === ['run_id' => $link->run_id], 422, 'Bukti tidak terikat pada tantangan.');
        $rule = DB::table('nl_trial_rules')->where('id', $link->trial_id)->first();
        $battle = DB::table('nl_battles')->where('id', $battleId)->first();
        $run = DB::table('nl_training_runs')->where('id', $link->run_id)->lockForUpdate()->first();
        abort_unless($rule && $battle && $run && (int) $battle->rule_id === (int) $rule->battle_rule_id
            && (int) $run->user_id === (int) $link->user_id && (int) $run->user_id === $serverTerms['account_id']
            && (int) $run->character_id === $serverTerms['character_id']
            && hash_equals($link->terms_hash, hash('sha256', SkillBlueprint::json($serverTerms)))
            && hash_equals($link->bundle_hash, $run->bundle_hash) && hash_equals($run->bundle_hash, hash('sha256', $run->bundle))
            && hash_equals($link->runtime_hash, $run->runtime_hash), 409, 'Binding atau snapshot berubah.');
        abort_unless($run->status === 'ended', 409, 'Pertarungan belum selesai.');
        $runtime = app(TrainingRuntime::class);
        abort_unless(hash_equals($link->runtime_hash, $runtime->fingerprint()), 409, 'Versi engine berubah.');
        $rows = DB::table('nl_training_actions')->where('run_id', $run->id)->orderBy('sequence')->get();
        abort_unless($rows->count() > 0 && $rows->count() <= 128 && $rows->count() === (int) $run->revision, 409, 'Riwayat aksi tidak lengkap.');
        $commands = [];
        foreach ($rows as $i => $row) {
            abort_unless((int) $row->sequence === $i + 1, 409);
            $commands[] = json_decode($row->command, true, 8, JSON_THROW_ON_ERROR);
        }
        $bundle = json_decode($run->bundle, true, 48, JSON_THROW_ON_ERROR);
        $replayed = $runtime->replay($run->id, Crypt::decryptString($run->seed), $bundle, $commands)['state'];
        abort_unless($replayed['phase'] === 'ended' && SkillBlueprint::json($replayed) === SkillBlueprint::json(json_decode($run->state, true, 48, JSON_THROW_ON_ERROR)), 409, 'Hasil tidak cocok dengan replay server.');
        $won = $replayed['outcome']['won'];
        // A rewardable victory must be a real knockout, never a transport timeout or supplied flag.
        abort_unless(!$won || ($replayed['player']['hp'] > 0 && $replayed['mirror']['hp'] <= 0), 409, 'Kemenangan tanpa knockout tidak memenuhi syarat.');
        return new VerifiedBattleOutcome($battleId, $won, BattleService::proofHash($untrustedProof), 'mirror-replay-v1',
            hash('sha256', SkillBlueprint::json(['battle_id' => $battleId, 'run_id' => $run->id, 'terms' => $link->terms_hash,
                'bundle' => $link->bundle_hash, 'runtime' => $link->runtime_hash, 'commands' => $commands, 'result' => $replayed])));
    }
}
