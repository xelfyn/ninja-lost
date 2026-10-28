<?php

declare(strict_types=1);

namespace App\Content;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SkillBlueprint
{
    public const PROFILE = 'nl-studio-skill-v1';

    public static function normalize(array $input): array
    {
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 32768) self::reject('Definisi terlalu besar.');
        Validator::make(['d' => $input], [
            'd' => ['required', 'array:id,name,lore,element,level,premium,damage,cp_cost,cooldown,animation_key,hit_frames,effects,source_url,rights_basis,rights_evidence,supply_limit'],
            'd.id' => ['required', 'string', 'regex:/\Askill_nl_[a-z0-9_]{1,60}\z/D'],
            'd.name' => ['required', 'string', 'max:120'], 'd.lore' => ['nullable', 'string', 'max:500'],
            'd.element' => ['required', 'integer', 'between:1,5'], 'd.level' => ['required', 'integer', 'between:1,200'],
            'd.premium' => ['required', 'boolean'], 'd.damage' => ['required', 'integer', 'between:1,1000000'],
            'd.cp_cost' => ['required', 'integer', 'between:0,1000000'], 'd.cooldown' => ['required', 'integer', 'between:0,50'],
            'd.animation_key' => ['nullable', 'string', 'regex:/\Askill_[A-Za-z0-9_-]{1,80}\z/D'],
            'd.hit_frames' => ['present', 'array', 'max:8'], 'd.hit_frames.*' => ['required', 'integer', 'between:0,10000', 'distinct'],
            'd.effects' => ['present', 'array', 'max:5'], 'd.effects.*' => ['required', 'array:code,chance,duration,amount'],
            'd.effects.*.code' => ['required', 'in:'.implode(',', array_keys(EffectRegistry::RULES)), 'distinct:strict'],
            'd.effects.*.chance' => ['required', 'integer', 'between:0,100'],
            'd.effects.*.duration' => ['required', 'integer', 'between:0,10'],
            'd.effects.*.amount' => ['required', 'integer', 'between:0,100'],
            'd.source_url' => ['required', 'url', 'starts_with:https://', 'max:500'],
            'd.rights_basis' => ['required', 'in:self_authored,permission_documented'],
            'd.rights_evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'd.supply_limit' => ['nullable', 'integer', 'between:1,2147483647'],
        ])->validate();
        if (!array_is_list($input['hit_frames']) || !array_is_list($input['effects'])) self::reject('Efek dan frame harus berupa daftar terurut.');
        $effects = [];
        foreach ($input['effects'] as $effect) {
            $rule = EffectRegistry::RULES[$effect['code']];
            $duration = (int) $effect['duration']; $amount = (int) $effect['amount'];
            if ($duration < $rule['min_duration'] || $duration > $rule['max_duration'] || $amount < $rule['min_amount'] || $amount > $rule['max_amount']) {
                self::reject($rule['label'].' memerlukan durasi '.$rule['min_duration'].'–'.$rule['max_duration'].' dan nilai '.$rule['min_amount'].'–'.$rule['max_amount'].' ('.$rule['unit'].').');
            }
            $effects[] = ['code' => $effect['code'], 'chance' => (int) $effect['chance'], 'duration' => $duration, 'amount' => $amount];
        }
        $animation = $input['animation_key'] ?? null;
        $frames = array_map('intval', $input['hit_frames']);
        if (count($frames) > 1) self::reject('Multi-hit belum didukung profil ini. Pakai satu frame atau kosong.');
        if (!$animation && $frames) self::reject('Frame memerlukan referensi animasi.');
        return ['id' => $input['id'], 'name' => trim($input['name']), 'lore' => trim($input['lore'] ?? ''),
            'element' => (int) $input['element'], 'level' => (int) $input['level'], 'premium' => (bool) $input['premium'],
            'damage' => (int) $input['damage'], 'cp_cost' => (int) $input['cp_cost'], 'cooldown' => (int) $input['cooldown'],
            'animation_key' => $animation ?: null, 'hit_frames' => $frames, 'effects' => $effects,
            'source_url' => $input['source_url'], 'rights_basis' => $input['rights_basis'], 'rights_evidence' => trim($input['rights_evidence']),
            'supply_limit' => isset($input['supply_limit']) ? (int) $input['supply_limit'] : null];
    }

    public static function tooltip(array $d): string
    {
        $lines = ['Damage dasar '.$d['damage'].'; CP '.$d['cp_cost'].'; cooldown '.$d['cooldown'].' giliran.'];
        foreach ($d['effects'] as $e) {
            $r = EffectRegistry::RULES[$e['code']];
            $text = $r['label'].' → '.($r['target'] === 'self' ? 'pemakai' : 'lawan').'; peluang '.$e['chance'].'%';
            if ($e['amount'] > 0) $text .= '; '.$e['amount'].' '.$r['unit'];
            if ($e['duration'] > 0) $text .= '; '.$e['duration'].' giliran target';
            $lines[] = $text.'. '.$r['timing'];
        }
        return implode("\n", $lines);
    }

    public static function compile(array $definition, string $releaseId): array
    {
        $d = self::normalize($definition);
        return ['schema' => 1, 'profile' => self::PROFILE, 'effect_registry' => EffectRegistry::VERSION, 'revision' => 'nl-studio:'.$releaseId,
            'definition' => $d, 'tooltip' => self::tooltip($d), 'client_ready' => false,
            'skills' => [['id' => $d['id'], 'name' => $d['name'], 'description' => self::tooltip($d), 'type' => (string) $d['element'],
                'level' => $d['level'], 'premium' => $d['premium'], 'damage' => $d['damage'], 'cp_cost' => $d['cp_cost'], 'cooldown' => $d['cooldown'],
                'target' => 'Single', 'multi_hit' => false, 'anims' => ['hit' => $d['hit_frames']]]],
            'effects' => [['skill_id' => $d['id'], 'skill_effect' => array_map([EffectRegistry::class, 'compile'], $d['effects'])]],
            'visual' => ['base_skill_id' => $d['animation_key'], 'hit_frames' => $d['hit_frames'], 'status' => 'reference_only_not_client_verified']];
    }

    public static function json(array $value): string
    {
        $sort = function ($v) use (&$sort) {
            if (!is_array($v)) return $v;
            if (!array_is_list($v)) ksort($v, SORT_STRING);
            return array_map($sort, $v);
        };
        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function reject(string $message): never
    {
        throw ValidationException::withMessages(['blueprint' => $message]);
    }
}
