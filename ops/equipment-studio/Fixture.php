<?php

namespace NinjaLost\QA;

use App\Models\Character;
use App\Models\User;
use App\Services\Content\EquipmentStudio;
use App\Services\Rewards\ClientStateService;
use App\Services\Rewards\StaffGrantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
require_once __DIR__.'/../combat-data/LoadoutFixture.php';

final class EquipmentFixture
{
    public static function definition(?string $key = null): array
    {
        $key ??= bin2hex(random_bytes(6));
        return ['id' => 'gear_nl_qa_'.$key, 'name' => 'QA equipment '.$key, 'lore' => 'Self-authored integration fixture.',
            'pieces' => [
                ['id' => 'wpn_nl_qa_'.$key, 'name' => 'QA blade '.$key, 'slot' => 'weapon', 'level' => 1, 'premium' => false, 'visual_key' => null, 'supply_limit' => 20,
                    'modifiers' => [['stat' => 'agility', 'unit' => 'flat', 'amount' => 15], ['stat' => 'atk', 'unit' => 'flat', 'amount' => 20]]],
                ['id' => 'back_nl_qa_'.$key, 'name' => 'QA cape '.$key, 'slot' => 'back', 'level' => 1, 'premium' => false, 'visual_key' => null, 'supply_limit' => 20,
                    'modifiers' => [['stat' => 'maxHp', 'unit' => 'flat', 'amount' => 100]]],
            ],
            'bonuses' => [['required' => 2, 'modifiers' => [['stat' => 'agility', 'unit' => 'flat', 'amount' => 10], ['stat' => 'atk', 'unit' => 'percent', 'amount' => 20]]]],
            'source_url' => 'https://example.invalid/self-authored-equipment', 'rights_basis' => 'self_authored',
            'rights_evidence' => 'Synthetic mechanics authored for CI; no imported game assets.'];
    }
    public static function release(User $staff, array $definition): array
    {
        Fixture::guard(); $service = app(EquipmentStudio::class); $draft = $service->save($staff, $definition);
        $release = $service->publish($staff, $draft['id'], $draft['revision'], $draft['hash']);
        return ['draft' => $draft, 'release' => $release];
    }
    public static function grantEquip(User $staff, User $actor, Character $character, array $definition): void
    {
        Fixture::guard();
        $lines = array_map(fn ($p) => ['type' => 'item', 'key' => $p['id'], 'quantity' => 1], $definition['pieces']);
        app(StaffGrantService::class)->grant($staff, $character->id, $lines, 'QA equipment test', (string) Str::uuid());
        $service = app(ClientStateService::class); $state = $service->snapshot($actor, $character->id);
        $service->saveEquipment($actor, $character->id, ['revision' => $state['revision'], 'equipment' => array_column($definition['pieces'], 'id', 'slot')]);
    }
    /** Owned disposable CI fixtures only; production recovery must retain all these records. */
    public static function cleanup(int $draftId, array $userIds): void
    {
        Fixture::guard();
        if (!$userIds || count($userIds) !== count(array_unique($userIds))) throw new \RuntimeException('Explicit distinct fixture actors required.');
        DB::transaction(function () use ($draftId, $userIds) {
            $users = DB::table('users')->whereIn('id', $userIds)->lockForUpdate()->get();
            if ($users->count() !== count($userIds) || $users->contains(fn ($u) => !str_starts_with($u->username, 'nlqa_'))) throw new \RuntimeException('Only owned synthetic users can be cleaned.');
            $draft = DB::table('nl_studio_drafts')->where('id', $draftId)->lockForUpdate()->first();
            if (!$draft || !str_starts_with($draft->content_key, 'gear_nl_qa_') || !in_array((int) $draft->created_by, $userIds, true)) throw new \RuntimeException('Only owned synthetic equipment can be cleaned.');
            $maps = DB::table('nl_studio_equipment')->where('draft_id', $draftId)->get();
            $keys = $maps->pluck('content_key');
            $characters = DB::table('characters')->whereIn('user_id', $userIds)->pluck('id');
            if (DB::table('character_items')->whereIn('item_id', $keys)->whereNotIn('character_id', $characters)->exists()) throw new \RuntimeException('Equipment is owned outside this fixture.');
            $claims = DB::table('nl_claims')->whereIn('user_id', $userIds)->pluck('id');
            DB::table('nl_reward_ledger')->whereIn('claim_id', $claims)->delete();
            DB::table('nl_claims')->whereIn('id', $claims)->delete();
            DB::table('nl_operations_audit')->whereIn('actor_id', $userIds)->delete();
            DB::table('nl_studio_heads')->where('draft_id', $draftId)->delete();
            DB::table('nl_studio_releases')->where('draft_id', $draftId)->delete();
            DB::table('nl_studio_equipment')->where('draft_id', $draftId)->delete();
            DB::table('nl_studio_drafts')->where('id', $draftId)->delete();
            DB::table('character_items')->whereIn('character_id', $characters)->whereIn('item_id', $keys)->delete();
            // Catalog provenance references its creating staff account with ON DELETE RESTRICT.
            DB::table('nl_catalog')->whereIn('id', $maps->pluck('catalog_id'))->delete();
            DB::table('items')->whereIn('id', $maps->pluck('backend_item_id'))->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
        });
    }
}
