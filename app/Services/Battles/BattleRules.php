<?php

declare(strict_types=1);

namespace App\Services\Battles;

use App\Models\User;
use App\Services\Shop\ShopCatalog;
use App\Support\Rewards\RewardRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Internal operator service. No default rules, UI endpoint, or legacy route activation. */
final class BattleRules
{
    public function create(User $actor, array $input): int
    {
        Validator::make(['rule' => $input], [
            'rule' => ['required', 'array:slug,mission_id,minimum_seconds,ttl_seconds'],
            'rule.slug' => ['required', 'string', 'regex:/\A[a-z0-9-]{1,80}\z/D'],
            'rule.mission_id' => ['required', 'string', 'regex:/\A[A-Za-z0-9_.-]{1,100}\z/D'],
            'rule.minimum_seconds' => ['required', 'integer', 'min:1', 'max:300'],
            'rule.ttl_seconds' => ['required', 'integer', 'min:60', 'max:1800'],
        ])->validate();
        if ((int) $input['minimum_seconds'] >= (int) $input['ttl_seconds']) { RewardRules::reject('Durasi minimum harus lebih kecil dari TTL.'); }
        return $this->staff($actor, function () use ($actor, $input) {
            $mission = self::mission($input['mission_id']);
            if (DB::table('nl_battle_rules')->where('slug', $input['slug'])->exists()) { RewardRules::reject('Slug rule telah digunakan.'); }
            $id = DB::table('nl_battle_rules')->insertGetId([
                'slug' => $input['slug'], 'mission_id' => $input['mission_id'], 'definition_hash' => ShopCatalog::fingerprint($mission),
                'minimum_seconds' => (int) $input['minimum_seconds'], 'ttl_seconds' => (int) $input['ttl_seconds'],
                'active' => false, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($actor, $id, 'battle.rule.create', ['mission_id' => $input['mission_id'], 'active' => false]);
            return $id;
        });
    }

    public function setActive(User $actor, int $id, bool $active): void
    {
        $this->staff($actor, function () use ($actor, $id, $active) {
            $rule = DB::table('nl_battle_rules')->where('id', $id)->lockForUpdate()->first(); abort_unless($rule, 404);
            if ($active && !hash_equals($rule->definition_hash, ShopCatalog::fingerprint(self::mission($rule->mission_id)))) {
                RewardRules::reject('Definisi misi berubah; tinjau dan buat rule baru.');
            }
            DB::table('nl_battle_rules')->where('id', $id)->update(['active' => $active, 'updated_at' => now()]);
            $this->audit($actor, $id, 'battle.rule.status', ['active' => $active]);
        });
    }

    public static function mission(string $key): object
    {
        $mission = DB::table('missions')->where('mission_id', $key)->sharedLock()->first();
        if (!$mission || $key === 'msn_fishing' || (int) $mission->req_lvl < 1 || (int) $mission->req_lvl > 85
            || (int) $mission->gold < 0 || (int) $mission->xp < 0 || (int) $mission->gold > 100000000 || (int) $mission->xp > 100000000
            || ((int) $mission->gold === 0 && (int) $mission->xp === 0)) {
            RewardRules::reject('Misi tidak didukung atau reward di luar batas evaluasi.');
        }
        return $mission;
    }

    private function staff(User $actor, callable $operation): mixed
    {
        abort_unless(app()->environment(['local', 'testing']), 403, 'Evaluation only.');
        return DB::transaction(function () use ($actor, $operation) {
            $row = DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            abort_unless($row && (int) $row->account_type === 99, 403);
            return $operation();
        }, 3);
    }
    private function audit(User $actor, int $id, string $action, array $details): void
    {
        DB::table('nl_operations_audit')->insert(['actor_id' => $actor->id, 'action' => $action, 'subject' => 'battle-rule:'.$id, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
