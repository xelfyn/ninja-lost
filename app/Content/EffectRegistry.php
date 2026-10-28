<?php

declare(strict_types=1);

namespace App\Content;

/** Explicit, narrow authoring vocabulary; presence here is not full native-client parity. */
final class EffectRegistry
{
    public const VERSION = 'nl-studio-effects-v1';
    public const RULES = [
        'stun' => ['label' => 'Stun', 'target' => 'enemy', 'type' => 'Debuff', 'calc' => 'number', 'min_duration' => 1, 'max_duration' => 5, 'min_amount' => 0, 'max_amount' => 0, 'unit' => 'tanpa nilai', 'timing' => 'Melewati giliran target; durasi dihitung pada giliran target.'],
        'burn' => ['label' => 'Burn', 'target' => 'enemy', 'type' => 'Debuff', 'calc' => 'percent', 'min_duration' => 1, 'max_duration' => 10, 'min_amount' => 1, 'max_amount' => 50, 'unit' => '% HP maksimum per giliran target', 'timing' => 'Damage berkala pada awal giliran target.'],
        'heal' => ['label' => 'Heal', 'target' => 'self', 'type' => 'Buff', 'calc' => 'percent', 'min_duration' => 0, 'max_duration' => 0, 'min_amount' => 1, 'max_amount' => 100, 'unit' => '% HP maksimum pemakai', 'timing' => 'Pemulihan langsung; tetap mengikuti aturan healing-block engine.'],
        'purify' => ['label' => 'Purify', 'target' => 'self', 'type' => 'Buff', 'calc' => 'number', 'min_duration' => 0, 'max_duration' => 0, 'min_amount' => 0, 'max_amount' => 0, 'unit' => 'tanpa nilai', 'timing' => 'Membersihkan debuff yang dapat dibersihkan, langsung.'],
        'disperse' => ['label' => 'Disperse', 'target' => 'enemy', 'type' => 'Debuff', 'calc' => 'number', 'min_duration' => 0, 'max_duration' => 0, 'min_amount' => 0, 'max_amount' => 0, 'unit' => 'tanpa nilai', 'timing' => 'Menghapus buff lawan yang dapat dihapus, bila hit tidak dihindari.'],
    ];

    public static function options(): array
    {
        return array_map(fn ($rule) => $rule['label'], self::RULES);
    }

    public static function compile(array $effect): array
    {
        $rule = self::RULES[$effect['code']];
        return ['effect' => $effect['code'], 'effect_name' => $rule['label'], 'target' => $rule['target'], 'type' => $rule['type'],
            'duration' => $effect['duration'], 'chance' => $effect['chance'], 'amount' => $effect['amount'], 'calc_type' => $rule['calc'],
            'amount_hp' => 0, 'amount_cp' => 0, 'reduce_type' => 'MAX', 'passive' => false, 'no_disperse' => false];
    }
}
