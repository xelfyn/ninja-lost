<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\Character;
use App\Services\Content\GameAssetRegistry;
use Illuminate\Support\Facades\DB;

final class PlayerViewContract
{
    public function __construct(private readonly GameAssetRegistry $assets)
    {
    }

    public function character(int $userId, int $characterId): array
    {
        $character = DB::table('characters')
            ->where('id', $characterId)
            ->where('user_id', $userId)
            ->first();

        abort_unless($character, 404);

        $tokens = (int) DB::table('users')->where('id', $userId)->value('tokens');

        $equipment = [
            'weapon' => $this->equipmentSlot('weapon', $character->equipment_weapon),
            'clothing' => $this->equipmentSlot('clothing', $character->equipment_clothing),
            'back' => $this->equipmentSlot('back', $character->equipment_back),
            'accessory' => $this->equipmentSlot('accessory', $character->equipment_accessory),
        ];

        $skillIds = $this->parseList($character->equipment_skills);
        $skills = $this->skills($skillIds);

        $petId = $this->stringOrNull($character->equipment_pet);
        $pet = $petId === null ? null : [
            'id' => $petId,
            'asset' => $this->assets->find('pet', $petId)
                ?? $this->assets->placeholder('pet', $petId),
        ];

        $appearanceIssues = [];
        if ($this->stringOrNull($character->hair_style) === null) {
            $appearanceIssues[] = 'hair_style_missing';
        }
        // The classic client/server contract includes a face selection but the
        // current Ninja Lost character schema does not. Do not silently invent one.
        $appearanceIssues[] = 'face_schema_gap';

        return [
            'character' => [
                'id' => (int) $character->id,
                'name' => (string) $character->name,
                'level' => (int) $character->level,
                'xp' => (int) $character->xp,
                'rank' => [
                    'code' => (int) $character->rank,
                    'label' => $this->rankLabel((int) $character->rank),
                ],
                'gold' => (int) $character->gold,
                'tokens' => $tokens,
                'elements' => [
                    'wind' => (int) $character->point_wind,
                    'fire' => (int) $character->point_fire,
                    'lightning' => (int) $character->point_lightning,
                    'water' => (int) $character->point_water,
                    'earth' => (int) $character->point_earth,
                ],
            ],
            'appearance' => [
                'render_status' => $appearanceIssues === [] ? 'ready_for_compositor' : 'incomplete',
                'renderable' => $appearanceIssues === [],
                'issues' => $appearanceIssues,
                'base' => [
                    'gender' => [
                        'status' => 'mapped',
                        'value' => (int) $character->gender,
                    ],
                    'skin_color' => [
                        'status' => $this->stringOrNull($character->skin_color) === null ? 'missing' : 'mapped',
                        'value' => $this->stringOrNull($character->skin_color),
                    ],
                    'hair' => [
                        'status' => $this->stringOrNull($character->hair_style) === null ? 'missing' : 'mapped_metadata_only',
                        'source_value' => $this->stringOrNull($character->hair_style),
                        'color_value' => $this->stringOrNull($character->hair_color),
                    ],
                    'face' => [
                        'status' => 'schema_gap',
                        'source_value' => null,
                    ],
                ],
                'equipment' => $equipment,
            ],
            'equipment' => $equipment,
            'skills' => [
                'equipped' => $skills,
                'equipped_count' => count($skills),
            ],
            'systems' => [
                'talents' => [
                    'slots' => array_values(array_filter([
                        $this->stringOrNull($character->talent_1),
                        $this->stringOrNull($character->talent_2),
                        $this->stringOrNull($character->talent_3),
                    ])),
                    'learned' => $this->parseList($character->talent_skills),
                ],
                'division_class' => [
                    'configured' => $this->stringOrNull($character->class) !== null,
                    'value' => $this->stringOrNull($character->class),
                    'evidence' => [
                        'level' => (int) $character->level,
                        'special_jounin_claimed' => (bool) $character->special_jounin_claimed,
                    ],
                ],
                'senjutsu' => [
                    'type' => $this->stringOrNull($character->senjutsu_type),
                    'learned' => $this->parseList($character->senjutsu_skills),
                    'equipped' => $this->parseList($character->senjutsu_equipped_skills),
                ],
                'pet' => $pet,
            ],
        ];
    }

    private function equipmentSlot(string $kind, mixed $raw): array
    {
        $id = $this->stringOrNull($raw);

        if ($id === null) {
            return [
                'id' => null,
                'asset' => null,
            ];
        }

        return [
            'id' => $id,
            'asset' => $this->assets->find($kind, $id)
                ?? $this->assets->placeholder($kind, $id),
        ];
    }

    private function skills(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $definitions = DB::table('skills')
            ->whereIn('skill_id', $ids)
            ->get(['skill_id', 'name', 'level', 'element'])
            ->keyBy('skill_id');

        $rows = [];

        foreach ($ids as $id) {
            $definition = $definitions->get($id);
            $rows[] = [
                'id' => $id,
                'name' => $definition?->name,
                'level' => $definition ? (int) $definition->level : null,
                'element' => $definition ? (int) $definition->element : null,
                'definition_status' => $definition ? 'available' : 'missing',
                'asset' => $this->assets->find('skill', $id)
                    ?? $this->assets->placeholder('skill', $id),
            ];
        }

        return $rows;
    }

    private function parseList(mixed $raw): array
    {
        $value = $this->stringOrNull($raw);

        if ($value === null) {
            return [];
        }

        if (str_starts_with($value, '[')) {
            try {
                $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $this->normalizeList($decoded);
                }
            } catch (\Throwable) {
                // Fall back to the legacy comma-delimited representation.
            }
        }

        return $this->normalizeList(explode(',', $value));
    }

    private function normalizeList(array $values): array
    {
        $out = [];

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $normalized = trim((string) $value);
            if ($normalized === '' || in_array($normalized, $out, true)) {
                continue;
            }

            $out[] = $normalized;
        }

        return $out;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function rankLabel(int $rank): string
    {
        return match ($rank) {
            Character::RANK_GENIN => 'Genin',
            Character::RANK_CHUNIN => 'Chunin',
            Character::RANK_JOUNIN => 'Jounin',
            Character::RANK_SPECIAL_JOUNIN => 'Special Jounin',
            Character::RANK_SANNIN => 'Sannin',
            Character::RANK_KAGE => 'Kage',
            default => 'Unknown Rank',
        };
    }
}
