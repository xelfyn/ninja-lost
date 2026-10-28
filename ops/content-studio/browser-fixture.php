<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/Fixture.php';
use NinjaLost\QA\Fixture;
use NinjaLost\QA\StudioFixture;
use NinjaLost\QA\LoadoutFixture;
use App\Models\User;
use App\Services\Content\PublishedLoadout;
use App\Services\Rewards\StaffGrantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
Fixture::guard();
$path = getenv('NL_QA_STATE'); if (!$path || !is_dir(dirname($path))) throw new RuntimeException('Isolated fixture state required');
function verifyState(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$action = $argv[1] ?? '';
if ($action === 'prepare') {
    verifyState(!is_file($path), 'Do not overwrite fixture state');
    $admin = Fixture::actor(99, getenv('NL_ADMIN_EMAIL'), getenv('NL_ADMIN_PASSWORD'));
    $player = Fixture::actor(0, getenv('NL_PLAYER_EMAIL'), getenv('NL_PLAYER_PASSWORD')); $other = Fixture::actor();
    $char = LoadoutFixture::character($player); $enemy = LoadoutFixture::character($other);
    DB::table('characters')->where('id', $char->id)->update(['point_wind' => 1]);
    $definition = StudioFixture::definition(['cp_cost' => 12, 'cooldown' => 3, 'effects' => [
        ['code' => 'stun', 'duration' => 1, 'amount' => 0, 'chance' => 100], ['code' => 'burn', 'duration' => 2, 'amount' => 10, 'chance' => 100],
        ['code' => 'heal', 'duration' => 0, 'amount' => 10, 'chance' => 100], ['code' => 'purify', 'duration' => 0, 'amount' => 0, 'chance' => 100],
        ['code' => 'disperse', 'duration' => 0, 'amount' => 0, 'chance' => 100],
    ]]);
    $state = ['admin' => $admin->id, 'player' => $player->id, 'other' => $other->id, 'character' => $char->id, 'enemy' => $enemy->id,
        'definition' => $definition, 'grant_keys' => [(string) Str::uuid(), (string) Str::uuid()]];
    file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR)); chmod($path, 0600); echo json_encode($state, JSON_THROW_ON_ERROR); exit;
}
$s = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR); $key = $s['definition']['id'];
$draft = DB::table('nl_studio_drafts')->where('content_key', $key)->where('created_by', $s['admin'])->first();
if ($action === 'cleanup') {
    StudioFixture::cleanup([$s['admin'], $s['player'], $s['other']], $draft ? [$draft->id] : []); unlink($path); echo '{}'; exit;
}
if ($action === 'empty') {
    verifyState(!$draft && !DB::table('skills')->where('skill_id', $key)->exists(), 'Preview must not persist'); echo '{}'; exit;
}
verifyState($draft !== null, 'Draft missing');
if ($action === 'draft') {
    $d = json_decode($draft->definition, true); verifyState((int) $draft->revision === 1 && $d['damage'] === 80 && count($d['effects']) === 5, 'Form did not persist expected definition');
    verifyState(!DB::table('skills')->where('skill_id', $key)->exists(), 'Saving draft changed backend'); echo json_encode(['draft_id' => $draft->id]); exit;
}
if ($action === 'published') {
    $release = DB::table('nl_studio_releases')->where('draft_id', $draft->id)->where('draft_revision', 1)->sole();
    verifyState(!DB::table('nl_studio_heads')->where('draft_id', $draft->id)->exists(), 'Publish activated a release');
    verifyState(!DB::table('skills')->where('skill_id', $key)->exists(), 'Publish created live content'); echo json_encode(['release' => $release->id]); exit;
}
$head = DB::table('nl_studio_heads')->where('draft_id', $draft->id)->sole();
$release = DB::table('nl_studio_releases')->where('id', $head->release_id)->sole();
$payload = app(App\Services\Content\SkillStudio::class)->decode($release);
$catalog = DB::table('nl_catalog')->where('id', $draft->catalog_id)->sole();
verifyState($catalog->enabled && $catalog->source_revision === $payload['revision'], 'Catalog/head mismatch');
if ($action === 'active') { verifyState($payload['skills'][0]['damage'] === 80, 'Wrong active damage'); echo json_encode(['release' => $head->release_id]); exit; }
if ($action === 'grant') {
    foreach ([[$s['player'], $s['character']], [$s['other'], $s['enemy']]] as $i => [$user, $char]) {
        app(StaffGrantService::class)->grant(User::findOrFail($s['admin']), $char, [['type' => 'skill', 'key' => $key, 'quantity' => 1]], 'QA browser-authored skill', $s['grant_keys'][$i]);
        DB::table('characters')->where('id', $char)->update(['equipment_skills' => $key]);
    }
    echo '{}'; exit;
}
if ($action === 'unchanged-active') {
    verifyState((int) $draft->revision === 2 && json_decode($draft->definition, true)['damage'] === 90, 'Edited draft missing');
    verifyState($payload['skills'][0]['damage'] === 80, 'Draft changed active mechanics'); echo '{}'; exit;
}
if ($action === 'bundle') {
    verifyState((int) $catalog->issued === 2, 'Publication/reset corrupted issued supply');
    verifyState(DB::table('character_skills')->where('skill_id', $key)->count() === 2, 'Ownership lost');
    verifyState(DB::table('nl_claims')->whereIn('user_id', [$s['player'], $s['other']])->count() === 2, 'Unexpected reward issue');
    $bundles = [app(PublishedLoadout::class)->prepare(User::findOrFail($s['player']), $s['character']), app(PublishedLoadout::class)->prepare(User::findOrFail($s['other']), $s['enemy'])];
    echo json_encode(['bundles' => $bundles], JSON_THROW_ON_ERROR); exit;
}
throw new RuntimeException('Unknown fixture operation');
