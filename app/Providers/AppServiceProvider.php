<?php

namespace App\Providers;

use App\Support\Economy\EnforceEvaluationSurface;
use App\Support\Economy\EvaluationSurface;
use App\Support\NSX\PreviewOrigin;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\ComponentHookRegistry;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register before Livewire boots its hook registry.
        ComponentHookRegistry::register(EnforceEvaluationSurface::class);
    }

    public function boot(): void
    {
        $origin = PreviewOrigin::resolve(
            $this->app->environment(), env('CODESPACES', false), env('CODESPACE_NAME'),
            env('GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN', 'app.github.dev'), (string) config('app.url'),
        );
        if ($origin !== null) {
            URL::forceRootUrl($origin);
            URL::forceScheme('https');
        }
        // The pinned Laravel 12 kernel omits automatic console-event routing in
        // APP_ENV=testing. Quarantine must also cover that supported evaluation mode.
        // Initialize the idempotent dispatcher before any Artisan instance is created.
        $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $command = app(Kernel::class)->all()[$event->command] ?? null;
            if ($command && !EvaluationSurface::commandAllowed(get_class($command))) {
                throw new \RuntimeException('Command aplikasi legacy dikarantina; tidak ada data yang diubah.');
            }
        });
    }
}
