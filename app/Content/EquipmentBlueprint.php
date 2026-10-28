<?php

declare(strict_types=1);

namespace App\Content;

use Illuminate\Support\Facades\Validator;

/** Versioned, bounded equipment definition. No script or arbitrary expression execution. */
final class EquipmentBlueprint
{
    public const PROFILE = 'nl-studio-equipment-v1';
    public const SLOTS = ['weapon' => 'wpn', 'back' => 'back', 'clothing' => 'set', 'accessory' => 'accessory'];
    public const COLUMNS = ['weapon' => 'equipment_weapon', 'back' => 'equipment_back', 'clothing' => 'equipment_clothing', 'accessory' => 'equipment_accessory'];

    public static function rules(): array
    {
        return json_decode(file_get_contents(base_path('combat/data/equipment-stats-v1.json')), true, 16, JSON_THROW_ON_ERROR);
    }

    public static function modifiers(array $input): array
    {
        if (!array_is_list($input) || count($input) > 14) SkillBlueprint::reject('Modifier harus daftar maksimal 14 baris.');
        $rules = self::rules(); $seen = []; $out = [];
        foreach ($input as $line) {
            if (!is_array($line) || count($line) !== 3 || array_diff(array_keys($line), ['stat', 'unit', 'amount'])) SkillBlueprint::reject('Field modifier tidak didukung.');
            $stat = $line['stat'] ?? null; $unit = $line['unit'] ?? null;
            if (!is_string($stat) || !is_string($unit) || !isset($rules['stats'][$stat]['units'][$unit])) SkillBlueprint::reject('Stat atau satuan tidak didukung. Critical/dodge memakai poin persentase.');
            $amount = self::integer($line['amount'] ?? null, 0, $rules['stats'][$stat]['units'][$unit]);
            $key = $stat.':'.$unit;
            if (isset($seen[$key])) SkillBlueprint::reject('Stat dan satuan duplikat dalam satu sumber bonus.');
            $seen[$key] = true; $out[] = ['stat' => $stat, 'unit' => $unit, 'amount' => $amount];
        }
        usort($out, fn ($a, $b) => strcmp($a['stat'].':'.$a['unit'], $b['stat'].':'.$b['unit']));
        return $out;
    }

