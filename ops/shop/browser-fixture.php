<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/Fixture.php';
use NinjaLost\QA\Fixture;
use NinjaLost\QA\ShopFixture;
use Illuminate\Support\Facades\DB;
use App\Support\Economy\StateRevision;
Fixture::guard();
$path = getenv('NL_QA_STATE');
if (!$path || !is_dir(dirname($path))) { throw new RuntimeException('Isolated state path required'); }
function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
$action = $argv[1] ?? '';
if ($action === 'prepare') {
    check(!file_exists($path), 'Do not overwrite fixture ownership state');
    $admin = Fixture::actor(99, getenv('NL_ADMIN_EMAIL'), getenv('NL_ADMIN_PASSWORD'));
    $player = Fixture::actor(0, getenv('NL_PLAYER_EMAIL'), getenv('NL_PLAYER_PASSWORD'));
    $character = Fixture::character($player); $player->update(['tokens' => 100]); $character->update(['gold' => 100]);
    $content = Fixture::content($admin, 'item', 20);
    $state = ['admin' => $admin->id, 'player' => $player->id, 'character' => $character->id, 'catalog' => $content['id'], 'content_key' => $content['key'], 'slug' => 'nlqa-browser-shop-'.bin2hex(random_bytes(8))];
    file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR)); chmod($path, 0600);
    echo json_encode($state, JSON_THROW_ON_ERROR); exit;
}
$state = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
if ($action === 'offer') {
    $offer = DB::table('nl_shop_offers')->where('slug', $state['slug'])->where('created_by', $state['admin'])->first();
    check($offer && !$offer->active && (int) $offer->catalog_id === $state['catalog'] && (int) $offer->price_tokens === 10 && (int) $offer->price_gold === 5, 'Admin form did not persist correct inactive terms');
    echo json_encode(['offer' => $offer->id], JSON_THROW_ON_ERROR); exit;
}
if ($action === 'verify') {
    $claim = DB::table('nl_claims')->where('user_id', $state['player'])->where('source', 'shop_purchase')->sole();
    check((int) $claim->character_id === $state['character'] && (int) $claim->actor_id === $state['player'], 'Purchase actor/ownership');
    $purchase = DB::table('nl_shop_purchases')->where('claim_id', $claim->id)->sole();
    check((int) $purchase->quantity === 2 && (int) $purchase->charged_tokens === 20 && (int) $purchase->charged_gold === 10, 'Purchase cost');
    check((int) DB::table('users')->where('id', $state['player'])->value('tokens') === 80, 'Token balance');
    check((int) DB::table('characters')->where('id', $state['character'])->value('gold') === 90, 'Gold balance');
    check((int) DB::table('character_items')->where('character_id', $state['character'])->where('item_id', $state['content_key'])->value('quantity') === 2, 'Inventory mismatch');
    check((int) DB::table('nl_catalog')->where('id', $state['catalog'])->value('issued') === 2, 'Supply counter mismatch');
    check(StateRevision::current($state['player']) === 1, 'Revision must increment once');
    $ledger = DB::table('nl_reward_ledger')->where('claim_id', $claim->id)->get(); check($ledger->count() === 3, 'Ledger count');
    foreach ($ledger as $entry) { check((int) $entry->balance_after === (int) $entry->balance_before + ($entry->direction === 'debit' ? -1 : 1) * (int) $entry->quantity, 'Signed ledger arithmetic'); }
    echo json_encode(['receipt' => $claim->id], JSON_THROW_ON_ERROR); exit;
}
if ($action === 'cleanup') {
    $offers = DB::table('nl_shop_offers')->where('created_by', $state['admin'])->pluck('id')->all();
    ShopFixture::cleanup([$state['admin'], $state['player']], $offers, [$state['catalog']]);
    unlink($path); echo '{}'; exit;
}
throw new RuntimeException('Unsupported fixture action');
