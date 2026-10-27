<?php

use App\Evaluation\GatewayAccess;
use App\Models\User;
use App\Services\Rewards\RewardService;
use App\Services\Rewards\StaffGrantService;
use App\Services\Shop\ShopPurchase;
use App\Support\Rewards\RewardClock;
use App\Support\Economy\StateRevision;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use NinjaLost\QA\Fixture;
use NinjaLost\QA\ShopFixture;
require_once __DIR__.'/../shop/Fixture.php';

final class PlayerGatewayTest extends TestCase
{
    use DatabaseTransactions;
    private User $admin;
    private User $player;
    private App\Models\Character $character;
    private array $content;
    private int $offer;
    private string $password;
    private const PREFIX = '/evaluation/v1';

    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }
    protected function setUp(): void
    {
        parent::setUp(); Fixture::guard(); Cache::flush();
        $this->password = bin2hex(random_bytes(20));
        $this->admin = Fixture::actor(99);
        $this->player = Fixture::actor(0, null, $this->password);
        $this->character = Fixture::character($this->player);
        $this->player->update(['tokens' => 100]); $this->character->update(['gold' => 100]);
        $this->content = Fixture::content($this->admin, 'item', 200);
        $this->offer = ShopFixture::offer($this->admin, $this->content['id']);
    }
    private function playerSession(): void
    {
        $this->actingAs($this->player, 'web')->withSession(['nl_gateway_auth' => GatewayAccess::stamp($this->player)]);
    }
    private function purchase(array $changes = []): array
    {
        return array_replace(['character_id' => $this->character->id, 'offer_id' => $this->offer, 'quantity' => 2, 'request_key' => (string) Str::uuid()], $changes);
    }
    private function unchanged(): void
    {
        $this->assertSame(100, (int) $this->player->fresh()->tokens);
        $this->assertSame(100, (int) $this->character->fresh()->gold);
        $this->assertSame(0, StateRevision::current($this->player->id));
        $this->assertSame(0, DB::table('nl_claims')->where('user_id', $this->player->id)->count());
    }
    private function rawPost(string $path, string $body, string $type = 'application/json')
    {
        return $this->call('POST', self::PREFIX.$path, [], [], [], ['CONTENT_TYPE' => $type, 'HTTP_ACCEPT' => 'application/json'], $body);
    }
    public function test_bootstrap_contains_protocol_and_csrf_but_no_gameplay_claim(): void
    {
        $r = $this->getJson(self::PREFIX.'/bootstrap')->assertOk()->assertJsonPath('gameplay_available', false)->assertJsonPath('protocol', 'evaluation-v1');
        $this->assertNotEmpty($r->json('csrf_token'));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertArrayNotHasKey('user_id', $r->json()); $this->unchanged();
    }
    public function test_gateway_disabled_flag_and_production_deny_even_loaded_routes(): void
    {
        config(['evaluation_gateway.enabled' => false]); $this->getJson(self::PREFIX.'/bootstrap')->assertNotFound();
        config(['evaluation_gateway.enabled' => true]); app()->instance('env', 'production');
        try { $this->getJson(self::PREFIX.'/bootstrap')->assertNotFound(); }
        finally { app()->instance('env', 'testing'); }
        $this->unchanged();
    }
    public function test_remote_ip_and_foreign_host_cannot_open_gateway(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->getJson(self::PREFIX.'/bootstrap')->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->getJson('http://foreign.example/evaluation/v1/bootstrap')->assertNotFound();
    }
    public function test_guest_and_unstamped_portal_identity_cannot_use_adapter(): void
    {
        $this->getJson(self::PREFIX.'/characters')->assertUnauthorized();
        $this->postJson(self::PREFIX.'/purchases', $this->purchase())->assertUnauthorized();
        $this->actingAs($this->player)->getJson(self::PREFIX.'/characters')->assertUnauthorized(); $this->unchanged();
    }
    public function test_actual_login_rotates_session_and_returns_no_password_or_role(): void
    {
        $this->getJson(self::PREFIX.'/bootstrap')->assertOk(); $before = session()->getId();
        $r = $this->postJson(self::PREFIX.'/login', ['username' => $this->player->username, 'password' => $this->password])->assertOk()->assertJsonPath('user_id', $this->player->id);
        $this->assertNotSame($before, session()->getId());
        $this->assertAuthenticatedAs($this->player, 'web');
        $this->assertArrayNotHasKey('password', $r->json()); $this->assertArrayNotHasKey('account_type', $r->json());
        $this->getJson(self::PREFIX.'/characters')->assertOk();
    }
    public function test_invalid_password_unknown_account_and_admin_login_are_generic_denials(): void
    {
        foreach ([[$this->player->username, 'wrong'], ['missing-'.Str::uuid(), 'wrong'], [$this->admin->username, 'wrong']] as [$username, $password]) {
            $this->postJson(self::PREFIX.'/login', compact('username', 'password'))->assertUnauthorized()->assertJsonPath('message', 'Kredensial tidak valid.');
        }
        $this->admin->update(['password' => $this->password]);
        $this->postJson(self::PREFIX.'/login', ['username' => $this->admin->username, 'password' => $this->password])->assertUnauthorized();
        $this->unchanged();
    }
    public function test_database_role_is_rechecked_for_every_authenticated_mutation(): void
    {
        $this->playerSession(); DB::table('users')->where('id', $this->player->id)->update(['account_type' => 2]);
        $this->postJson(self::PREFIX.'/purchases', $this->purchase())->assertUnauthorized(); $this->unchanged();
    }
    public function test_password_reset_invalidates_old_gateway_stamp(): void
    {
        $this->playerSession(); $this->player->update(['password' => bin2hex(random_bytes(24))]);
        $this->getJson(self::PREFIX.'/characters')->assertUnauthorized(); $this->unchanged();
    }
    public function test_expired_or_wrong_identity_stamp_is_not_authenticated(): void
    {
        $this->playerSession();
        $this->withSession(['nl_gateway_auth' => array_replace(GatewayAccess::stamp($this->player), ['expires_at' => RewardClock::now()->getTimestamp()])])->getJson(self::PREFIX.'/characters')->assertUnauthorized();
        $this->withSession(['nl_gateway_auth' => array_replace(GatewayAccess::stamp($this->player), ['user_id' => $this->admin->id])])->getJson(self::PREFIX.'/characters')->assertUnauthorized();
    }
    public function test_foreign_character_state_inventory_and_save_are_denied(): void
    {
        $foreign = Fixture::character(Fixture::actor()); $this->playerSession();
        foreach (['state', 'inventory'] as $suffix) { $this->getJson(self::PREFIX.'/characters/'.$foreign->id.'/'.$suffix)->assertNotFound(); }
        $this->postJson(self::PREFIX.'/characters/'.$foreign->id.'/equipment', ['revision' => 0, 'equipment' => ['weapon' => '']])->assertNotFound();
        $this->getJson(self::PREFIX.'/characters')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->character->id); $this->unchanged();
    }
    public function test_purchase_retry_uses_same_issuer_and_one_adapter_audit(): void
    {
        $this->playerSession(); $input = $this->purchase();
        $first = $this->postJson(self::PREFIX.'/purchases', $input)->assertOk()->assertJsonPath('replayed', false)->json();
        $this->postJson(self::PREFIX.'/purchases', $input)->assertOk()->assertJsonPath('id', $first['id'])->assertJsonPath('replayed', true);
        $this->getJson(self::PREFIX.'/characters/'.$this->character->id.'/state')->assertOk()->assertJsonPath('tokens', 80)->assertJsonPath('gold', 90)->assertJsonPath('revision', 1);
        $this->getJson(self::PREFIX.'/characters/'.$this->character->id.'/inventory')->assertOk()->assertJsonPath('items.data.0.item_id', $this->content['key'])->assertJsonPath('items.data.0.quantity', 2);
        $this->assertSame(1, DB::table('nl_operations_audit')->where('action', 'gateway.purchase')->where('actor_id', $this->player->id)->count());
        $this->assertSame(1, DB::table('nl_shop_purchases')->count());
    }
    public function test_retry_receipt_created_outside_gateway_is_not_charged_again(): void
    {
        $input = $this->purchase(); $first = app(ShopPurchase::class)->buy($this->player, $input); $this->playerSession();
        $this->postJson(self::PREFIX.'/purchases', $input)->assertOk()->assertJsonPath('id', $first['id'])->assertJsonPath('replayed', true);
        $this->assertSame(80, (int) $this->player->fresh()->tokens);
        $this->assertSame(0, DB::table('nl_operations_audit')->where('action', 'gateway.purchase')->count());
    }
    public function test_changed_intent_under_same_purchase_key_is_rejected(): void
    {
        $this->playerSession(); $input = $this->purchase(); $this->postJson(self::PREFIX.'/purchases', $input)->assertOk();
        $this->postJson(self::PREFIX.'/purchases', array_replace($input, ['quantity' => 3]))->assertUnprocessable();
        $this->assertSame(80, (int) $this->player->fresh()->tokens); $this->assertSame(1, StateRevision::current($this->player->id));
    }
    public function test_unknown_price_actor_reward_and_balance_fields_do_not_write(): void
    {
        $this->playerSession();
        foreach (['actor_id' => $this->admin->id, 'price_tokens' => 1, 'tokens' => 99999, 'reward' => ['tokens' => 9]] as $key => $value) {
            $this->postJson(self::PREFIX.'/purchases', $this->purchase([$key => $value]))->assertUnprocessable(); $this->unchanged();
        }
    }
    public function test_starter_staff_and_purchase_share_current_balances_and_revisions(): void
    {
        $campaign = Fixture::campaign($this->admin, [['type' => 'tokens', 'key' => '', 'quantity' => 20]]);
        $this->playerSession(); $input = ['character_id' => $this->character->id, 'campaign_id' => $campaign, 'choice' => 'default', 'request_key' => (string) Str::uuid()];
        $this->getJson(self::PREFIX.'/campaigns')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson(self::PREFIX.'/starter-claims', $input)->assertOk()->assertJsonPath('replayed', false);
        $this->postJson(self::PREFIX.'/starter-claims', $input)->assertOk()->assertJsonPath('replayed', true);
        $this->getJson(self::PREFIX.'/campaigns')->assertJsonCount(0, 'data');
        app(StaffGrantService::class)->grant($this->admin, $this->character->id, [['type' => 'tokens', 'key' => '', 'quantity' => 7]], 'QA gateway cross adapter', (string) Str::uuid());
        $this->postJson(self::PREFIX.'/purchases', $this->purchase())->assertOk();
        $this->getJson(self::PREFIX.'/characters/'.$this->character->id.'/state')->assertJsonPath('tokens', 107)->assertJsonPath('gold', 90)->assertJsonPath('revision', 3);
    }
    public function test_redeem_uses_shared_retries_and_code_is_not_exposed_by_discovery(): void
    {
        $code = 'QA-'.strtoupper(bin2hex(random_bytes(8)));
        Fixture::campaign($this->admin, [['type' => 'tokens', 'key' => '', 'quantity' => 7]], ['kind' => 'redeem', 'code' => $code]);
        $this->playerSession(); $listing = $this->getJson(self::PREFIX.'/campaigns')->assertOk()->assertJsonCount(0, 'data');
        $this->assertStringNotContainsString($code, $listing->getContent());
        $input = ['character_id' => $this->character->id, 'code' => $code, 'choice' => 'default', 'request_key' => (string) Str::uuid()];
        $this->postJson(self::PREFIX.'/redeem-claims', $input)->assertOk()->assertJsonPath('replayed', false);
        $this->postJson(self::PREFIX.'/redeem-claims', $input)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(107, (int) $this->player->fresh()->tokens);
        $this->assertStringNotContainsString($code, DB::table('nl_operations_audit')->get()->toJson());
    }
    public function test_equipment_uses_current_revision_and_cannot_mass_assign_economy(): void
    {
        $this->playerSession(); $path = self::PREFIX.'/characters/'.$this->character->id.'/equipment';
        $this->postJson($path, ['revision' => 0, 'equipment' => ['weapon' => '']])->assertOk()->assertJsonPath('revision', 1);
        $this->postJson(self::PREFIX.'/purchases', $this->purchase())->assertOk();
        $this->postJson($path, ['revision' => 1, 'equipment' => ['weapon' => '']])->assertConflict();
        $this->postJson($path, ['revision' => 2, 'equipment' => ['weapon' => ''], 'gold' => 99999])->assertUnprocessable();
        $this->assertSame(80, (int) $this->player->fresh()->tokens); $this->assertSame(2, StateRevision::current($this->player->id));
    }
    public function test_discovery_hides_inactive_offers_and_unknown_rights(): void
    {
        $this->playerSession(); $this->getJson(self::PREFIX.'/offers')->assertOk()->assertJsonCount(1, 'data');
        DB::table('nl_shop_offers')->where('id', $this->offer)->update(['active' => false]);
        $this->getJson(self::PREFIX.'/offers')->assertJsonCount(0, 'data');
        $this->postJson(self::PREFIX.'/purchases', $this->purchase())->assertUnprocessable();
        DB::table('nl_shop_offers')->where('id', $this->offer)->update(['active' => true]);
        DB::table('nl_catalog')->where('id', $this->content['id'])->update(['rights_basis' => 'unknown']);
        $this->getJson(self::PREFIX.'/offers')->assertJsonCount(0, 'data'); $this->unchanged();
    }
    public function test_listing_bound_is_explicit_instead_of_silently_claiming_full_inventory(): void
    {
        for ($i = 0; $i < 100; $i++) { Fixture::character($this->player); }
        $this->playerSession();
        $this->getJson(self::PREFIX.'/characters')->assertOk()->assertJsonCount(100, 'data')->assertJsonPath('limit', 100)->assertJsonPath('truncated', true);
    }
    public function test_json_shape_size_depth_and_query_are_bounded(): void
    {
        $this->playerSession();
        foreach (['[]', 'null', '{broken', str_repeat('{"x":', 15).'0'.str_repeat('}', 15)] as $body) { $this->rawPost('/purchases', $body)->assertUnprocessable(); }
        $this->rawPost('/purchases', '{"x":"'.str_repeat('a', 17000).'"}')->assertStatus(413);
        $this->rawPost('/purchases', '{}', 'text/plain')->assertStatus(415);
        $this->getJson(self::PREFIX.'/characters?token=not-supported')->assertUnprocessable(); $this->unchanged();
    }
    public function test_login_limits_and_mutation_limits_are_enforced(): void
    {
        $key = 'nl-gateway-login:user:'.hash('sha256', mb_strtolower($this->player->username));
        for ($i = 0; $i < 5; $i++) { RateLimiter::hit($key, 60); }
        $this->postJson(self::PREFIX.'/login', ['username' => $this->player->username, 'password' => $this->password])->assertStatus(429)->assertHeader('Retry-After');
        $this->playerSession();
        for ($i = 0; $i < 60; $i++) { RateLimiter::hit('nl-gateway-write:'.$this->player->id, 60); }
        $this->postJson(self::PREFIX.'/purchases', $this->purchase())->assertStatus(429); $this->unchanged();
    }
    public function test_logout_invalidates_gateway_and_rotates_existing_session(): void
    {
        $this->playerSession(); $before = session()->getId();
        $this->rawPost('/logout', '{}')->assertOk()->assertJsonPath('logged_out', true);
        $this->assertGuest('web'); $this->assertNotSame($before, session()->getId());
        $this->getJson(self::PREFIX.'/characters')->assertUnauthorized();
    }
    public function test_no_battle_amf_staff_or_generic_execution_adapter_exists(): void
    {
        $this->playerSession();
        foreach (['/amf', '/gateway.php', '/evaluation/v1/battles', '/evaluation/v1/staff-grants', '/evaluation/v1/execute'] as $path) { $this->postJson($path, [])->assertNotFound(); }
        $this->unchanged();
    }
    public function test_failure_writing_adapter_audit_rolls_back_domain_receipt_and_debit(): void
    {
        $this->playerSession(); $armed = true;
        DB::listen(function ($query) use (&$armed) {
            if ($armed && str_starts_with($query->sql, 'insert into `nl_operations_audit`') && in_array('gateway.purchase', $query->bindings, true)) { throw new RuntimeException('qa-gateway-late-audit'); }
        });
        try { $this->postJson(self::PREFIX.'/purchases', $this->purchase())->assertStatus(500)->assertJsonPath('message', 'Kesalahan internal; jangan mengganti request_key saat mencoba ulang.'); }
        finally { $armed = false; }
        $this->unchanged(); $this->assertSame(0, DB::table('nl_shop_purchases')->count());
        $this->assertSame(0, DB::table('character_items')->where('character_id', $this->character->id)->count());
    }
}
