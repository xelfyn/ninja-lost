<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\NSX\AdminPolicy;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Reuses the installed panel, its resources, authorization and database. */
class NSXOperationsCenter extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-command-line';
    protected static ?string $navigationLabel = 'Pusat Operasi Ninja Lost';
    protected static ?string $title = 'Pusat Operasi Ninja Lost';
    protected static ?string $slug = 'nsx-operations';
    protected static ?int $navigationSort = -10;
    protected string $view = 'filament.pages.nsx-operations-center';

    public static function canAccess(): bool
    {
        return AdminPolicy::allows(Filament::auth()->user()?->account_type);
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $panel = Filament::getCurrentPanel();
        $modules = [];
        $unresolved = 0;

        foreach ($panel?->getResources() ?? [] as $resource) {
            try {
                if (!$resource::canAccess() || !array_key_exists('index', $resource::getPages())) {
                    continue;
                }
                $modules[] = [
                    'label' => strip_tags((string) $resource::getNavigationLabel()),
                    'url' => $resource::getUrl('index', panel: $panel->getId()),
                    'kind' => 'Data & pengelolaan',
                ];
            } catch (Throwable) {
                // A failed module is not silently reported as healthy.
                $unresolved++;
            }
        }
        foreach ($panel?->getPages() ?? [] as $page) {
            if ($page === static::class) {
                continue;
            }
            try {
                if (!$page::canAccess()) {
                    continue;
                }
                $modules[] = [
                    'label' => strip_tags((string) $page::getNavigationLabel()),
                    'url' => $page::getUrl(panel: $panel->getId()),
                    'kind' => 'Pengaturan',
                ];
            } catch (Throwable) {
                $unresolved++;
            }
        }
        usort($modules, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        // These are database totals, never concurrent-player or performance claims.
        $totals = ['Akun' => null, 'Karakter' => null, 'Definisi skill' => null];
        $databaseReachable = false;
        try {
            DB::select('SELECT 1');
            $databaseReachable = true;
            foreach (['Akun' => 'users', 'Karakter' => 'characters', 'Definisi skill' => 'skills'] as $label => $table) {
                if (Schema::hasTable($table)) {
                    $totals[$label] = DB::table($table)->count();
                }
            }
        } catch (Throwable) {
            $databaseReachable = false;
            // No raw connection error, database credentials or stack traces in UI.
        }

        return [
            'modules' => $modules,
            'unresolved' => $unresolved,
            'totals' => $totals,
            'databaseReachable' => $databaseReachable,
            'checkedAt' => now()->timezone('Asia/Jakarta')->format('d M Y H:i:s') . ' WIB',
            'applicationName' => (string) config('app.name'),
        ];
    }
}
