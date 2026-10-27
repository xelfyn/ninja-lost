<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Support\Rewards\RewardRules;
use Illuminate\Support\Facades\DB;

/** Caller must already hold the relevant account and, for purchases, character/offer locks. */
final class ShopCatalog
{
    public static function locked(int $catalogId): array
    {
        if (DB::transactionLevel() < 1) { throw new \LogicException('Shop catalog requires a transaction.'); }
        $catalog = DB::table('nl_catalog')->where('id', $catalogId)->lockForUpdate()->first();
        if (!$catalog || !in_array($catalog->kind, ['item', 'skill'], true) || !$catalog->enabled
            || !in_array($catalog->rights_basis, ['self_authored', 'permission_documented'], true)) {
            RewardRules::reject('Katalog toko belum aktif, belum diizinkan, atau jenisnya belum didukung.');
        }
        [$table, $column] = RewardRules::CONTENT[$catalog->kind];
        $definitions = DB::table($table)->where($column, $catalog->content_key)->sharedLock()->limit(2)->get();
        if ($definitions->count() !== 1) { RewardRules::reject('Definisi konten hilang atau ambigu.'); }
        return [$catalog, $definitions->first()];
    }

    public static function fingerprint(object $definition): string
    {
        $data = get_object_vars($definition);
        unset($data['created_at'], $data['updated_at']);
        ksort($data, SORT_STRING);
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public static function requireUnchanged(object $offer, object $definition): void
    {
        if (!hash_equals($offer->definition_hash, self::fingerprint($definition))) {
            RewardRules::reject('Definisi konten berubah. Operator harus meninjau dan membuat penawaran baru.');
        }
    }

    /** Compute the legacy skill-element acquisition rule without writing before funds are checked. */
    public static function eligibility(object $account, object $character, object $catalog, object $definition): array
    {
        if ((int) $definition->level > (int) $character->level || ($definition->premium && !in_array((int) $account->account_type, [1, 99], true))) {
            RewardRules::reject('Level atau jenis akun belum memenuhi persyaratan konten.');
        }
        if ($catalog->kind !== 'skill' || !in_array((int) $definition->element, [1, 2, 3, 4, 5], true)) { return []; }
        $element = (int) $definition->element;
        $slots = ['element_1', 'element_2', 'element_3'];
        $owned = array_map(fn ($slot) => (int) $character->{$slot}, $slots);
        if (in_array($element, $owned, true)) { return []; }
        $maximum = in_array((int) $account->account_type, [1, 99], true) ? 3 : 2;
        if (count(array_filter($owned)) >= $maximum) { RewardRules::reject('Slot elemen karakter sudah penuh.'); }
        foreach ($slots as $slot) {
            if (!(int) $character->{$slot}) { return [$slot => $element]; }
        }
        RewardRules::reject('Tidak ada slot elemen tersedia.');
    }
}
