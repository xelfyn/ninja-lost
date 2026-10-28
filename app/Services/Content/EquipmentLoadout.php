<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Content\EquipmentBlueprint;
use App\Content\SkillBlueprint;
use Illuminate\Support\Facades\DB;

/** Database-owned equipment only. No endpoint accepts a caller-supplied bonus total. */
final class EquipmentLoadout
{
    public function capture(object $account, object $character): array
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Equipment requires account-first transaction.');
        $selected = [];
        foreach (EquipmentBlueprint::COLUMNS as $slot => $column) {
            $id = $character->{$column};
            if (in_array($id, [null, '', 0, '0'], true)) continue;
            if (!is_string($id) || !preg_match('/\A'.EquipmentBlueprint::SLOTS[$slot].'_nl_[a-z0-9_]{1,60}\z/D', $id)) SkillBlueprint::reject('Equipment tidak berasal dari profil Studio yang didukung.');
            $selected[$slot] = $id;
        }
        ksort($selected); $ids = array_values($selected);
        // Membership is immutable after initial activation. Read it first only to discover
        // bundle IDs; all authoritative state is re-read under bundle locks below.
        $hints = DB::table('nl_studio_equipment')->whereIn('content_key', $ids)->get();
        if ($hints->count() !== count($ids)) SkillBlueprint::reject('Equipment belum dipublikasikan.');
        $drafts = $hints->pluck('draft_id')->unique()->sort()->values(); $releases = []; $definitions = [];
        foreach ($drafts as $draftId) {
            $bundle = app(EquipmentStudio::class)->active((int) $draftId); $releases[] = $bundle['release'];
            foreach ($bundle['payload']['definition']['pieces'] as $p) $definitions[$p['id']] = $p;
        }
        foreach ($selected as $slot => $id) {
            $p = $definitions[$id] ?? null;
            $catalog = DB::table('nl_catalog')->where('kind', 'item')->where('content_key', $id)->sharedLock()->first();
            $owned = DB::table('character_items')->where('character_id', $character->id)->where('item_id', $id)->sharedLock()->get();
            if (!$p || $p['slot'] !== $slot || !$catalog || !$catalog->enabled || $owned->count() !== 1 || (int) $owned->first()->quantity < 1
                || $p['level'] > (int) $character->level || ($p['premium'] && (int) $account->account_type !== 1)) SkillBlueprint::reject('Equipment tidak dimiliki, nonaktif, atau persyaratannya tidak terpenuhi.');
        }
        return ['profile' => 'nl-owned-equipment-v1', 'equipped' => $selected ?: (object) [], 'releases' => $releases];
    }
}
