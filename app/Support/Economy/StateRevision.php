<?php

declare(strict_types=1);

namespace App\Support\Economy;

use App\Support\Rewards\RewardRules;
use Illuminate\Support\Facades\DB;

final class StateRevision
{
    public static function current(int $userId): int
    {
        return (int) (DB::table('nl_account_revisions')->where('user_id', $userId)->value('revision') ?? 0);
    }

    /** Caller must hold the account lock in the same transaction as the mutation. */
    public static function bump(int $userId): int
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('A revision update requires an active transaction and account lock.');
        }
        $current = self::current($userId);
        if ($current >= 4294967295) {
            RewardRules::reject('Batas revision tercapai; perubahan dibatalkan.');
        }
        DB::table('nl_account_revisions')->updateOrInsert(['user_id' => $userId], ['revision' => $current + 1]);
        return $current + 1;
    }
}
