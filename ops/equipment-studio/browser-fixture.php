<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/Fixture.php';
use App\Content\EquipmentBlueprint;
use App\Content\SkillBlueprint;
use App\Models\Character;
use App\Models\User;
use App\Services\Content\PublishedLoadout;
use App\Services\Rewards\ClientStateService;
use Illuminate\Support\Facades\DB;
use NinjaLost\QA\EquipmentFixture as EF;
use NinjaLost\QA\Fixture;
use NinjaLost\QA\LoadoutFixture;
function demand(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
try {
    Fixture::guard(); $file = getenv('NL_QA_STATE'); demand(is_string($file) && $file !== '', 'State path required');
    $action = $argv[1] ?? ''; $state = file_exists($file) ? json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR) : null;
    if ($action === 'prepare') {
        demand($state === null, 'Prior fixture state not cleaned');
        $staff = Fixture::actor(99, getenv('NL_ADMIN_EMAIL'), getenv('NL_ADMIN_PASSWORD'));
        $player = Fixture::actor(); $other = Fixture::actor(); $character = LoadoutFixture::character($player); $enemy = LoadoutFixture::character($other);
        $d = EF::definition(); foreach ($d['pieces'] as &$p) $p['supply_limit'] = null; unset($p);
        $state = ['staff' => $staff->id, 'player' => $player->id, 'other' => $other->id, 'character' => $character->id, 'enemy' => $enemy->id, 'definition' => $d];
        file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR)); chmod($file, 0600); echo json_encode($state, JSON_THROW_ON_ERROR); exit(0);
    }
    demand(is_array($state), 'Fixture state missing');
    $draft = DB::table('nl_studio_drafts')->where('content_key', $state['definition']['id'])->first();
    $ids = array_column($state['definition']['pieces'], 'id');
    $head = $draft ? DB::table('nl_studio_heads')->where('draft_id', $draft->id)->value('release_id') : null;
    $release = $draft ? DB::table('nl_studio_releases')->where('draft_id', $draft->id)->orderByDesc('draft_revision')->first() : null;
    $output = [];
    switch ($action) {
        case 'empty':
            demand(!$draft && DB::table('items')->whereIn('item_id', $ids)->count() === 0, 'Preview changed database'); break;
        case 'draft':
            demand($draft && !$head && !$release, 'Draft phase mismatch');
            demand(BlueprintEqual($draft->definition, $state['definition']), 'Browser values did not match expected definition');
            demand(DB::table('items')->whereIn('item_id', $ids)->count() === 0, 'Draft projected live items'); $output = ['id' => (int) $draft->id]; break;
        case 'published':
            demand($draft && $release, 'Publication absent'); $output = ['release' => $release->id, 'head' => $head]; break;
        case 'grant':
            demand($head !== null && DB::table('items')->whereIn('item_id', $ids)->count() === 2, 'Incomplete activation');
            demand(DB::table('nl_claims')->where('user_id', $state['player'])->count() === 0, 'Fixture grant already used');
            EF::grantEquip(User::findOrFail($state['staff']), User::findOrFail($state['player']), Character::findOrFail($state['character']), $state['definition']);
            $output = ['granted' => true]; break;
        case 'unequip':
        case 'reequip':
            $actor = User::findOrFail($state['player']); $service = app(ClientStateService::class); $s = $service->snapshot($actor, $state['character']);
            $back = array_column($state['definition']['pieces'], 'id', 'slot')['back'];
            $service->saveEquipment($actor, $state['character'], ['revision' => $s['revision'], 'equipment' => ['back' => $action === 'unequip' ? '' : $back]]); break;
        case 'bundle':
            $output = ['bundles' => [app(PublishedLoadout::class)->prepareEquipped(User::findOrFail($state['player']), $state['character']), app(PublishedLoadout::class)->prepareEquipped(User::findOrFail($state['other']), $state['enemy'])]]; break;
        case 'verify':
            demand($head !== null, 'Head missing');
            demand((int) DB::table('nl_catalog')->whereIn('content_key', $ids)->sum('issued') === 2, 'Supply changed during versioning');
            demand((int) DB::table('character_items')->where('character_id', $state['character'])->whereIn('item_id', $ids)->sum('quantity') === 2, 'Owned items lost or duplicated');
            demand(DB::table('nl_claims')->where('user_id', $state['player'])->count() === 1, 'Grant duplicated');
            demand((int) DB::table('users')->where('id', $state['player'])->value('tokens') === 0, 'Wallet changed');
            demand((int) DB::table('characters')->where('id', $state['character'])->value('gold') === 0, 'Gold changed');
            $output = ['head' => $head, 'revision' => (int) $draft->revision]; break;
        case 'cleanup':
            if ($draft) EF::cleanup((int) $draft->id, [$state['staff'], $state['player'], $state['other']]);
            else Fixture::cleanup([$state['staff'], $state['player'], $state['other']], [], []);
            unlink($file); break;
        default: throw new RuntimeException('Unknown fixture operation');
    }
    echo json_encode($output, JSON_THROW_ON_ERROR);
} catch (Throwable $e) { fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n"); exit(1); }
function BlueprintEqual(string $stored, array $expected): bool {
    return SkillBlueprint::json(EquipmentBlueprint::normalize(json_decode($stored, true, 32, JSON_THROW_ON_ERROR))) === SkillBlueprint::json(EquipmentBlueprint::normalize($expected));
}
