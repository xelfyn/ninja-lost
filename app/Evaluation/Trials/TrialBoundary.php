<?php

declare(strict_types=1);
namespace App\Evaluation\Trials;

use App\Evaluation\TrainingBoundary;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TrialBoundary
{
    public static function enabled(): bool { return TrainingBoundary::enabled() && config('mirror_trials.enabled') === true; }
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(self::enabled(), 404);
        return $next($request);
    }
}
