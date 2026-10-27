<?php

declare(strict_types=1);

namespace App\Support\NSX;

/** Explicit role check shared by the panel and operations page. */
final class AdminPolicy
{
    public static function allows(mixed $accountType): bool
    {
        // Database drivers may hydrate an integer column as a numeric string.
        // Do not coerce arbitrary strings such as "99anything" to administrator.
        return $accountType === 99 || $accountType === '99';
    }
}
