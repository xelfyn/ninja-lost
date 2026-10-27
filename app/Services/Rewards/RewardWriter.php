<?php

namespace App\Services\Rewards;

use App\Support\Rewards\RewardRules;
use Illuminate\Support\Facades\DB;

class RewardWriter
{
    /** Called only while the account, character and relevant catalog rows are locked. */
    public function apply(object $account, object $character, array $line, ?object $catalog): array
    {
        $type = $line['type'];
        $quantity = $line['quantity'];
        $key = $catalog?->content_key ?? '';
        if (in_array($type, ['tokens', 'gold'], true)) {
            $table = $type === 'tokens' ? 'users' : 'characters';
            $owner = $type === 'tokens' ? $account : $character;
            $before = (int) $owner->{$type};
            if ($before < 0 || $before > RewardRules::BALANCE_MAX - $quantity) {
                RewardRules::reject('Batas saldo terlampaui; tidak ada hadiah diterbitkan.');
            }
            DB::table($table)->where('id', $owner->id)->update([$type => $before + $quantity, 'updated_at' => now()]);
            $owner->{$type} = $before + $quantity;
            return ['type' => $type, 'key' => '', 'quantity' => $quantity, 'before' => $before, 'after' => $before + $quantity];
        }
        [$definitionTable, $column, $table] = RewardRules::CONTENT[$type];
        $definition = DB::table($definitionTable)->where($column, $key)->first();
        if (!$definition) {
            RewardRules::reject('Definisi konten tidak tersedia.');
        }
        $existing = DB::table($table)->where('character_id', $character->id)->where($column, $key)->lockForUpdate()->get();
        if ($existing->count() > 1) {
            RewardRules::reject('Inventory memiliki duplikasi lama; perlu audit, bukan reset otomatis.');
        }
        if ($type !== 'item' && $existing->isNotEmpty()) {
            RewardRules::reject('Karakter sudah memiliki skill/pet ini; pilih hadiah lain.');
        }
        $before = $existing->isEmpty() ? 0 : (int) $existing->first()->quantity;
        if ($before < 0 || $before > RewardRules::BALANCE_MAX - $quantity) {
            RewardRules::reject('Batas jumlah inventory terlampaui.');
        }
        if ($existing->isNotEmpty()) {
            DB::table($table)->where('id', $existing->first()->id)->update(['quantity' => $before + $quantity, 'updated_at' => now()]);
        } else {
            $values = ['character_id' => $character->id, $column => $key, 'created_at' => now(), 'updated_at' => now()];
            if ($type === 'item') {
                $values += ['quantity' => $quantity, 'category' => $definition->category];
            } elseif ($type === 'pet') {
                $values += ['name' => $definition->name, 'level' => 1, 'xp' => 0];
            }
            DB::table($table)->insert($values);
        }
        return ['type' => $type, 'key' => $key, 'quantity' => $quantity, 'before' => $before, 'after' => $before + $quantity];
    }
}
