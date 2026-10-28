<?php

declare(strict_types=1);

namespace App\Services\Combat;

use App\Models\User;
use App\Services\Content\EquipmentLoadout;
use App\Content\SkillBlueprint;
use App\Support\Economy\StateRevision;
use App\Support\Rewards\RewardRules;
use Illuminate\Support\Facades\DB;

/** Read-only admission input. Not a bearer ticket, game endpoint, or combat verdict. */
final class CombatLoadout
{
    public function prepare(User $actor, int $characterId): array { return $this->read($actor, $characterId, false); }

    /** Explicit internal profile: gear is validated, never silently stripped or trusted from a client. */
    public function prepareEquipped(User $actor, int $characterId): array { return $this->read($actor, $characterId, true); }

    private function read(User $actor, int $characterId, bool $withEquipment): array
    {
        abort_unless(app()->environment(['local', 'testing']), 403, 'Evaluation runtime only.');
        return DB::transaction(function () use ($actor, $characterId, $withEquipment) {
            $account = DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            abort_unless($account && in_array((int) $account->account_type, [0, 1], true), 403);
            $character = DB::table('characters')->where('id', $characterId)->where('user_id', $account->id)->lockForUpdate()->first();
            abort_unless($character, 404);
            $unsupported = ['equipment_pet', 'talent_1', 'talent_2', 'talent_3', 'talent_skills', 'senjutsu_skills', 'senjutsu_equipped_skills', 'senjutsu_type'];
            if (!$withEquipment) $unsupported = array_merge(['equipment_weapon', 'equipment_back', 'equipment_clothing', 'equipment_accessory'], $unsupported);
            foreach ($unsupported as $field) {
                if (!in_array($character->{$field} ?? null, [null, '', 0, '0'], true)) RewardRules::reject('Loadout memakai gear/pet/talent/senjutsu yang belum didukung profil combat ini.');
            }
            $stats = [];
            foreach (['level', 'point_wind', 'point_fire', 'point_lightning', 'point_earth', 'point_water'] as $field) {
                $value = $character->{$field};
                if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < ($field === 'level' ? 1 : 0) || (int) $value > 10000) RewardRules::reject('Stat karakter tidak valid.');
                $stats[$field] = (int) $value;
            }
            // Bundle locks precede item catalog locks; skill locks follow the equipment bundle.
            $equipment = $withEquipment ? app(EquipmentLoadout::class)->capture($account, $character) : null;
            $equipped = $character->equipment_skills ?? '';
            if (!is_string($equipped) || strlen($equipped) > 1000) RewardRules::reject('Daftar skill tidak valid.');
            $ids = $equipped === '' ? [] : array_map('trim', explode(',', $equipped));
            if (count($ids) > 8 || count($ids) !== count(array_unique($ids))) RewardRules::reject('Skill duplikat atau batas profil terlampaui.');
            foreach ($ids as $id) if (!preg_match('/\Askill_[A-Za-z0-9_-]{1,80}\z/D', $id)) RewardRules::reject('Identitas skill tidak valid.');
            $catalog = DB::table('nl_catalog')->where('kind', 'skill')->whereIn('content_key', $ids)->orderBy('id')->sharedLock()->get()->groupBy('content_key');
            $definitions = DB::table('skills')->whereIn('skill_id', $ids)->orderBy('id')->sharedLock()->get()->groupBy('skill_id');
            $owned = DB::table('character_skills')->where('character_id', $characterId)->whereIn('skill_id', $ids)->orderBy('id')->sharedLock()->get()->groupBy('skill_id');
            $skills = [];
            foreach ($ids as $id) {
                if (($catalog->get($id)?->count() ?? 0) !== 1 || ($definitions->get($id)?->count() ?? 0) !== 1 || ($owned->get($id)?->count() ?? 0) !== 1) RewardRules::reject('Skill tidak dimiliki, tidak terdaftar atau ambigu.');
                $record = $catalog->get($id)->first(); $definition = $definitions->get($id)->first();
                if (!$record->enabled || !in_array($record->rights_basis, ['self_authored', 'permission_documented'], true) || !$record->source_revision) RewardRules::reject('Registri skill belum disetujui.');
                if ((int) $definition->level > $stats['level'] || ($definition->premium && (int) $account->account_type !== 1)) RewardRules::reject('Persyaratan skill belum dipenuhi.');
                if (in_array((int) $definition->element, [1, 2, 3, 4, 5], true) && !in_array((int) $definition->element, [(int) $character->element_1, (int) $character->element_2, (int) $character->element_3], true)) RewardRules::reject('Elemen skill tidak dimiliki karakter.');
                $skills[] = ['id' => $id, 'catalog_id' => (int) $record->id, 'source_revision' => $record->source_revision,
                    'level' => (int) $definition->level, 'element' => (int) $definition->element, 'premium' => (bool) $definition->premium];
            }
            $snapshot = ['profile' => 'nl-owned-basic-combat-v1', 'user_id' => (int) $account->id, 'revision' => StateRevision::current((int) $account->id),
                'character' => ['id' => (int) $character->id, 'name' => $character->name, 'rank' => (int) $character->rank, 'equipment_skills' => implode(',', $ids)] + $stats,
                'skills' => $skills, 'scope' => 'Trusted server input only; mechanics binding and current-state admission still required.'];
            if ($withEquipment) $snapshot['equipment'] = $equipment;
            return $snapshot;
        }, 3);
    }

    /** Re-read at start. This does not reserve a battle or block future equipment changes. */
    public function assertCurrent(User $actor, array $snapshot): void
    {
        abort_unless(isset($snapshot['character']['id']) && is_int($snapshot['character']['id']), 422);
        $current = $this->prepare($actor, $snapshot['character']['id']);
        abort_unless($current === $snapshot, 409, 'Data combat berubah; ambil snapshot baru.');
    }

    public function assertEquippedCurrent(User $actor, array $snapshot): void
    {
        abort_unless(isset($snapshot['character']['id']) && is_int($snapshot['character']['id']), 422);
        $current = $this->prepareEquipped($actor, $snapshot['character']['id']);
        abort_unless(SkillBlueprint::json($current) === SkillBlueprint::json($snapshot), 409, 'Data equipment atau versi mekanik berubah.');
    }
}
