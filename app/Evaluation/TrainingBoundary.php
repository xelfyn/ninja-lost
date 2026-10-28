<?php

declare(strict_types=1);

namespace App\Evaluation;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TrainingBoundary
{
    public static function enabled(): bool { return GatewayAccess::enabled() && config('training.enabled') === true; }
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(self::enabled(), 404);
        return $next($request);
    }
}
