<?php

declare(strict_types=1);

namespace App\Support\Economy;

final class EvaluationSurface
{
    public const RESOURCES = [
        \App\Filament\Resources\Items\ItemResource::class,
        \App\Filament\Resources\Skills\SkillResource::class,
        \App\Filament\Resources\Pets\PetResource::class,
        \App\Filament\Resources\Missions\MissionResource::class,
    ];
    public const PAGES = [
        \Filament\Pages\Dashboard::class,
        \App\Filament\Pages\NSXOperationsCenter::class,
        \App\Filament\Pages\GameSettings::class,
        \App\Filament\Pages\RewardCampaigns::class,
        \App\Filament\Pages\ContentRegistry::class,
        \App\Filament\Pages\StaffGrants::class,
        \App\Filament\Pages\ShopOffers::class,
    ];

    public static function componentAllowed(string $class): bool
    {
        if (str_starts_with($class, 'App\\Player\\')) {
            return in_array($class, [\App\Player\Pages\PlayerHome::class, \App\Player\Pages\PlayerCatalog::class, \App\Player\Pages\PlayerShop::class], true);
        }
        if (!str_starts_with($class, 'App\\Filament\\')) { return true; }
        if (in_array($class, self::PAGES, true)) { return true; }
        foreach (self::RESOURCES as $resource) {
            $prefix = substr($resource, 0, strrpos($resource, '\\') + 1);
            if (str_starts_with($class, $prefix)) { return true; }
        }
        return false;
    }

    public static function commandAllowed(string $class): bool
    {
        return !str_starts_with($class, 'App\\Console\\Commands\\')
            || $class === \App\Console\Commands\CreateEvaluationAdmin::class;
    }
}
