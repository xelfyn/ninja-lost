<?php

declare(strict_types=1);

namespace App\Services\Battles;

use App\Models\User;
use App\Services\Rewards\GrantBatch;
use App\Services\Rewards\RewardWriter;
use App\Services\Shop\ShopCatalog;
use App\Support\Economy\StateRevision;
use App\Support\Rewards\RewardClock;
use App\Support\Rewards\RewardRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Internal adapter contract. Not reachable by legacy AMF or a new public HTTP endpoint. */
final class BattleService
{
    private BattleOutcomeVerifier $verifier;
    public function __construct(private RewardWriter $writer, ?BattleOutcomeVerifier $verifier = null)
    {
        $this->verifier = $verifier ?? new UnavailableBattleVerifier();
    }

    public function start(User $actor, array $input): array
    {
        Validator::make(['battle' => $input], [
            'battle' => ['required', 'array:character_id,rule_id,request_key'],
            'battle.character_id' => ['required', 'integer', 'min:1'], 'battle.rule_id' => ['required', 'integer', 'min:1'],
            'battle.request_key' => ['required', 'uuid'],
        ])->validate();
        $key = strtolower($input['request_key']); $ruleId = (int) $input['rule_id'];
        $hash = hash('sha256', json_encode([(int) $actor->id, (int) $input['character_id'], $ruleId], JSON_THROW_ON_ERROR));
        return $this->locked($actor, (int) $input['character_id'], function ($account, $character) use ($key, $ruleId, $hash) {
            $prior = DB::table('nl_battles')->where('user_id', $account->id)->where('start_key', $key)->first();
            if ($prior) {
                if (!hash_equals($prior->request_hash, $hash)) { RewardRules::reject('Identitas start telah dipakai untuk battle berbeda.'); }
                return $this->ticket($prior, true);
            }
            $now = RewardClock::now();
            if (DB::table('nl_battles')->where('character_id', $character->id)->where('status', 'started')->where('expires_at', '>', $now)->exists()) {
                abort(409, 'Karakter masih memiliki battle aktif.');
            }
            $rule = DB::table('nl_battle_rules')->where('id', $ruleId)->lockForUpdate()->first();
            if (!$rule || !$rule->active) { RewardRules::reject('Rule battle tidak aktif.'); }
            $mission = BattleRules::mission($rule->mission_id);
            if (!hash_equals($rule->definition_hash, ShopCatalog::fingerprint($mission)) || $character->level < $mission->req_lvl) { RewardRules::reject('Misi berubah atau level tidak memenuhi syarat.'); }
            $this->supported($character);
            $xp = (int) $mission->xp;
            // Bonus is captured at start, not taken from a finish payload or a later config edit.
            if ($character->double_xp_expire_at && $now->lt(RewardClock::parse($character->double_xp_expire_at))) {
                $rate = (int) $character->xp_bonus_rate;
                if ($rate < 0 || $rate > 1000) { RewardRules::reject('Bonus XP di luar batas evaluasi.'); }
                $xp = intdiv($xp * (100 + $rate), 100);
            }
            $progression = BattleProgression::snapshot($character, $xp);
            $lines = (int) $mission->gold > 0 ? [['type' => 'gold', 'key' => '', 'quantity' => (int) $mission->gold]] : [];
            $id = (string) Str::uuid();
            $terms = ['mission_id' => $mission->mission_id, 'definition_hash' => $rule->definition_hash, 'rewards' => $lines, 'progression' => $progression,
                'account_id' => (int) $account->id, 'character_id' => (int) $character->id, 'start_revision' => StateRevision::current((int) $account->id),
                'equipment' => array_intersect_key((array) $character, array_flip(['equipment_weapon', 'equipment_clothing', 'equipment_back', 'equipment_accessory', 'equipment_skills']))];
            DB::table('nl_battles')->insert(['id' => $id, 'user_id' => $account->id, 'character_id' => $character->id, 'rule_id' => $ruleId,
                'start_key' => $key, 'request_hash' => $hash, 'terms' => json_encode($terms, JSON_THROW_ON_ERROR), 'status' => 'started',
                'started_at' => $now, 'not_before' => $now->addSeconds((int) $rule->minimum_seconds), 'expires_at' => $now->addSeconds((int) $rule->ttl_seconds)]);
            return $this->ticket(DB::table('nl_battles')->where('id', $id)->first(), false);
        });
    }

