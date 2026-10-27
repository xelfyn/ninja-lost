<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Models\User;
use App\Support\Rewards\RewardRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ShopOffers
{
    /** Offers have immutable price/quantity/catalog terms. A price change requires a new inactive offer. */
    public function create(User $actor, array $input): int
    {
        Validator::make(['offer' => $input], [
            'offer' => ['required', 'array:slug,title,catalog_id,price_tokens,price_gold,max_quantity'],
            'offer.slug' => ['required', 'string', 'regex:/\A[a-z0-9-]{1,80}\z/D'],
            'offer.title' => ['required', 'string', 'max:120'],
            'offer.catalog_id' => ['required', 'integer', 'min:1'],
            'offer.price_tokens' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'offer.price_gold' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'offer.max_quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ])->validate();
        if ((int) $input['price_tokens'] === 0 && (int) $input['price_gold'] === 0) { RewardRules::reject('Penawaran berbayar memerlukan harga positif; gunakan campaign untuk hadiah gratis.'); }
        return $this->staff($actor, function () use ($actor, $input) {
            [$catalog, $definition] = ShopCatalog::locked((int) $input['catalog_id']);
            if ($catalog->kind === 'skill' && (int) $input['max_quantity'] !== 1) { RewardRules::reject('Pembelian skill dibatasi satu per transaksi.'); }
            if (DB::table('nl_shop_offers')->where('slug', $input['slug'])->exists()) { RewardRules::reject('Slug penawaran sudah digunakan.'); }
            $id = DB::table('nl_shop_offers')->insertGetId([
                'slug' => $input['slug'], 'title' => trim($input['title']), 'catalog_id' => $catalog->id,
                'definition_hash' => ShopCatalog::fingerprint($definition), 'price_tokens' => (int) $input['price_tokens'],
                'price_gold' => (int) $input['price_gold'], 'max_quantity' => (int) $input['max_quantity'],
                'active' => false, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit((int) $actor->id, $id, 'shop.offer.create', ['catalog_id' => $catalog->id, 'price_tokens' => (int) $input['price_tokens'], 'price_gold' => (int) $input['price_gold'], 'max_quantity' => (int) $input['max_quantity']]);
            return $id;
        });
    }

    public function setActive(User $actor, int $offerId, bool $active): void
    {
        $this->staff($actor, function () use ($actor, $offerId, $active) {
            $offer = DB::table('nl_shop_offers')->where('id', $offerId)->lockForUpdate()->first();
            abort_unless($offer, 404);
            if ($active) {
                [, $definition] = ShopCatalog::locked((int) $offer->catalog_id);
                ShopCatalog::requireUnchanged($offer, $definition);
            }
            if ((bool) $offer->active === $active) { return; }
            DB::table('nl_shop_offers')->where('id', $offerId)->update(['active' => $active, 'updated_at' => now()]);
            $this->audit((int) $actor->id, $offerId, 'shop.offer.status', ['active' => $active]);
        });
    }

    private function staff(User $actor, callable $operation): mixed
    {
        abort_unless(app()->environment(['local', 'testing']), 403, 'Evaluation runtime only.');
        return DB::transaction(function () use ($actor, $operation) {
            $account = DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            abort_unless($account && (int) $account->account_type === 99, 403);
            return $operation();
        }, 3);
    }

    private function audit(int $actor, int $offer, string $action, array $details): void
    {
        DB::table('nl_operations_audit')->insert(['actor_id' => $actor, 'action' => $action, 'subject' => 'shop:'.$offer, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
