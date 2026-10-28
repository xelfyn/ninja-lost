<?php

namespace NinjaLost\QA;

use App\Models\Character;
use App\Models\User;
use App\Services\Rewards\RewardManagement;
use App\Services\Rewards\StaffGrantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
require_once __DIR__.'/../rewards/Fixture.php';

final class LoadoutFixture
{
    public static function character(User $actor): Character
    {
        $character = Fixture::character($actor);
        $clear = array_fill_keys(['equipment_weapon', 'equipment_back', 'equipment_clothing', 'equipment_accessory', 'equipment_pet', 'talent_1', 'talent_2', 'talent_3', 'talent_skills', 'senjutsu_skills', 'senjutsu_equipped_skills', 'senjutsu_type'], 0);
        DB::table('characters')->where('id', $character->id)->update($clear + ['level' => 10, 'point_wind' => 0, 'point_fire' => 0, 'point_lightning' => 0, 'point_water' => 0, 'point_earth' => 0, 'element_1' => 2, 'element_2' => 0, 'element_3' => 0, 'equipment_skills' => '']);
        return $character->fresh();
    }
    public static function skill(User $admin, Character $character): array
    {
        Fixture::guard(); $id = 'skill_qa_'.bin2hex(random_bytes(8));
        DB::table('skills')->insert(['skill_id' => $id, 'name' => 'Self-authored zero-cost QA hit', 'level' => 1, 'element' => 2, 'premium' => false, 'created_at' => now(), 'updated_at' => now()]);
        $ops = app(RewardManagement::class);
        $registry = $ops->register($admin, ['kind' => 'skill', 'content_key' => $id, 'title' => 'Self-authored QA skill', 'source_url' => 'https://example.invalid/self-authored-g8f',
            'source_revision' => 'g8f-self-authored-v1', 'rights_basis' => 'self_authored', 'rights_evidence' => 'Synthetic CI mechanics; no inherited asset.',
            'compatibility_note' => 'Restricted base-stat evaluation only.', 'supply_limit' => 20]);
        $ops->setCatalogEnabled($admin, $registry, true);
        app(StaffGrantService::class)->grant($admin, $character->id, [['type' => 'skill', 'key' => $id, 'quantity' => 1]], 'QA owned combat skill', (string) Str::uuid());
        DB::table('characters')->where('id', $character->id)->update(['equipment_skills' => $id]);
        return ['id' => $id, 'registry' => $registry, 'rule' => ['id' => $id, 'damage' => 70, 'cp_cost' => 0, 'cooldown' => 0, 'type' => '2', 'level' => 1, 'premium' => false]];
    }
}
