<?php

namespace App\Services\Rewards;

use App\Models\User;
use App\Support\Rewards\RewardClock;
use App\Support\Rewards\RewardRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class RewardService
{
    public function __construct(private RewardWriter $writer) {}

    public function starter(User $actor, int $characterId, int $campaignId, string $choice, string $requestKey): array
    {
        return $this->claim($actor, $characterId, $campaignId, $choice, $requestKey, 'starter', null);
    }

    public function redeem(User $actor, int $characterId, string $code, string $choice, string $requestKey): array
    {
        $hash = RewardRules::codeHash($code);
        $id = DB::table('nl_campaigns')->where('code_hash', $hash)->value('id');
        if (!$id) { RewardRules::reject('Kode tidak valid atau tidak tersedia.'); }
        return $this->claim($actor, $characterId, (int) $id, $choice, $requestKey, 'redeem', $hash);
    }

    private function claim(User $actor, int $characterId, int $campaignId, string $choice, string $requestKey, string $kind, ?string $codeHash): array
    {
        abort_unless(app()->environment(['local', 'testing']), 403, 'Evaluation runtime only.');
        Validator::make(compact('characterId', 'campaignId', 'choice', 'requestKey'), [
            'characterId' => ['required', 'integer', 'min:1'],
            'campaignId' => ['required', 'integer', 'min:1'],
            'choice' => ['required', 'regex:/\A[a-z0-9_-]{1,40}\z/D'],
            'requestKey' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{16,64}\z/D'],
        ])->validate();
        $fingerprint = hash('sha256', json_encode([$characterId, $campaignId, $choice, $kind, $codeHash], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($actor, $characterId, $campaignId, $choice, $requestKey, $kind, $codeHash, $fingerprint) {
            // Account -> character -> campaign -> sorted catalog. Shared issuer preserves this order.
            $account = DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            abort_unless($account && in_array((int) $account->account_type, [0, 1, 99], true), 403);
            $character = DB::table('characters')->where('id', $characterId)->where('user_id', $account->id)->lockForUpdate()->first();
            abort_unless($character, 404);
            $prior = DB::table('nl_claims')->where('user_id', $account->id)->where('idempotency_key', $requestKey)->first();
            if ($prior) {
                if (!hash_equals($prior->request_hash, $fingerprint)) { RewardRules::reject('Kunci permintaan sudah dipakai untuk pilihan yang berbeda.'); }
                return ['id' => $prior->id, 'replayed' => true, 'granted' => json_decode($prior->granted, true, 32, JSON_THROW_ON_ERROR)];
            }
            $campaign = DB::table('nl_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            if (!$campaign || $campaign->kind !== $kind || ($kind === 'redeem' && !hash_equals($campaign->code_hash ?? '', $codeHash ?? ''))) {
                RewardRules::reject('Campaign atau kode tidak tersedia.');
            }
            if (DB::table('nl_claims')->where('campaign_id', $campaignId)->where('user_id', $account->id)->exists()) { RewardRules::reject('Akun sudah mengeklaim campaign ini.'); }
            $time = RewardClock::now();
            if ($campaign->status !== 'active' || $time->lt(RewardClock::parse($campaign->starts_at)) || $time->gte(RewardClock::parse($campaign->ends_at))) { RewardRules::reject('Campaign belum aktif atau sudah berakhir.'); }
            if ($campaign->claimed_count >= $campaign->max_claims) { RewardRules::reject('Kuota campaign sudah habis.'); }
            $choices = RewardRules::choices(json_decode($campaign->choices, true, 32, JSON_THROW_ON_ERROR));
            $selection = collect($choices)->firstWhere('key', $choice);
            if (!$selection) { RewardRules::reject('Pilihan starter/reward tidak sah.'); }
            $batch = (new GrantBatch($this->writer))->apply($account, $character, $selection['rewards'], $time);
            $id = (string) Str::uuid();
            DB::table('nl_claims')->insert([
                'id' => $id, 'campaign_id' => $campaignId, 'user_id' => $account->id, 'character_id' => $characterId,
                'idempotency_key' => $requestKey, 'request_hash' => $fingerprint, 'choice_key' => $choice,
                'source' => 'player_portal', 'actor_id' => $account->id, 'reason' => $campaign->title,
                'granted' => json_encode($batch['granted'], JSON_THROW_ON_ERROR), 'created_at' => $time,
            ]);
            DB::table('nl_reward_ledger')->insert(array_map(fn ($entry) => ['claim_id' => $id] + $entry, $batch['entries']));
            DB::table('nl_campaigns')->where('id', $campaignId)->increment('claimed_count', 1, ['updated_at' => $time]);
            return ['id' => $id, 'replayed' => false, 'granted' => $batch['granted']];
        }, 3);
    }
}