    public function finish(User $actor, array $input): array
    {
        Validator::make(['finish' => $input], ['finish' => ['required', 'array:battle_id,proof'], 'finish.battle_id' => ['required', 'uuid'], 'finish.proof' => ['present', 'array', 'max:128']])->validate();
        $proofHash = self::proofHash($input['proof']);
        $battleId = strtolower($input['battle_id']);
        $characterId = DB::table('nl_battles')->where('id', $battleId)->where('user_id', $actor->id)->value('character_id');
        abort_unless($characterId, 404);
        return $this->locked($actor, (int) $characterId, function ($account, $character) use ($input, $battleId, $proofHash) {
            $battle = DB::table('nl_battles')->where('id', $battleId)->where('user_id', $account->id)->where('character_id', $character->id)->lockForUpdate()->first();
            abort_unless($battle, 404);
            if ($battle->result !== null) {
                if (!hash_equals($battle->proof_hash, $proofHash)) { RewardRules::reject('Battle sudah diselesaikan dengan bukti berbeda.'); }
                return ['replayed' => true] + json_decode($battle->result, true, 32, JSON_THROW_ON_ERROR);
            }
            $now = RewardClock::now();
            abort_unless($battle->status === 'started' && $now->lt(RewardClock::parse($battle->expires_at)), 410, 'Battle kedaluwarsa atau telah dibatalkan.');
            abort_unless($now->gte(RewardClock::parse($battle->not_before)), 409, 'Battle belum dapat diselesaikan.');
            $this->supported($character);
            $terms = json_decode($battle->terms, true, 32, JSON_THROW_ON_ERROR);
            $verified = $this->verifier->verify($battleId, $terms, $input['proof']);
            if ($verified->battleId !== $battleId || !hash_equals($proofHash, $verified->proofHash)
                || !preg_match('/\A[a-zA-Z0-9_.-]{1,80}\z/D', $verified->verifier) || !preg_match('/\A[a-f0-9]{64}\z/D', $verified->evidenceHash)) {
                throw new \LogicException('Verifier returned an unbound or malformed verdict.');
            }
            $claimId = null; $granted = [];
            if ($verified->won) {
                $progression = BattleProgression::apply($character, $terms['progression']);
                $batch = $terms['rewards'] ? (new GrantBatch($this->writer))->apply($account, $character, $terms['rewards'], $now) : ['granted' => [], 'entries' => []];
                if (!$terms['rewards']) { StateRevision::bump((int) $account->id); }
                $granted = array_merge($batch['granted'], [$progression]); $claimId = (string) Str::uuid();
                DB::table('nl_claims')->insert(['id' => $claimId, 'campaign_id' => null, 'user_id' => $account->id, 'character_id' => $character->id,
                    'idempotency_key' => 'battle:'.$battleId, 'request_hash' => $proofHash, 'choice_key' => 'battle', 'source' => 'battle_settlement', 'actor_id' => $account->id,
                    'reason' => 'Battle: '.$terms['mission_id'], 'granted' => json_encode($granted, JSON_THROW_ON_ERROR), 'created_at' => $now]);
                $entries = $batch['entries'];
                $entries[] = ['catalog_id' => null, 'kind' => 'xp', 'content_key' => '', 'quantity' => $progression['quantity'], 'balance_before' => $progression['before'], 'balance_after' => $progression['after'], 'created_at' => $now];
                DB::table('nl_reward_ledger')->insert(array_map(fn ($entry) => ['claim_id' => $claimId, 'direction' => 'credit'] + $entry, $entries));
            }
            $result = ['battle_id' => $battleId, 'outcome' => $verified->won ? 'won' : 'lost', 'claim_id' => $claimId, 'granted' => $granted];
            DB::table('nl_battles')->where('id', $battleId)->update(['status' => $result['outcome'], 'proof_hash' => $proofHash, 'verifier' => $verified->verifier,
                'evidence_hash' => $verified->evidenceHash, 'claim_id' => $claimId, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'settled_at' => $now]);
            return ['replayed' => false] + $result;
        });
    }

    public static function proofHash(array $proof): string
    {
        try { $json = json_encode($proof, JSON_THROW_ON_ERROR, 16); }
        catch (\JsonException $e) { RewardRules::reject('Bukti battle bukan JSON terbatas yang valid.'); }
        if (strlen($json) > 65536) { RewardRules::reject('Bukti battle terlalu besar.'); }
        // Exact serialized input identity; callers retain the same object ordering on retry.
        return hash('sha256', $json);
    }
    private function locked(User $actor, int $characterId, callable $operation): array
    {
        abort_unless(app()->environment(['local', 'testing']), 403, 'Evaluation only.');
        return DB::transaction(function () use ($actor, $characterId, $operation) {
            $account = DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            abort_unless($account && in_array((int) $account->account_type, [0, 1, 99], true), 403);
            $character = DB::table('characters')->where('id', $characterId)->where('user_id', $account->id)->lockForUpdate()->first(); abort_unless($character, 404);
            return $operation($account, $character);
        }, 3);
    }
    private function supported(object $character): void
    {
        if ($character->equipment_pet || DB::table('character_recruits')->where('character_id', $character->id)->exists()) {
            RewardRules::reject('Pet/recruit settlement belum terintegrasi; battle ini tidak didukung.');
        }
    }
    private function ticket(object $battle, bool $replayed): array
    {
        return ['battle_id' => $battle->id, 'status' => $battle->status, 'expires_at' => $battle->expires_at, 'replayed' => $replayed];
    }
}
