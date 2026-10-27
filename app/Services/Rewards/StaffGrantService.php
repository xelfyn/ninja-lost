<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Models\User;
use App\Support\Rewards\RewardClock;
use App\Support\Rewards\RewardRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class StaffGrantService
{
    public function __construct(private RewardWriter $writer) {}

    /** Actor is supplied by a trusted authenticated adapter, never payload actor_id. */
    public function grant(User $actor, int $characterId, array $rewards, string $reason, string $requestKey): array
    {
        abort_unless(app()->environment(['local', 'testing']), 403, 'Evaluation runtime only.');
        $reason = trim($reason); $requestKey = strtolower($requestKey);
        Validator::make(compact('characterId', 'reason', 'requestKey'), [
            'characterId' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:8', 'max:160'],
            'requestKey' => ['required', 'uuid'],
        ])->validate();
        $lines = RewardRules::choices([['key' => 'staff', 'label' => 'Staff grant', 'rewards' => $rewards]])[0]['rewards'];
        $recipientId = (int) DB::table('characters')->where('id', $characterId)->value('user_id');
        abort_unless($recipientId, 404);
        $actorId = (int) $actor->id;
        // ':' is forbidden by portal validation, and reserves a separate adapter namespace.
        $storedKey = 'staff:'.$actorId.':'.$requestKey;
        $hash = hash('sha256', json_encode(['staff_grant', $actorId, $recipientId, $characterId, $lines, $reason], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($actorId, $recipientId, $characterId, $storedKey, $hash, $reason, $lines) {
            $accounts = [];
            $ids = array_unique([$actorId, $recipientId]); sort($ids, SORT_NUMERIC);
            foreach ($ids as $id) { $accounts[$id] = DB::table('users')->where('id', $id)->lockForUpdate()->first(); }
            abort_unless(($accounts[$actorId] ?? null) && (int) $accounts[$actorId]->account_type === 99, 403);
            $account = $accounts[$recipientId] ?? null;
            abort_unless($account && in_array((int) $account->account_type, [0, 1, 99], true), 403);
            $character = DB::table('characters')->where('id', $characterId)->where('user_id', $recipientId)->lockForUpdate()->first();
            abort_unless($character, 404);
            // Actor-wide key: changing recipient cannot turn a retry into a second grant.
            $prior = DB::table('nl_claims')->where('actor_id', $actorId)->where('idempotency_key', $storedKey)->first();
            if ($prior) {
                if ($prior->source !== 'staff_grant' || !hash_equals($prior->request_hash, $hash)) { RewardRules::reject('Kunci grant telah dipakai untuk payload berbeda.'); }
                return ['id' => $prior->id, 'replayed' => true, 'granted' => json_decode($prior->granted, true, 32, JSON_THROW_ON_ERROR)];
            }
            $time = RewardClock::now();
            $batch = (new GrantBatch($this->writer))->apply($account, $character, $lines, $time);
            $id = (string) Str::uuid();
            DB::table('nl_claims')->insert([
                'id' => $id, 'campaign_id' => null, 'user_id' => $recipientId, 'character_id' => $characterId,
                'idempotency_key' => $storedKey, 'request_hash' => $hash, 'choice_key' => 'staff',
                'source' => 'staff_grant', 'actor_id' => $actorId, 'reason' => $reason,
                'granted' => json_encode($batch['granted'], JSON_THROW_ON_ERROR), 'created_at' => $time,
            ]);
            DB::table('nl_reward_ledger')->insert(array_map(fn ($entry) => ['claim_id' => $id] + $entry, $batch['entries']));
            return ['id' => $id, 'replayed' => false, 'granted' => $batch['granted']];
        }, 3);
    }
}
