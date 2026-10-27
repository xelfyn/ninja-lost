<?php

declare(strict_types=1);

namespace App\Services\Battles;

use App\Models\Character;
use App\Support\Rewards\RewardRules;
use Illuminate\Support\Facades\DB;

/** Reuses cumulative-XP/rank-cap semantics; no fallback threshold or client XP. */
final class BattleProgression
{
    public static function snapshot(object $character, int $reward): array
    {
        $cap = (new Character(['rank' => (int) $character->rank]))->getMaxLevel();
        $level = (int) $character->level; $xp = (int) $character->xp;
        if ($level < 1 || $level > $cap || $xp < 0 || $xp > RewardRules::BALANCE_MAX - $reward) { RewardRules::reject('Progression karakter tidak valid.'); }
        $curve = [];
        if ($reward > 0) {
            $rows = DB::table('x_p_s')->whereBetween('level', [$level, $cap])->orderBy('level')->sharedLock()->get();
            $previous = 0;
            foreach ($rows as $row) {
                $value = (int) $row->character_xp;
                if ($value < 1 || $value < $previous || $value > RewardRules::BALANCE_MAX) { RewardRules::reject('Kurva XP tidak valid.'); }
                $curve[(string) $row->level] = $value; $previous = $value;
            }
            for ($i = $level; $i <= $cap; $i++) { if (!isset($curve[$i])) { RewardRules::reject('Kurva XP belum lengkap.'); } }
            if ($level === $cap && $xp > $curve[$cap]) { RewardRules::reject('XP lama melebihi cap; perlu pemeriksaan operator.'); }
        }
        return ['level' => $level, 'xp' => $xp, 'rank' => (int) $character->rank, 'cap' => $cap, 'curve' => $curve, 'reward' => $reward];
    }

    public static function apply(object $character, array $snapshot): array
    {
        foreach (['xp', 'level', 'rank'] as $key) {
            abort_unless((int) $character->{$key} === (int) $snapshot[$key], 409, 'Progression berubah sejak battle dimulai.');
        }
        $before = (int) $character->xp; $level = (int) $character->level;
        $xp = $before + (int) $snapshot['reward'];
        if ($snapshot['reward'] > 0) {
            while ($level < $snapshot['cap'] && $xp >= $snapshot['curve'][$level]) { $level++; }
            if ($level >= $snapshot['cap']) { $xp = min($xp, $snapshot['curve'][$level]); }
        }
        if ($xp < $before || $xp > RewardRules::BALANCE_MAX) { RewardRules::reject('Hasil progression di luar batas.'); }
        DB::table('characters')->where('id', $character->id)->update(['xp' => $xp, 'level' => $level, 'updated_at' => now()]);
        return ['type' => 'xp', 'key' => '', 'quantity' => $xp - $before, 'before' => $before, 'after' => $xp,
            'level_before' => (int) $character->level, 'level_after' => $level, 'requested' => (int) $snapshot['reward']];
    }
}
