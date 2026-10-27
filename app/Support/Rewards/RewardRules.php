<?php

namespace App\Support\Rewards;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RewardRules
{
    public const BALANCE_MAX = 2147483647; // Existing users.tokens / characters.gold are signed INT.
    public const CONTENT = [
        'item' => ['items', 'item_id', 'character_items'],
        'skill' => ['skills', 'skill_id', 'character_skills'],
        'pet' => ['pets', 'pet_id', 'character_pets'],
    ];

    public static function reject(string $message): never
    {
        throw ValidationException::withMessages(['reward' => $message]);
    }

    public static function codeHash(string $code): string
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/\A[A-Z0-9-]{12,64}\z/D', $code)) {
            self::reject('Kode tidak valid atau tidak tersedia.');
        }
        return hash('sha256', $code);
    }

    public static function choices(array $choices): array
    {
        Validator::make(['choices' => $choices], [
            'choices' => ['required', 'array', 'min:1', 'max:8'],
            'choices.*' => ['required', 'array:key,label,rewards'],
            'choices.*.key' => ['required', 'string', 'regex:/\A[a-z0-9_-]{1,40}\z/D', 'distinct:strict'],
            'choices.*.label' => ['required', 'string', 'max:80'],
            'choices.*.rewards' => ['required', 'array', 'min:1', 'max:12'],
            'choices.*.rewards.*' => ['required', 'array:type,key,quantity'],
            'choices.*.rewards.*.type' => ['required', 'in:tokens,gold,item,skill,pet'],
            'choices.*.rewards.*.key' => ['nullable', 'string', 'max:100'],
            'choices.*.rewards.*.quantity' => ['required', 'integer', 'min:1', 'max:100000000'],
        ])->validate();
        $result = [];
        foreach ($choices as $choice) {
            $seen = [];
            $lines = [];
            foreach ($choice['rewards'] as $line) {
                $type = $line['type'];
                $key = trim($line['key'] ?? '');
                $quantity = (int) $line['quantity'];
                if (isset(self::CONTENT[$type])) {
                    if (!preg_match('/\A[A-Za-z0-9_.-]{1,100}\z/D', $key)) {
                        self::reject('ID konten tidak valid.');
                    }
                    if ($quantity > 1000000 || (in_array($type, ['skill', 'pet'], true) && $quantity !== 1)) {
                        self::reject('Skill/pet hanya satu; jumlah item maksimal 1.000.000 per baris.');
                    }
                } elseif ($key !== '') {
                    self::reject('Reward mata uang tidak menerima ID konten.');
                }
                $identity = strtolower($type.':'.$key);
                if (isset($seen[$identity])) {
                    self::reject('Reward yang sama tidak boleh berulang dalam satu pilihan.');
                }
                $seen[$identity] = true;
                $lines[] = ['type' => $type, 'key' => $key, 'quantity' => $quantity];
            }
            $result[] = ['key' => $choice['key'], 'label' => $choice['label'], 'rewards' => $lines];
        }
        return $result;
    }
}
