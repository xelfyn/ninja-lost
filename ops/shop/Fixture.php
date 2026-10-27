<?php

namespace NinjaLost\QA;

use App\Models\User;
use App\Services\Shop\ShopOffers;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../rewards/Fixture.php';

final class ShopFixture
{
    public static function offer(User $admin, int $catalogId, int $tokens = 10, int $gold = 5, int $max = 10, bool $active = true): int
    {
        Fixture::guard();
        $id = app(ShopOffers::class)->create($admin, ['slug' => 'nlqa-shop-'.bin2hex(random_bytes(8)), 'title' => 'QA Synthetic Offer',
            'catalog_id' => $catalogId, 'price_tokens' => $tokens, 'price_gold' => $gold, 'max_quantity' => $max]);
        if ($active) { app(ShopOffers::class)->setActive($admin, $id, true); }
        return $id;
    }

    public static function cleanup(array $userIds, array $offers, array $catalogs): void
    {
        Fixture::guard();
        $claims = DB::table('nl_claims')->whereIn('user_id', $userIds)->pluck('id');
        DB::table('nl_shop_purchases')->whereIn('claim_id', $claims)->delete();
        DB::table('nl_reward_ledger')->whereIn('claim_id', $claims)->delete();
        DB::table('nl_claims')->whereIn('id', $claims)->delete();
        DB::table('nl_shop_offers')->whereIn('id', $offers)->delete();
        Fixture::cleanup($userIds, [], $catalogs);
    }
}
