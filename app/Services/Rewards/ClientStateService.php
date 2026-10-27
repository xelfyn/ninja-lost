<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Models\User;
use App\Support\Economy\StateRevision;
use App\Support\Rewards\RewardRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Internal integration contract, not a published game endpoint or battle-state validator. */
final class ClientStateService
{
    private const SLOTS = ['weapon' => 'equipment_weapon', 'back' => 'equipment_back', 'clothing' => 'equipment_clothing', 'accessory' => 'equipment_accessory'];

    public function snapshot(User $actor, int $characterId): array
    {
        return $this->locked($actor, $characterId, fn ($account, $character) => $this->state($account, $character));
    }

    public function saveEquipment(User $actor, int $characterId, array $payload): array
    {
        Validator::make(['state' => $payload], [
            'state' => ['required', 'array:revision,equipment'],
            'state.revision' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'state.equipment' => ['required', 'array:weapon,back,clothing,accessory', 'min:1'],
            'state.equipment.*' => ['nullable', 'string', 'max:100', 'regex:/\A[A-Za-z0-9_.-]*\z/D'],
        ])->validate();
        return $this->locked($actor, $characterId, function ($account, $character) use ($payload) {
            abort_unless((int) $payload['revision'] === StateRevision::current((int) $account->id), 409, 'State berubah. Ambil snapshot server baru sebelum menyimpan.');
            $selection = $payload['equipment'];
            uasort($selection, fn ($a, $b) => strcmp(strtolower($a ?? ''), strtolower($b ?? '')));
            $updates = [];
            foreach ($selection as $slot => $key) {
                $key = $key ?? '';
                if ($key !== '') {
                    $catalog = DB::table('nl_catalog')->where('kind', 'item')->where('content_key', $key)->lockForUpdate()->first();
                    $item = DB::table('items')->where('item_id', $key)->first();
                    $owned = DB::table('character_items')->where('character_id', $character->id)->where('item_id', $key)->lockForUpdate()->get();
                    if (!$catalog || !$catalog->enabled || !in_array($catalog->rights_basis, ['self_authored', 'permission_documented'], true) || !$item || $item->category !== $slot || (int) $item->level > (int) $character->level || ($item->premium && !in_array((int) $account->account_type, [1, 99], true)) || $owned->count() !== 1 || (int) $owned->first()->quantity < 1) {
                        RewardRules::reject('Equipment tidak dimiliki, tidak kompatibel dengan slot/level/akun, atau belum aktif.');
                    }
                }
                $updates[self::SLOTS[$slot]] = $key;
                $character->{self::SLOTS[$slot]} = $key;
            }
            DB::table('characters')->where('id', $character->id)->update($updates + ['updated_at' => now()]);
            $revision = StateRevision::bump((int) $account->id);
            DB::table('nl_operations_audit')->insert(['actor_id' => $account->id, 'action' => 'equipment.save', 'subject' => 'character:'.$character->id, 'details' => json_encode(['revision' => $revision, 'slots' => array_keys($selection)], JSON_THROW_ON_ERROR), 'created_at' => now()]);
            return $this->state($account, $character);
        });
    }

    private function locked(User $actor, int $characterId, callable $operation): array
    {
        abort_unless(app()->environment(['local', 'testing']), 403, 'Evaluation runtime only.');
        return DB::transaction(function () use ($actor, $characterId, $operation) {
            $account = DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            abort_unless($account && in_array((int) $account->account_type, [0, 1, 99], true), 403);
            $character = DB::table('characters')->where('id', $characterId)->where('user_id', $account->id)->lockForUpdate()->first();
            abort_unless($character, 404);
            return $operation($account, $character);
        }, 3);
    }

    private function state(object $account, object $character): array
    {
        $equipment = [];
        foreach (self::SLOTS as $slot => $column) { $equipment[$slot] = $character->{$column}; }
        return ['character_id' => (int) $character->id, 'revision' => StateRevision::current((int) $account->id), 'tokens' => (int) $account->tokens, 'gold' => (int) $character->gold, 'equipment' => $equipment];
    }
}
