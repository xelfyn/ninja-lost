<?php

declare(strict_types=1);

namespace App\Support\Economy;

use Livewire\ComponentHook;

/** Runs on initial mount AND hydration, before any quarantined component action. */
final class EnforceEvaluationSurface extends ComponentHook
{
    public function boot(): void
    {
        abort_unless(EvaluationSurface::componentAllowed(get_class($this->component)), 403, 'Komponen legacy dikarantina sampai audit integrasi selesai.');
    }
}
