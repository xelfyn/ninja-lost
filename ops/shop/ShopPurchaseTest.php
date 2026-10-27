<?php

use App\Models\User;
use App\Services\Rewards\ClientStateService;
use App\Services\Rewards\RewardService;
use App\Services\Rewards\RewardWriter;
use App\Services\Rewards\StaffGrantService;
use App\Services\Shop\ShopOffers;
use App\Services\Shop\ShopPurchase;
use App\Support\Economy\StateRevision;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use NinjaLost\QA\Fixture;
use NinjaLost\QA\ShopFixture;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
require_once __DIR__.'/Fixture.php';

final class ShopPurchaseTest extends TestCase
{
    use DatabaseTransactions;
    private User $admin;
    private User $player;
    private App\Models\Character $character;
    private array $content;
    private int $offer;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }
    protected function setUp(): void
    {
        parent::setUp(); Fixture::guard();
        $this->admin = Fixture::actor(99); $this->player = Fixture::actor(); $this->character = Fixture::character($this->player);
        $this->player->update(['tokens' => 100]); $this->character->update(['gold' => 100]);
        $this->content = Fixture::content($this->admin, 'item', 100);
        $this->offer = ShopFixture::offer($this->admin, $this->content['id']);
        Filament::setCurrentPanel(Filament::getPanel('player'));
    }
    private function input(array $changes = []): array
    {
        return array_replace(['character_id' => $this->character->id, 'offer_id' => $this->offer, 'quantity' => 1, 'request_key' => (string) Str::uuid()], $changes);
    }
    private function buy(array $input): array { return app(ShopPurchase::class)->buy($this->player, $input); }
    private function reject(callable $action): void
    {
        $caught = null;
        try { $action(); } catch (ValidationException $error) { $caught = $error; }
        $this->assertInstanceOf(ValidationException::class, $caught);
    }
    private function deny(int $status, callable $action): void
    {
        $caught = null;
        try { $action(); } catch (HttpExceptionInterface $error) { $caught = $error; }
        $this->assertInstanceOf(HttpExceptionInterface::class, $caught);
        $this->assertSame($status, $caught->getStatusCode());
    }
    private function unchanged(): void
    {
        $this->assertSame(100, (int) $this->player->fresh()->tokens);
        $this->assertSame(100, (int) $this->character->fresh()->gold);
        $this->assertSame(0, StateRevision::current($this->player->id));
        $this->assertSame(0, DB::table('nl_claims')->where('user_id', $this->player->id)->count());
        $this->assertSame(0, (int) DB::table('nl_catalog')->where('id', $this->content['id'])->value('issued'));
    }
    public function test_dual_currency_purchase_updates_existing_inventory_and_signed_ledger(): void
    {
        $result = $this->buy($this->input(['quantity' => 3]));
        $this->assertFalse($result['replayed']); $this->assertSame(['tokens' => 30, 'gold' => 15], $result['charged']);
        $this->assertSame(70, (int) $this->player->fresh()->tokens); $this->assertSame(85, (int) $this->character->fresh()->gold);
        $this->assertSame(1, StateRevision::current($this->player->id));
        $this->assertDatabaseHas('character_items', ['character_id' => $this->character->id, 'item_id' => $this->content['key'], 'quantity' => 3]);
        $this->assertDatabaseHas('nl_shop_purchases', ['claim_id' => $result['id'], 'charged_tokens' => 30, 'charged_gold' => 15, 'quantity' => 3]);
        $this->assertSame(3, DB::table('nl_reward_ledger')->where('claim_id', $result['id'])->count());
        foreach (DB::table('nl_reward_ledger')->where('claim_id', $result['id'])->get() as $entry) {
            $sign = $entry->direction === 'credit' ? 1 : -1;
            $this->assertSame((int) $entry->balance_after, (int) $entry->balance_before + $sign * (int) $entry->quantity);
        }
    }
    public function test_single_currency_prices_do_not_charge_the_other_currency(): void
    {
        foreach ([[0, 5], [10, 0]] as [$tokens, $gold]) {
            $offer = ShopFixture::offer($this->admin, $this->content['id'], $tokens, $gold);
            $r = $this->buy($this->input(['offer_id' => $offer]));
            $this->assertSame(1, DB::table('nl_reward_ledger')->where('claim_id', $r['id'])->where('direction', 'debit')->count());
        }
        $this->assertSame(90, (int) $this->player->fresh()->tokens); $this->assertSame(95, (int) $this->character->fresh()->gold);
    }
    public function test_retry_survives_offer_pause_definition_change_and_catalog_disable(): void
    {
        $input = $this->input(); $first = $this->buy($input);
        app(ShopOffers::class)->setActive($this->admin, $this->offer, false);
        DB::table('nl_catalog')->where('id', $this->content['id'])->update(['enabled' => false]);
        DB::table('items')->where('item_id', $this->content['key'])->update(['level' => 99]);
        $again = $this->buy($input); $this->assertTrue($again['replayed']);
        $this->assertSame($first['id'], $again['id']); $this->assertSame($first['charged'], $again['charged']);
        $this->assertSame(90, (int) $this->player->fresh()->tokens); $this->assertSame(1, StateRevision::current($this->player->id));
        $this->assertSame(1, DB::table('nl_shop_purchases')->count());
    }
    public function test_retry_key_rejects_changed_quantity_offer_and_character(): void
    {
        $input = $this->input(); $this->buy($input);
        $other = Fixture::character($this->player);
        foreach ([['quantity' => 2], ['offer_id' => $this->offer + 100], ['character_id' => $other->id]] as $change) {
            $this->reject(fn () => $this->buy(array_replace($input, $change)));
        }
        $this->assertSame(90, (int) $this->player->fresh()->tokens); $this->assertSame(1, StateRevision::current($this->player->id));
    }
    public function test_client_price_balance_actor_and_invalid_quantity_are_rejected(): void
    {
        foreach ([['price_tokens' => 1], ['tokens' => 999], ['actor_id' => $this->admin->id], ['quantity' => 0], ['quantity' => -1], ['quantity' => 1.5], ['quantity' => 1001], ['quantity' => '1e2'], ['request_key' => 'staff:invalid']] as $change) {
            $this->reject(fn () => $this->buy($this->input($change))); $this->unchanged();
        }
    }
    public function test_foreign_character_and_database_revoked_actor_are_denied(): void
    {
        $foreign = Fixture::character(Fixture::actor());
        $this->deny(404, fn () => $this->buy($this->input(['character_id' => $foreign->id])));
        DB::table('users')->where('id', $this->player->id)->update(['account_type' => 2]);
        $this->deny(403, fn () => $this->buy($this->input())); $this->unchanged();
    }
    public function test_insufficient_either_currency_has_no_partial_charge(): void
    {
        foreach ([[101, 1], [1, 101]] as [$tokens, $gold]) {
            $offer = ShopFixture::offer($this->admin, $this->content['id'], $tokens, $gold);
            $this->reject(fn () => $this->buy($this->input(['offer_id' => $offer]))); $this->unchanged();
        }
    }
    public function test_multiplication_overflow_and_offer_quantity_limit_reject(): void
    {
        $offer = ShopFixture::offer($this->admin, $this->content['id'], 2147483647, 0);
        $this->reject(fn () => $this->buy($this->input(['offer_id' => $offer, 'quantity' => 2])));
        $this->reject(fn () => $this->buy($this->input(['quantity' => 11]))); $this->unchanged();
    }
    public function test_paused_offer_disabled_registry_and_unapproved_rights_reject(): void
    {
        app(ShopOffers::class)->setActive($this->admin, $this->offer, false);
        $this->reject(fn () => $this->buy($this->input()));
        app(ShopOffers::class)->setActive($this->admin, $this->offer, true);
        DB::table('nl_catalog')->where('id', $this->content['id'])->update(['enabled' => false]);
        $this->reject(fn () => $this->buy($this->input()));
        DB::table('nl_catalog')->where('id', $this->content['id'])->update(['enabled' => true, 'rights_basis' => 'unknown']);
        $this->reject(fn () => $this->buy($this->input())); $this->unchanged();
    }
    public function test_definition_edit_invalidates_existing_offer_instead_of_changing_terms(): void
    {
        DB::table('items')->where('item_id', $this->content['key'])->update(['price_tokens' => 999]);
        $this->reject(fn () => $this->buy($this->input()));
        $this->reject(fn () => app(ShopOffers::class)->setActive($this->admin, $this->offer, true)); $this->unchanged();
    }
    public function test_level_and_premium_eligibility_rechecked_on_server(): void
    {
        DB::table('items')->where('item_id', $this->content['key'])->update(['level' => 99]);
        $high = ShopFixture::offer($this->admin, $this->content['id']);
        $this->reject(fn () => $this->buy($this->input(['offer_id' => $high])));
        DB::table('items')->where('item_id', $this->content['key'])->update(['level' => 1, 'premium' => true]);
        $premium = ShopFixture::offer($this->admin, $this->content['id']);
        $this->reject(fn () => $this->buy($this->input(['offer_id' => $premium]))); $this->unchanged();
        $this->player->update(['account_type' => 1]); $this->buy($this->input(['offer_id' => $premium]));
        $this->assertSame(90, (int) $this->player->fresh()->tokens);
    }
    public function test_exhausted_supply_and_legacy_duplicate_inventory_rollback_debit(): void
    {
        DB::table('nl_catalog')->where('id', $this->content['id'])->update(['supply_limit' => 0]);
        $this->reject(fn () => $this->buy($this->input())); $this->unchanged();
        DB::table('nl_catalog')->where('id', $this->content['id'])->update(['supply_limit' => 100]);
        for ($i = 0; $i < 2; $i++) { DB::table('character_items')->insert(['character_id' => $this->character->id, 'item_id' => $this->content['key'], 'quantity' => 1, 'category' => 'item']); }
        $this->reject(fn () => $this->buy($this->input())); $this->unchanged();
        $this->assertSame(2, DB::table('character_items')->where('character_id', $this->character->id)->count());
    }
    public function test_skill_insufficient_funds_does_not_assign_an_element(): void
    {
        $skill = Fixture::content($this->admin, 'skill');
        DB::table('skills')->where('skill_id', $skill['key'])->update(['element' => 4]);
        DB::table('characters')->where('id', $this->character->id)->update(['element_1' => 0, 'element_2' => 0, 'element_3' => 0]);
        $offer = ShopFixture::offer($this->admin, $skill['id'], 101, 1, 1);
        $this->reject(fn () => $this->buy($this->input(['offer_id' => $offer]))); $this->unchanged();
        $this->assertSame(0, (int) $this->character->fresh()->element_1);
        $this->assertSame(0, DB::table('character_skills')->where('character_id', $this->character->id)->count());
    }
    public function test_skill_acquisition_and_duplicate_rejection_are_atomic(): void
    {
        $skill = Fixture::content($this->admin, 'skill');
        DB::table('skills')->where('skill_id', $skill['key'])->update(['element' => 4]);
        DB::table('characters')->where('id', $this->character->id)->update(['element_1' => 0, 'element_2' => 0, 'element_3' => 0]);
        $offer = ShopFixture::offer($this->admin, $skill['id'], 10, 5, 1);
        $this->buy($this->input(['offer_id' => $offer]));
        $this->assertSame(4, (int) $this->character->fresh()->element_1);
        $this->reject(fn () => $this->buy($this->input(['offer_id' => $offer])));
        $this->assertSame(90, (int) $this->player->fresh()->tokens); $this->assertSame(1, StateRevision::current($this->player->id));
        $this->assertSame(1, DB::table('character_skills')->where('character_id', $this->character->id)->count());
    }
    public function test_full_element_slots_reject_without_debit(): void
    {
        $skill = Fixture::content($this->admin, 'skill');
        DB::table('skills')->where('skill_id', $skill['key'])->update(['element' => 4]);
        DB::table('characters')->where('id', $this->character->id)->update(['element_1' => 1, 'element_2' => 2, 'element_3' => 0]);
        $offer = ShopFixture::offer($this->admin, $skill['id'], 10, 5, 1);
        $this->reject(fn () => $this->buy($this->input(['offer_id' => $offer]))); $this->unchanged();
    }
    public function test_purchase_invalidates_old_equipment_snapshot(): void
    {
        $state = app(ClientStateService::class)->snapshot($this->player, $this->character->id);
        $this->buy($this->input());
        $this->deny(409, fn () => app(ClientStateService::class)->saveEquipment($this->player, $this->character->id, ['revision' => $state['revision'], 'equipment' => ['weapon' => '']]));
        $this->assertSame(90, (int) $this->player->fresh()->tokens);
        $this->assertDatabaseHas('character_items', ['character_id' => $this->character->id, 'item_id' => $this->content['key'], 'quantity' => 1]);
    }
    public function test_writer_failure_after_debit_rolls_back_everything(): void
    {
        $writer = new class extends RewardWriter {
            public function apply(object $account, object $character, array $line, ?object $catalog): array
            { parent::apply($account, $character, $line, $catalog); throw new RuntimeException('qa-injected-after-inventory'); }
        };
        $caught = null;
        try { (new ShopPurchase($writer))->buy($this->player, $this->input()); } catch (RuntimeException $e) { $caught = $e; }
        $this->assertSame('qa-injected-after-inventory', $caught?->getMessage()); $this->unchanged();
        $this->assertSame(0, DB::table('character_items')->where('character_id', $this->character->id)->count());
    }
    public function test_late_purchase_receipt_failure_rolls_back_ledger_revision_supply(): void
    {
        $armed = true;
        DB::listen(function ($query) use (&$armed) {
            if ($armed && str_starts_with($query->sql, 'insert into `nl_shop_purchases`')) { throw new RuntimeException('qa-late-receipt'); }
        });
        $caught = null;
        try { $this->buy($this->input()); } catch (RuntimeException $e) { $caught = $e; } finally { $armed = false; }
        $this->assertSame('qa-late-receipt', $caught?->getMessage()); $this->unchanged();
        $this->assertSame(0, DB::table('nl_shop_purchases')->count());
        $this->assertSame(0, DB::table('nl_reward_ledger')->count());
    }
    public function test_portal_and_staff_grants_reconcile_with_shop_debits(): void
    {
        $campaign = Fixture::campaign($this->admin, [['type' => 'tokens', 'key' => '', 'quantity' => 20]]);
        app(RewardService::class)->starter($this->player, $this->character->id, $campaign, 'default', (string) Str::uuid());
        $this->buy($this->input(['quantity' => 2]));
        app(StaffGrantService::class)->grant($this->admin, $this->character->id, [['type' => 'tokens', 'key' => '', 'quantity' => 7]], 'QA shared currency chain', (string) Str::uuid());
        $balance = 100;
        foreach (DB::table('nl_reward_ledger')->where('kind', 'tokens')->orderBy('id')->get() as $entry) {
            $this->assertSame($balance, (int) $entry->balance_before);
            $balance += ($entry->direction === 'debit' ? -1 : 1) * (int) $entry->quantity;
            $this->assertSame($balance, (int) $entry->balance_after);
        }
        $this->assertSame(107, $balance); $this->assertSame($balance, (int) $this->player->fresh()->tokens);
        $this->assertSame(3, StateRevision::current($this->player->id));
    }
    public function test_offer_creation_defaults_inactive_and_reloads_staff_role(): void
    {
        $id = ShopFixture::offer($this->admin, $this->content['id'], 10, 5, 2, false);
        $this->assertFalse((bool) DB::table('nl_shop_offers')->where('id', $id)->value('active'));
        DB::table('users')->where('id', $this->admin->id)->update(['account_type' => 0]);
        $this->deny(403, fn () => app(ShopOffers::class)->setActive($this->admin, $id, true));
        $this->deny(403, fn () => ShopFixture::offer($this->admin, $this->content['id']));
    }
    public function test_zero_prices_unsupported_pets_and_unrecognized_offer_fields_reject(): void
    {
        $this->reject(fn () => ShopFixture::offer($this->admin, $this->content['id'], 0, 0));
        $pet = Fixture::content($this->admin, 'pet');
        $this->reject(fn () => ShopFixture::offer($this->admin, $pet['id']));
        $this->reject(fn () => app(ShopOffers::class)->create($this->admin, ['slug' => 'qa-test', 'title' => 'QA', 'catalog_id' => $this->content['id'], 'price_tokens' => 1, 'price_gold' => 0, 'max_quantity' => 1, 'active' => true]));
        $this->unchanged();
    }
    public function test_guest_and_player_authorization_and_closed_game_routes(): void
    {
        $this->get('/portal/shop')->assertRedirect('/portal/login');
        $this->actingAs($this->player)->get('/portal/shop')->assertOk()->assertSee('Toko Evaluasi');
        $this->get('/admin/shop-offers')->assertForbidden();
        foreach (['/amf', '/gateway.php', '/clan/auth/login', '/crew/auth/login'] as $path) { $this->post($path)->assertNotFound(); }
    }
    public function test_real_livewire_purchase_action_retains_identity_until_explicit_new_purchase(): void
    {
        $this->actingAs($this->player);
        $page = Livewire::test(App\Player\Pages\PlayerShop::class)->set('offerId', (string) $this->offer)->set('quantity', '2');
        $key = $page->get('requestKey');
        $page->call('buy')->assertHasNoErrors(); $id = $page->get('receiptId');
        $this->assertNotEmpty($id);
        $page->call('buy')->assertSet('receiptId', $id)->assertSet('requestKey', $key);
        $this->assertSame(80, (int) $this->player->fresh()->tokens);
        $page->call('newPurchase')->assertSet('receiptId', ''); $this->assertNotSame($key, $page->get('requestKey'));
        $this->assertSame(80, (int) $this->player->fresh()->tokens);
    }
    public function test_shop_request_identity_is_locked_from_client_updates(): void
    {
        $this->actingAs($this->player); $page = Livewire::test(App\Player\Pages\PlayerShop::class);
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $page->set('requestKey', (string) Str::uuid());
    }
    public function test_admin_form_creates_and_activates_only_explicit_offer(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin')); $this->actingAs($this->admin);
        $page = Livewire::test(App\Filament\Pages\ShopOffers::class)->set('offerSlug', 'qa-form-'.bin2hex(random_bytes(5)))->set('offerTitle', 'QA Form Offer')
            ->set('catalogId', (string) $this->content['id'])->set('priceTokens', '2')->set('priceGold', '3')->set('maxQuantity', '5')->call('createOffer')->assertHasNoErrors();
        $id = (int) $page->get('createdOfferId'); $this->assertGreaterThan(0, $id);
        $this->assertDatabaseHas('nl_shop_offers', ['id' => $id, 'active' => false]);
        $page->call('activate', $id)->assertHasNoErrors(); $this->assertDatabaseHas('nl_shop_offers', ['id' => $id, 'active' => true]);
        $page->call('pause', $id)->assertHasNoErrors(); $this->assertDatabaseHas('nl_shop_offers', ['id' => $id, 'active' => false]);
    }
    public function test_production_runtime_cannot_purchase_or_activate_offers(): void
    {
        app()->instance('env', 'production');
        try {
            $this->deny(403, fn () => $this->buy($this->input()));
            $this->deny(403, fn () => app(ShopOffers::class)->setActive($this->admin, $this->offer, true));
            $this->unchanged();
        } finally { app()->instance('env', 'testing'); }
    }
}