    public static function integer(mixed $value, int $minimum, int $maximum): int
    {
        if (!(is_int($value) || (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value))) || $value < $minimum || $value > $maximum) SkillBlueprint::reject('Bilangan bulat berada di luar batas profil.');
        return (int) $value;
    }

    public static function normalize(array $input): array
    {
        if (strlen(SkillBlueprint::json($input)) > 65536) SkillBlueprint::reject('Paket equipment terlalu besar.');
        Validator::make(['d' => $input], [
            'd' => ['required', 'array:id,name,lore,pieces,bonuses,source_url,rights_basis,rights_evidence'],
            'd.id' => ['required', 'string', 'regex:/\Agear_nl_[a-z0-9_]{1,60}\z/D'],
            'd.name' => ['required', 'string', 'max:120'], 'd.lore' => ['nullable', 'string', 'max:500'],
            'd.pieces' => ['required', 'array', 'min:1', 'max:4'],
            'd.pieces.*' => ['required', 'array:id,name,slot,level,premium,visual_key,supply_limit,modifiers'],
            'd.pieces.*.id' => ['required', 'string', 'max:100', 'distinct:strict'],
            'd.pieces.*.name' => ['required', 'string', 'max:120'],
            'd.pieces.*.slot' => ['required', 'in:weapon,back,clothing,accessory', 'distinct:strict'],
            'd.pieces.*.level' => ['required', 'integer', 'between:1,200'],
            'd.pieces.*.premium' => ['required', 'boolean'],
            'd.pieces.*.visual_key' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_]{1,100}\z/D'],
            'd.pieces.*.supply_limit' => ['nullable', 'integer', 'between:1,2147483647'],
            'd.pieces.*.modifiers' => ['present', 'array', 'max:14'],
            'd.bonuses' => ['present', 'array', 'max:3'],
            'd.bonuses.*' => ['required', 'array:required,modifiers'],
            'd.bonuses.*.required' => ['required', 'integer', 'between:2,4', 'distinct'],
            'd.bonuses.*.modifiers' => ['required', 'array', 'min:1', 'max:14'],
            'd.source_url' => ['required', 'url', 'starts_with:https://', 'max:500'],
            'd.rights_basis' => ['required', 'in:self_authored,permission_documented'],
            'd.rights_evidence' => ['required', 'string', 'min:10', 'max:2000'],
        ])->validate();
        if (!array_is_list($input['pieces']) || !array_is_list($input['bonuses'])) SkillBlueprint::reject('Bagian dan bonus harus berupa daftar.');
        $pieces = [];
        foreach ($input['pieces'] as $p) {
            if (!preg_match('/\A'.self::SLOTS[$p['slot']].'_nl_[a-z0-9_]{1,60}\z/D', $p['id'])) SkillBlueprint::reject('Prefix ID tidak cocok dengan slot equipment.');
            $name = trim($p['name']); if ($name === '') SkillBlueprint::reject('Nama bagian wajib diisi.');
            $pieces[] = ['id' => $p['id'], 'name' => $name, 'slot' => $p['slot'], 'level' => self::integer($p['level'], 1, 200),
                'premium' => (bool) $p['premium'], 'visual_key' => ($p['visual_key'] ?? null) ?: null,
                'supply_limit' => isset($p['supply_limit']) ? self::integer($p['supply_limit'], 1, 2147483647) : null,
                'modifiers' => self::modifiers($p['modifiers'])];
        }
        usort($pieces, fn ($a, $b) => strcmp($a['id'], $b['id']));
        $bonuses = [];
        foreach ($input['bonuses'] as $b) $bonuses[] = ['required' => self::integer($b['required'], 2, count($pieces)), 'modifiers' => self::modifiers($b['modifiers'])];
        usort($bonuses, fn ($a, $b) => $a['required'] <=> $b['required']);
        $name = trim($input['name']); if ($name === '') SkillBlueprint::reject('Nama paket wajib diisi.');
        return ['id' => $input['id'], 'name' => $name, 'lore' => trim($input['lore'] ?? ''), 'pieces' => $pieces, 'bonuses' => $bonuses,
            'source_url' => $input['source_url'], 'rights_basis' => $input['rights_basis'], 'rights_evidence' => trim($input['rights_evidence'])];
    }

    public static function tooltip(array $d): string
    {
        $rules = self::rules(); $lines = ['Bonus set bersifat kumulatif; satu bagian per slot dihitung satu kali.'];
        $describe = static function (array $mods) use ($rules): string {
            return implode('; ', array_map(fn ($m) => $rules['stats'][$m['stat']]['label'].' +'.$m['amount'].' '.match ($m['unit']) {'flat' => 'poin', 'percent' => '% relatif setelah bonus tetap', 'points' => 'poin persentase'}, $mods));
        };
        foreach ($d['pieces'] as $p) $lines[] = $p['name'].' ['.$p['slot'].']: '.($describe($p['modifiers']) ?: 'tanpa bonus stat').'.';
        foreach ($d['bonuses'] as $b) $lines[] = $b['required'].' bagian: '.$describe($b['modifiers']).'.';
        return implode("\n", $lines);
    }

    public static function compile(array $definition, string $releaseId): array
    {
        $d = self::normalize($definition);
        return ['schema' => 1, 'profile' => self::PROFILE, 'stat_registry' => self::rules()['version'],
            'revision' => 'nl-equipment:'.$releaseId, 'definition' => $d, 'tooltip' => self::tooltip($d),
            'client_ready' => false, 'visual_status' => 'reference_only_not_client_verified'];
    }
}
