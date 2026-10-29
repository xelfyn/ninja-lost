<?php

use App\Filament\Pages\StaffGrants;
use App\Models\Character;
use App\Models\User;
use App\Services\Content\ContentDirectoryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Livewire\Livewire;

final class ContentDirectoryTest extends TestCase
{
    use DatabaseTransactions;

    private string $root;
    private User $admin;
    private User $player;
    private Character $character;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->root = sys_get_temp_dir().'/nl-content-directory-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->root.'/public/skill', 0700, true);
        File::put($this->root.'/public/skill/skill_1.png', "\x89PNG\r\n\x1a\nfixture");
        File::put($this->root.'/manifest.json', json_encode([
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
            'ninja_assets.manifest' => $this->root.'/manifest.json',
            'ninja_assets.public_root' => $this->root.'/public',
            'ninja_assets.public_prefix' => '/game-assets',
            'ninja_assets.web_extensions' => ['png', 'webp', 'jpg', 'jpeg'],
        ]);

        $this->admin = $this->user(99, 'operator');
        $this->player = $this->user(0, 'player');
        $this->character = Character::create([
            'user_id' => $this->player->id,
            'name' => 'Kai',
            'level' => 41,
            'rank' => Character::RANK_JOUNIN,
            'gold' => 100,
        ]);

        DB::table('skills')->insert([
            'skill_id' => 'skill_1',
            'name' => 'Lightning Edge',
            'level' => 5,
            'element' => 5,
            'price_gold' => 0,
            'price_tokens' => 0,
            'premium' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->catalog('skill', 'skill_1', 'Catalog Lightning', true);
        $this->catalog('skill', 'skill_disabled', 'Disabled Skill', false);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function user(int $type, string $prefix): User
    {
        $suffix = bin2hex(random_bytes(5));

        return User::create([
            'name' => ucfirst($prefix),
            'username' => $prefix.'_'.$suffix,
            'email' => $prefix.'_'.$suffix.'@example.invalid',
            'password' => bin2hex(random_bytes(24)),
            'account_type' => $type,
            'tokens' => 0,
        ]);
    }

    private function catalog(string $kind, string $key, string $title, bool $enabled): void
    {
        DB::table('nl_catalog')->insert([
            'kind' => $kind,
            'content_key' => $key,
            'title' => $title,
            'source_url' => 'https://example.invalid/source',
            'source_revision' => 'fixture',
            'rights_basis' => 'permission_documented',
            'rights_evidence' => 'Fixture permission evidence',
            'compatibility_note' => 'Fixture compatibility note',
            'effect_metadata' => null,
            'enabled' => $enabled,
            'supply_limit' => 100,
            'opening_issued' => 0,
            'issued' => 0,
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_character_search_is_human_readable_and_does_not_expose_email(): void
    {
        $otherUser = $this->user(0, 'other');
        $other = Character::create([
            'user_id' => $otherUser->id,
            'name' => 'Kai',
            'level' => 20,
            'rank' => Character::RANK_GENIN,
        ]);

        $rows = app(ContentDirectoryService::class)->searchCharacters('Kai');

        $this->assertCount(2, $rows);
        $this->assertSame('Kai', $rows[0]['name']);
        $this->assertArrayHasKey('username', $rows[0]);
        $this->assertArrayNotHasKey('email', $rows[0]);
        $this->assertStringContainsString('#', $rows[0]['label']);
        $this->assertContains($this->character->id, array_column($rows, 'id'));
        $this->assertContains($other->id, array_column($rows, 'id'));
    }

    public function test_content_search_uses_definition_name_enabled_catalog_and_asset_status(): void
    {
        $rows = app(ContentDirectoryService::class)->searchContent('skill', 'Lightning');

        $this->assertCount(1, $rows);
        $this->assertSame('skill_1', $rows[0]['key']);
        $this->assertSame('Lightning Edge', $rows[0]['name']);
        $this->assertSame(5, $rows[0]['level']);
        $this->assertSame('verified', $rows[0]['asset']['visual_status']);
        $this->assertSame('/game-assets/skill/skill_1.png', $rows[0]['asset']['url']);

        $this->assertSame([], app(ContentDirectoryService::class)->searchContent('skill', 'Disabled Skill'));
        $this->assertNull(app(ContentDirectoryService::class)->content('skill', 'skill_disabled'));
    }

    public function test_unknown_content_kind_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ContentDirectoryService::class)->searchContent('bloodline', 'anything');
    }

    public function test_staff_grant_can_select_by_name_and_issue_exactly_once(): void
    {
        $this->actingAs($this->admin);

        $component = Livewire::test(StaffGrants::class)
            ->set('characterSearch', 'Kai')
            ->assertSee('Kai')
            ->call('selectCharacter', $this->character->id)
            ->assertSet('characterId', (string) $this->character->id)
            ->set('rewardType', 'skill')
            ->set('contentSearch', 'Lightning')
            ->assertSee('Lightning Edge')
            ->call('selectContent', 'skill_1')
            ->assertSet('contentKey', 'skill_1')
            ->set('quantity', '1')
            ->set('reason', 'Evaluation selector grant')
            ->call('grant')
            ->assertHasNoErrors();

        $receipt = $component->get('receiptId');
        $this->assertNotSame('', $receipt);
        $this->assertDatabaseHas('character_skills', [
            'character_id' => $this->character->id,
            'skill_id' => 'skill_1',
        ]);

        // The committed receipt disables accidental repetition. Calling grant
        // again on the same component does not issue another row.
        $component->call('grant');
        $this->assertSame(1, DB::table('character_skills')
            ->where('character_id', $this->character->id)
            ->where('skill_id', 'skill_1')
            ->count());
        $this->assertSame(1, DB::table('nl_claims')->where('id', $receipt)->count());
    }

    public function test_stale_disabled_content_is_rejected_before_grant(): void
    {
        $this->actingAs($this->admin);

        $component = Livewire::test(StaffGrants::class)
            ->call('selectCharacter', $this->character->id)
            ->set('rewardType', 'skill')
            ->call('selectContent', 'skill_1')
            ->set('quantity', '1')
            ->set('reason', 'Evaluation stale selection');

        DB::table('nl_catalog')
            ->where('kind', 'skill')
            ->where('content_key', 'skill_1')
            ->update(['enabled' => false]);

        $component->call('grant')->assertHasErrors(['contentSearch']);

        $this->assertDatabaseMissing('character_skills', [
            'character_id' => $this->character->id,
            'skill_id' => 'skill_1',
        ]);
    }
}
