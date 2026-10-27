<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Models\User;
use App\Services\Rewards\GrantBatch;
use App\Services\Rewards\RewardWriter;
use App\Support\Rewards\RewardClock;
use App\Support\Rewards\RewardRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class ShopPurchase
{
    public function __construct(private RewardWriter $writer) {}

    /** Only trusted authenticated adapters supply actor. No client price, balance or reward payload. */
    public function buy(User $actor, array $input): array
    {
        abort_unless(app()->environment(['local', 'testing']), 403, 'Evaluation runtime only.');
        Validator::make(['purchase' => $input], [
            'purchase' => ['required', 'array:character_id,offer_id,quantity,request_key'],
            'purchase.character_id' => ['required', 'integer', 'min:1'],
            'purchase.offer_id' => ['required', 'integer', 'min:1'],
            'purchase.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'purchase.request_key' => ['required', 'uuid'],
        ])->validate();
        $actorId = (int) $actor->id;
        $characterId = (int) $input['character_id']; $offerId = (int) $input['offer_id']; $quantity = (int) $input['quantity'];
        $key = 'shop:'.strtolower($input['request_key']);
        $hash = hash('sha256', json_encode(['shop-v1', $actorId, $characterId, $offerId, $quantity], JSON_THROW_ON_ERROR));
        // Compatible with G8: account -> character -> offer -> catalog -> definition -> inventory.
        return DB::transaction(function () use ($actorId, $characterId, $offerId, $quantity, $key, $hash) {
            $account = DB::table('users')->where('id', $actorId)->lockForUpdate()->first();
            abort_unless($account && in_array((int) $account->account_type, [0, 1, 99], true), 403);
            $character = DB::table('characters')->where('id', $characterId)->where('user_id', $actorId)->lockForUpdate()->first();
            abort_unless($character, 404);
            $prior = DB::table('nl_claims')->where('user_id', $actorId)->where('idempotency_key', $key)->first();
            if ($prior) {
                if ($prior->source !== 'shop_purchase' || !hash_equals($prior->request_hash, $hash)) { RewardRules::reject('Kunci pembelian sudah dipakai untuk permintaan berbeda.'); }
                $purchase = DB::table('nl_shop_purchases')->where('claim_id', $prior->id)->first();
                if (!$purchase) { throw new \LogicException('Purchase receipt is incomplete; operator review required.'); }
                return ['id' => $prior->id, 'replayed' => true, 'granted' => json_decode($prior->granted, true, 32, JSON_THROW_ON_ERROR), 'charged' => ['tokens' => (int) $purchase->charged_tokens, 'gold' => (int) $purchase->charged_gold]];
            }
            $offer = DB::table('nl_shop_offers')->where('id', $offerId)->lockForUpdate()->first();
            if (!$offer || !$offer->active || $quantity > (int) $offer->max_quantity) { RewardRules::reject('Penawaran tidak aktif atau jumlah melebihi batas.'); }
            [$catalog, $definition] = ShopCatalog::locked((int) $offer->catalog_id);
            ShopCatalog::requireUnchanged($offer, $definition);
            if ($catalog->kind === 'skill' && $quantity !== 1) { RewardRules::reject('Skill hanya boleh dibeli satu.'); }
            $elements = ShopCatalog::eligibility($account, $character, $catalog, $definition);
            $costs = ['tokens' => (int) $offer->price_tokens, 'gold' => (int) $offer->price_gold];
            foreach ($costs as $type => &$cost) {
                if ($cost < 0 || $cost > intdiv(RewardRules::BALANCE_MAX, $quantity)) { RewardRules::reject('Total harga melampaui batas.'); }
                $cost *= $quantity;
                $owner = $type === 'tokens' ? $account : $character;
                if ((int) $owner->{$type} < $cost || (int) $owner->{$type} > RewardRules::BALANCE_MAX) { RewardRules::reject('Saldo tidak cukup atau tidak valid. Tidak ada perubahan disimpan.'); }
            } unset($cost);
            if ($costs['tokens'] === 0 && $costs['gold'] === 0) { RewardRules::reject('Harga penawaran tidak valid.'); }
            $time = RewardClock::now(); $debits = [];
            foreach ($costs as $type => $cost) {
                if ($cost === 0) { continue; }
                $owner = $type === 'tokens' ? $account : $character;
                $before = (int) $owner->{$type}; $after = $before - $cost;
                DB::table($type === 'tokens' ? 'users' : 'characters')->where('id', $owner->id)->update([$type => $after, 'updated_at' => $time]);
                $owner->{$type} = $after;
                $debits[] = ['catalog_id' => null, 'kind' => $type, 'content_key' => '', 'quantity' => $cost, 'direction' => 'debit', 'balance_before' => $before, 'balance_after' => $after, 'created_at' => $time];
            }
            if ($elements) { DB::table('characters')->where('id', $characterId)->update($elements + ['updated_at' => $time]); }
            $batch = (new GrantBatch($this->writer))->apply($account, $character, [['type' => $catalog->kind, 'key' => $catalog->content_key, 'quantity' => $quantity]], $time);
            $id = (string) Str::uuid();
            DB::table('nl_claims')->insert([
                'id' => $id, 'campaign_id' => null, 'user_id' => $actorId, 'character_id' => $characterId,
                'idempotency_key' => $key, 'request_hash' => $hash, 'choice_key' => 'shop', 'source' => 'shop_purchase',
                'actor_id' => $actorId, 'reason' => 'Pembelian: '.$offer->title, 'granted' => json_encode($batch['granted'], JSON_THROW_ON_ERROR), 'created_at' => $time,
            ]);
            $credits = array_map(fn ($entry) => ['direction' => 'credit'] + $entry, $batch['entries']);
            DB::table('nl_reward_ledger')->insert(array_map(fn ($entry) => ['claim_id' => $id] + $entry, array_merge($debits, $credits)));
            DB::table('nl_shop_purchases')->insert([
                'claim_id' => $id, 'offer_id' => $offerId, 'quantity' => $quantity, 'charged_tokens' => $costs['tokens'], 'charged_gold' => $costs['gold'],
                'terms' => json_encode(['slug' => $offer->slug, 'catalog_id' => $catalog->id, 'kind' => $catalog->kind, 'key' => $catalog->content_key, 'definition_hash' => $offer->definition_hash, 'unit_tokens' => (int) $offer->price_tokens, 'unit_gold' => (int) $offer->price_gold, 'elements' => $elements], JSON_THROW_ON_ERROR),
            ]);
            return ['id' => $id, 'replayed' => false, 'granted' => $batch['granted'], 'charged' => $costs];
        }, 3);
    }
}
