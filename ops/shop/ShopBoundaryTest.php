<?php

use App\Models\User;
use App\Services\Shop\ShopPurchase;
use App\Services\Rewards\StaffGrantService;
use App\Services\Rewards\RewardService;
use App\Support\Economy\StateRevision;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use NinjaLost\QA\Fixture;
use NinjaLost\QA\ShopFixture;
require_once __DIR__.'/Fixture.php';

final class ShopBoundaryTest extends TestCase
{
    use DatabaseTransactions;
    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); return $app;
    }
    protected function setUp(): void { parent::setUp(); Fixture::guard(); }
    private function rejection(callable $action): void
    {
        $caught = null;
        try { $action(); } catch (ValidationException $e) { $caught = $e; }
        $this->assertInstanceOf(ValidationException::class, $caught);
    }
    public function test_missing_database_account_is_not_cast_to_normal_player_role(): void
    {
        $user = Fixture::actor(); $this->actingAs($user);
        $this->assertTrue(App\Player\Pages\PlayerShop::canAccess());
        DB::table('users')->where('id', $user->id)->delete();
        $this->assertFalse(App\Player\Pages\PlayerShop::canAccess());
    }
    public function test_revision_overflow_rolls_back_debit_inventory_supply_and_receipts(): void
    {
        $admin = Fixture::actor(99); $user = Fixture::actor(); $character = Fixture::character($user);
        $user->update(['tokens' => 100]); $character->update(['gold' => 100]);
        $content = Fixture::content($admin, 'item'); $offer = ShopFixture::offer($admin, $content['id']);
        DB::table('nl_account_revisions')->insert(['user_id' => $user->id, 'revision' => 4294967295]);
        $this->rejection(fn () => app(ShopPurchase::class)->buy($user, ['character_id' => $character->id, 'offer_id' => $offer, 'quantity' => 1, 'request_key' => (string) Str::uuid()]));
        $this->assertSame(100, (int) $user->fresh()->tokens); $this->assertSame(100, (int) $character->fresh()->gold);
        $this->assertSame(4294967295, StateRevision::current($user->id));
        $this->assertSame(0, (int) DB::table('nl_catalog')->where('id', $content['id'])->value('issued'));
        $this->assertSame(0, DB::table('character_items')->where('character_id', $character->id)->count());
        $this->assertSame(0, DB::table('nl_claims')->count()); $this->assertSame(0, DB::table('nl_reward_ledger')->count());
    }
    public function test_failed_purchase_can_retry_same_key_after_legitimate_funding(): void
    {
        $admin = Fixture::actor(99); $user = Fixture::actor(); $character = Fixture::character($user);
        $content = Fixture::content($admin, 'item'); $offer = ShopFixture::offer($admin, $content['id']);
        $input = ['character_id' => $character->id, 'offer_id' => $offer, 'quantity' => 1, 'request_key' => (string) Str::uuid()];
        $this->rejection(fn () => app(ShopPurchase::class)->buy($user, $input));
        $this->assertSame(0, DB::table('nl_claims')->count());
        app(StaffGrantService::class)->grant($admin, $character->id, [['type' => 'tokens', 'key' => '', 'quantity' => 10], ['type' => 'gold', 'key' => '', 'quantity' => 5]], 'QA legitimate retry funding', (string) Str::uuid());
        $first = app(ShopPurchase::class)->buy($user, $input); $again = app(ShopPurchase::class)->buy($user, $input);
        $this->assertFalse($first['replayed']); $this->assertTrue($again['replayed']); $this->assertSame($first['id'], $again['id']);
        $this->assertSame(0, (int) $user->fresh()->tokens); $this->assertSame(0, (int) $character->fresh()->gold);
        $this->assertSame(2, StateRevision::current($user->id)); $this->assertSame(1, DB::table('nl_shop_purchases')->count());
    }
    public function test_same_uuid_for_different_accounts_is_independent(): void
    {
        $admin = Fixture::actor(99); $content = Fixture::content($admin, 'item'); $offer = ShopFixture::offer($admin, $content['id']);
        $key = (string) Str::uuid(); $receipts = [];
        for ($i = 0; $i < 2; $i++) {
            $u = Fixture::actor(); $c = Fixture::character($u); $u->update(['tokens' => 100]); $c->update(['gold' => 100]);
            $result = app(ShopPurchase::class)->buy($u, ['character_id' => $c->id, 'offer_id' => $offer, 'quantity' => 1, 'request_key' => $key]);
            $this->assertFalse($result['replayed']); $receipts[] = $result['id']; $this->assertSame(90, (int) $u->fresh()->tokens);
        }
        $this->assertCount(2, array_unique($receipts)); $this->assertSame(2, DB::table('nl_shop_purchases')->count());
    }
    public function test_portal_cannot_inject_the_shop_idempotency_namespace(): void
    {
        $admin = Fixture::actor(99); $u = Fixture::actor(); $c = Fixture::character($u); $campaign = Fixture::campaign($admin);
        $this->rejection(fn () => app(RewardService::class)->starter($u, $c->id, $campaign, 'default', 'shop:'.Str::uuid()));
        $this->assertSame(0, (int) $u->fresh()->tokens); $this->assertSame(0, DB::table('nl_claims')->count());
    }
}
