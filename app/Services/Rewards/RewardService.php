<?php

namespace App\Services\Rewards;

use App\Models\User;
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
        if (!$id) {
            RewardRules::reject('Kode tidak valid atau tidak tersedia.');
        }
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

        // Global lock order: account -> character -> campaign -> sorted catalog entries.
        // Different characters of one account therefore cannot race the account-level claim.
        return DB::transaction(function () use ($actor, $characterId, $campaignId, $choice, $requestKey, $kind, $codeHash, $fingerprint) {
            $account = DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            abort_unless($account && in_array((int) $account->account_type, [0, 1, 99], true), 403);
            $character = DB::table('characters')->where('id', $characterId)->where('user_id', $account->id)->lockForUpdate()->first();
            abort_unless($character, 404);
            $prior = DB::table('nl_claims')->where('user_id', $account->id)->where('idempotency_key', $requestKey)->first();
            if ($prior) {
                if (!hash_equals($prior->request_hash, $fingerprint)) {
                    RewardRules::reject('Kunci permintaan sudah dipakai untuk pilihan yang berbeda.');
                }
                return ['id' => $prior->id, 'replayed' => true, 'granted' => json_decode($prior->granted, true, 32, JSON_THROW_ON_ERROR)];
            }
            $campaign = DB::table('nl_campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            if (!$campaign || $campaign->kind !== $kind || ($kind === 'redeem' && !hash_equals($campaign->code_hash ?? '', $codeHash ?? ''))) {
                RewardRules::reject('Campaign atau kode tidak tersedia.');
            }
            if (DB::table('nl_claims')->where('campaign_id', $campaignId)->where('user_id', $account->id)->exists()) {
                RewardRules::reject('Akun sudah mengeklaim campaign ini.');
            }
            if ($campaign->status !== 'active' || now()->lt($campaign->starts_at) || now()->gte($campaign->ends_at)) {
                RewardRules::reject('Campaign belum aktif atau sudah berakhir.');
            }
            if ($campaign->claimed_count >= $campaign->max_claims) {
                RewardRules::reject('Kuota campaign sudah habis.');
            }
            $choices = RewardRules::choices(json_decode($campaign->choices, true, 32, JSON_THROW_ON_ERROR));
            $selection = collect($choices)->firstWhere('key', $choice);
            if (!$selection) {
                RewardRules::reject('Pilihan starter/reward tidak sah.');
            }
            $catalogs = [];
            $content = array_filter($selection['rewards'], fn ($line) => isset(RewardRules::CONTENT[$line['type']]));
            usort($content, fn ($a, $b) => strcmp(strtolower($a['type'].':'.$a['key']), strtolower($b['type'].':'.$b['key'])));
            foreach ($content as $line) {
                $row = DB::table('nl_catalog')->where('kind', $line['type'])->where('content_key', $line['key'])->lockForUpdate()->first();
                if (!$row || !$row->enabled || !in_array($row->rights_basis, ['self_authored', 'permission_documented'], true)) {
                    RewardRules::reject('Konten belum diizinkan dan diaktifkan pada registri.');
                }
                if ($row->issued > 4294967295 - $line['quantity'] || ($row->supply_limit !== null && $row->issued + $line['quantity'] > $row->supply_limit)) {
                    RewardRules::reject('Supply limited sudah habis.');
                }
                $catalogs[$line['type'].':'.$line['key']] = $row;
            }
            $id = (string) Str::uuid();
            $granted = [];
            $entries = [];
            foreach ($selection['rewards'] as $line) {
                $catalog = $catalogs[$line['type'].':'.$line['key']] ?? null;
                $result = $this->writer->apply($account, $character, $line, $catalog);
                $granted[] = $result;
                if ($catalog) {
                    DB::table('nl_catalog')->where('id', $catalog->id)->increment('issued', $line['quantity'], ['updated_at' => now()]);
                }
                $entries[] = ['claim_id' => $id, 'catalog_id' => $catalog?->id, 'kind' => $result['type'], 'content_key' => $result['key'], 'quantity' => $result['quantity'], 'balance_before' => $result['before'], 'balance_after' => $result['after'], 'created_at' => now()];
            }
            DB::table('nl_claims')->insert([
                'id' => $id, 'campaign_id' => $campaignId, 'user_id' => $account->id, 'character_id' => $characterId,
                'idempotency_key' => $requestKey, 'request_hash' => $fingerprint, 'choice_key' => $choice,
                'source' => 'player_portal', 'actor_id' => $account->id, 'reason' => $campaign->title,
                'granted' => json_encode($granted, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            DB::table('nl_reward_ledger')->insert($entries);
            DB::table('nl_campaigns')->where('id', $campaignId)->increment('claimed_count', 1, ['updated_at' => now()]);
            return ['id' => $id, 'replayed' => false, 'granted' => $granted];
        }, 3);
    }
}
