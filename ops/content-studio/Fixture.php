<?php

namespace NinjaLost\QA;

use App\Content\SkillBlueprint;
use App\Models\User;
use App\Services\Content\SkillStudio;
use Illuminate\Support\Facades\DB;
require_once __DIR__.'/../rewards/Fixture.php';
require_once __DIR__.'/../combat-data/LoadoutFixture.php';

final class StudioFixture
{
    public static function definition(array $changes = []): array
    {
        Fixture::guard();
        return array_replace(['id' => 'skill_nl_qa_'.bin2hex(random_bytes(6)), 'name' => 'QA Studio Skill', 'lore' => 'Synthetic, no external asset.',
            'element' => 2, 'level' => 1, 'premium' => false, 'damage' => 80, 'cp_cost' => 0, 'cooldown' => 0,
            'animation_key' => null, 'hit_frames' => [], 'effects' => [], 'source_url' => 'https://example.invalid/nl-self-authored',
            'rights_basis' => 'self_authored', 'rights_evidence' => 'Self-authored mechanics for isolated CI; no game asset.', 'supply_limit' => 20], $changes);
    }
    public static function release(User $admin, array $d, bool $active = false): array
    {
        Fixture::guard(); $ops = app(SkillStudio::class);
        $draft = $ops->save($admin, $d); $release = $ops->publish($admin, $draft['id'], $draft['revision'], $draft['hash']);
        if ($active) $ops->activate($admin, $release['id'], null);
        return ['draft' => $draft, 'release' => $release, 'definition' => SkillBlueprint::normalize($d)];
    }
    public static function cleanup(array $userIds, array $draftIds): void
    {
        Fixture::guard();
        $drafts = DB::table('nl_studio_drafts')->whereIn('id', $draftIds)->get();
        $claims = DB::table('nl_claims')->whereIn('user_id', $userIds)->pluck('id');
        DB::table('nl_reward_ledger')->whereIn('claim_id', $claims)->delete(); DB::table('nl_claims')->whereIn('id', $claims)->delete();
        DB::table('nl_studio_heads')->whereIn('draft_id', $draftIds)->delete();
        DB::table('nl_studio_releases')->whereIn('draft_id', $draftIds)->delete();
        DB::table('nl_studio_drafts')->whereIn('id', $draftIds)->delete();
        foreach ($drafts as $d) {
            if (!str_starts_with($d->content_key, 'skill_nl_qa_')) throw new \RuntimeException('Not owned QA content');
            DB::table('character_skills')->where('skill_id', $d->content_key)->delete();
            if ($d->catalog_id) DB::table('nl_catalog')->where('id', $d->catalog_id)->delete();
            if ($d->backend_skill_id) DB::table('skills')->where('id', $d->backend_skill_id)->delete();
        }
        Fixture::cleanup($userIds, [], []);
    }
}
