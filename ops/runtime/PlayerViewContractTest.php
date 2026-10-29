<?php

use App\Models\Character;
use App\Models\User;
use App\Services\Player\PlayerViewContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PlayerViewContractTest extends TestCase
{
    use DatabaseTransactions;

    private string $assetRoot;
    private string $manifest;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $root = sys_get_temp_dir().'/nl-player-view-'.bin2hex(random_bytes(8));
        $this->assetRoot = $root.'/public';
        $this->manifest = $root.'/manifest.json';

        File::makeDirectory($this->assetRoot.'/skill', 0700, true);
        File::put($this->assetRoot.'/skill/skill_1.png', "\x89PNG\r\n\x1a\nfixture");

        File::put($this->manifest, json_encode([
            'schema_version' => 1,
            'assets' => [
                'skill' => [
                    'skill_1' => [
                        'source_id' => 'skill_1',
                        'source_type' => 'ninja-saga-swf',
                        'source_ref' => 'swf/library/icons/skill_icon_1-10.swf',
                        'web_path' => 'skill/skill_1.png',
                        'visual_status' => 'verified',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        config([
            'ninja_assets.manifest' => $this->manifest,
            'ninja_assets.public_root' => $this->assetRoot,
            'ninja_assets.public_prefix' => '/game-assets',
            'ninja_assets.web_extensions' => ['png', 'webp', 'jpg', 'jpeg'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->assetRoot));
        parent::tearDown();
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Player',
            'username' => 'player_'.bin2hex(random_bytes(5)),
            'email' => bin2hex(random_bytes(5)).'@example.invalid',
            'password' => bin2hex(random_bytes(24)),
            'account_type' => 0,
            'tokens' => 321,
        ]);
    }

    private function character(User $user): Character
    {
        return Character::create([
            'user_id' => $user->id,
            'name' => 'Canon Ninja',
            'level' => 60,
            'xp' => 12345,
            'gender' => 0,
            'rank' => Character::RANK_JOUNIN,
            'gold' => 4567,
            'skin_color' => '2',
            'hair_style' => null,
            'hair_color' => 'black',
            'equipment_weapon' => 'wpn_10',
            'equipment_clothing' => 'set_20',
            'equipment_back' => null,
            'equipment_accessory' => 'acsy_3',
            'equipment_skills' => 'skill_1,skill_missing,skill_1',
            'equipment_pet' => 'pet_2',
            'talent_1' => 'talent_alpha',
            'talent_2' => null,
            'talent_3' => 'talent_beta',
            'talent_skills' => '["talent_skill_1","talent_skill_1","talent_skill_2"]',
            'senjutsu_type' => 'toad',
            'senjutsu_skills' => 'sen_1,sen_2',
            'senjutsu_equipped_skills' => '["sen_2"]',
            'special_jounin_claimed' => false,
            'point_wind' => 10,
            'point_fire' => 20,
            'point_lightning' => 30,
            'point_water' => 40,
            'point_earth' => 50,
        ]);
    }

    public function test_contract_uses_real_state_and_fails_closed_for_missing_visuals(): void
    {
        $user = $this->user();
        $character = $this->character($user);

        DB::table('skills')->insert([
            'skill_id' => 'skill_1',
            'name' => 'Original Skill One',
            'level' => 5,
            'element' => 3,
            'price_gold' => 0,
            'price_tokens' => 0,
            'premium' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $state = app(PlayerViewContract::class)->character($user->id, $character->id);

        $this->assertSame('Canon Ninja', $state['character']['name']);
        $this->assertSame('Jounin', $state['character']['rank']['label']);
        $this->assertSame(321, $state['character']['tokens']);
        $this->assertSame(50, $state['character']['elements']['earth']);

        $this->assertFalse($state['appearance']['renderable']);
        $this->assertSame('incomplete', $state['appearance']['render_status']);
        $this->assertContains('face_schema_gap', $state['appearance']['issues']);
        $this->assertContains('hair_style_missing', $state['appearance']['issues']);
        $this->assertSame('schema_gap', $state['appearance']['base']['face']['status']);

        $this->assertSame('wpn_10', $state['equipment']['weapon']['id']);
        $this->assertSame('missing', $state['equipment']['weapon']['asset']['visual_status']);
        $this->assertNull($state['equipment']['weapon']['asset']['url']);
        $this->assertNull($state['equipment']['back']['asset']);

        $this->assertCount(2, $state['skills']['equipped']);
        $this->assertSame('Original Skill One', $state['skills']['equipped'][0]['name']);
        $this->assertSame('verified', $state['skills']['equipped'][0]['asset']['visual_status']);
        $this->assertSame('/game-assets/skill/skill_1.png', $state['skills']['equipped'][0]['asset']['url']);
        $this->assertSame('missing', $state['skills']['equipped'][1]['definition_status']);
        $this->assertSame('missing', $state['skills']['equipped'][1]['asset']['visual_status']);

        $this->assertSame(['talent_alpha', 'talent_beta'], $state['systems']['talents']['slots']);
        $this->assertSame(['talent_skill_1', 'talent_skill_2'], $state['systems']['talents']['learned']);
        $this->assertFalse($state['systems']['division_class']['configured']);
        $this->assertSame(60, $state['systems']['division_class']['evidence']['level']);
        $this->assertFalse($state['systems']['division_class']['evidence']['special_jounin_claimed']);
        $this->assertSame('toad', $state['systems']['senjutsu']['type']);
        $this->assertSame(['sen_1', 'sen_2'], $state['systems']['senjutsu']['learned']);
        $this->assertSame(['sen_2'], $state['systems']['senjutsu']['equipped']);
        $this->assertSame('pet_2', $state['systems']['pet']['id']);
        $this->assertSame('missing', $state['systems']['pet']['asset']['visual_status']);
    }

    public function test_contract_never_reads_another_accounts_character(): void
    {
        $owner = $this->user();
        $character = $this->character($owner);
        $other = $this->user();

        $this->expectException(NotFoundHttpException::class);
        app(PlayerViewContract::class)->character($other->id, $character->id);
    }
}
