<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__.'/../shop/Fixture.php';

use App\Services\Rewards\StaffGrantService;
use App\Support\Economy\StateRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use NinjaLost\QA\Fixture;
use NinjaLost\QA\ShopFixture;

Fixture::guard();
$path = getenv('NL_GATEWAY_FIXTURE');
if (!$path || basename($path) !== 'state.json' || !str_starts_with(basename(dirname($path)), 'nl-gateway-probe.')) { throw new RuntimeException('Isolated gateway fixture path required.'); }
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    if (file_exists($path)) { throw new RuntimeException('Refuse to overwrite fixture state.'); }
    $password = bin2hex(random_bytes(24)); $admin = Fixture::actor(99); $player = Fixture::actor(0, null, $password);
    $character = Fixture::character($player); $foreign = Fixture::actor(); $foreignCharacter = Fixture::character($foreign);
    $content = Fixture::content($admin, 'item', 20);
    $offer = ShopFixture::offer($admin, $content['id']); $paused = ShopFixture::offer($admin, $content['id'], 1, 0, 1, false);
    $starter = Fixture::campaign($admin, [['type' => 'tokens', 'key' => '', 'quantity' => 100], ['type' => 'gold', 'key' => '', 'quantity' => 100]]);
    $code = 'QA-'.strtoupper(bin2hex(random_bytes(8)));
    $redeem = Fixture::campaign($admin, [['type' => 'tokens', 'key' => '', 'quantity' => 7], ['type' => 'gold', 'key' => '', 'quantity' => 2]], ['kind' => 'redeem', 'code' => $code]);
    $state = ['admin' => $admin->id, 'player' => $player->id, 'foreign' => $foreign->id, 'character' => $character->id, 'foreign_character' => $foreignCharacter->id,
        'username' => $player->username, 'password' => $password, 'content' => $content, 'offer' => $offer, 'paused_offer' => $paused, 'starter' => $starter, 'redeem' => $redeem, 'code' => $code];
    file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR)); chmod($path, 0600); exit;
}
if (!is_file($path)) { throw new RuntimeException('Fixture state missing.'); }
$s = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
if ($mode === 'grant') {
    app(StaffGrantService::class)->grant(App\Models\User::findOrFail($s['admin']), $s['character'], [['type' => 'tokens', 'key' => '', 'quantity' => 13], ['type' => 'gold', 'key' => '', 'quantity' => 3]], 'QA HTTP cross-adapter grant', (string) Str::uuid()); exit;
}
if ($mode === 'verify') {
    $check = function ($condition, string $label) { if (!$condition) { throw new RuntimeException('Gateway fixture mismatch: '.$label); } };
    $check((int) DB::table('users')->where('id', $s['player'])->value('tokens') === 100, 'tokens');
    $check((int) DB::table('characters')->where('id', $s['character'])->value('gold') === 95, 'gold');
    $check(StateRevision::current($s['player']) === 5, 'revision');
    $check((int) DB::table('character_items')->where('character_id', $s['character'])->where('item_id', $s['content']['key'])->value('quantity') === 2, 'inventory');
    $check((int) DB::table('nl_catalog')->where('id', $s['content']['id'])->value('issued') === 2, 'supply');
    $claims = DB::table('nl_claims')->where('user_id', $s['player'])->pluck('id');
    $check($claims->count() === 4, 'one starter, redeem, purchase and staff receipt');
    $check(DB::table('nl_shop_purchases')->whereIn('claim_id', $claims)->count() === 1, 'one purchase');
    $check(DB::table('nl_operations_audit')->where('actor_id', $s['player'])->whereIn('action', ['gateway.starter', 'gateway.redeem', 'gateway.purchase'])->count() === 3, 'one audit per new gateway receipt');
    foreach (['tokens' => 100, 'gold' => 95] as $kind => $expected) {
        $balance = 0;
        foreach (DB::table('nl_reward_ledger')->whereIn('claim_id', $claims)->where('kind', $kind)->orderBy('id')->get() as $entry) {
            $check((int) $entry->balance_before === $balance, $kind.' chain before');
            $balance += ($entry->direction === 'debit' ? -1 : 1) * (int) $entry->quantity;
            $check((int) $entry->balance_after === $balance, $kind.' chain after');
        }
        $check($balance === $expected, $kind.' chain final');
    }
    $check(DB::table('nl_claims')->where('user_id', $s['foreign'])->count() === 0, 'foreign account unchanged');
    echo "PASS independent SQL: balances100/95 revision5 inventory2 supply2 one purchase continuous debit/credit chains\n"; exit;
}
if ($mode === 'cleanup') {
    $users = [$s['admin'], $s['player'], $s['foreign']];
    $claims = DB::table('nl_claims')->whereIn('user_id', $users)->pluck('id');
    DB::table('nl_shop_purchases')->whereIn('claim_id', $claims)->delete(); DB::table('nl_reward_ledger')->whereIn('claim_id', $claims)->delete(); DB::table('nl_claims')->whereIn('id', $claims)->delete();
    DB::table('nl_shop_offers')->whereIn('id', [$s['offer'], $s['paused_offer']])->delete();
    DB::table('nl_campaigns')->whereIn('id', [$s['starter'], $s['redeem']])->delete();
    DB::table('sessions')->whereIn('user_id', $users)->delete();
    Fixture::cleanup($users, [], [$s['content']['id']]); unlink($path); exit;
}
throw new RuntimeException('Unknown fixture mode.');
