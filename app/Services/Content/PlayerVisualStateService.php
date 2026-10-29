<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\Character;

/**
 * Builds the web-facing visual state for one owned character without inventing
 * any game art. Full-body composition is intentionally blocked until the
 * classic appearance contract (including face) is mapped and verified.
 */
final class PlayerVisualStateService
{
    private const EQUIPMENT_KINDS = [
        'weapon' => ['column' => 'equipment_weapon', 'asset_kind' => 'weapon'],
        'clothing' => ['column' => 'equipment_clothing', 'asset_kind' => 'clothing'],
        'back' => ['column' => 'equipment_back', 'asset_kind' => 'back'],
        'accessory' => ['column' => 'equipment_accessory', 'asset_kind' => 'accessory'],
    ];

    public function __construct(private readonly GameAssetRegistry $assets)
    {
    }

    public function forCharacter(Character|object $character): array
    {
        $equipment = [];

        foreach (self::EQUIPMENT_KINDS as $slot => $definition) {
            $sourceId = trim((string) ($character->{$definition['column']} ?? ''));
            $equipment[$slot] = [
                'slot' => $slot,
                'content_key' => $sourceId !== '' ? $sourceId : null,
                'asset' => $sourceId !== ''
                    ? ($this->assets->find($definition['asset_kind'], $sourceId)
                        ?? $this->assets->placeholder($definition['asset_kind'], $sourceId))
                    : null,
            ];
        }

        $pet = trim((string) ($character->equipment_pet ?? ''));
        $hair = trim((string) ($character->hair_style ?? ''));

        return [
            'character_id' => (int) $character->id,
            'appearance' => [
                'gender' => isset($character->gender) ? (int) $character->gender : null,
                'skin_color' => isset($character->skin_color) ? (string) $character->skin_color : null,
                'hair_style' => $hair !== '' ? $hair : null,
                'hair_color' => isset($character->hair_color) ? (string) $character->hair_color : null,
                // Classic-client parity requires a face identity. Ninja Lost does
                // not currently expose a verified equivalent, so never fabricate one.
                'face' => null,
            ],
            'equipment' => $equipment,
            'pet' => [
                'content_key' => $pet !== '' ? $pet : null,
                'asset' => $pet !== ''
                    ? ($this->assets->find('pet', $pet) ?? $this->assets->placeholder('pet', $pet))
                    : null,
            ],
            'composition' => [
                'status' => 'incomplete',
                'url' => null,
                'missing_requirements' => ['face'],
                'reason' => 'Classic character face mapping is not yet verified.',
            ],
        ];
    }
}
