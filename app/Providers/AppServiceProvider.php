<?php

namespace App\Providers;

use App\Support\NSX\PreviewOrigin;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Exact local Codespaces origin only. Do not trust arbitrary forwarded hosts/proxies.
        $origin = PreviewOrigin::resolve(
            $this->app->environment(), env('CODESPACES', false), env('CODESPACE_NAME'),
            env('GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN', 'app.github.dev'), (string) config('app.url'),
        );
        if ($origin !== null) {
            URL::forceRootUrl($origin);
            URL::forceScheme('https');
        }
    }
}
