<?php

declare(strict_types=1);

namespace App\Evaluation;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class TrainingGateway
{
    public function client()
    {
        return response()->view('evaluation.training')->header('X-Frame-Options', 'DENY')
            ->header('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'none'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    }
    private function call(Request $request, callable $operation, bool $write = false)
    {
        return response()->json(GatewayAccess::run($request, function (User $actor) use ($operation, $write) {
            if ($write) {
                $key = 'nl-training:'.$actor->id;
                abort_if(RateLimiter::tooManyAttempts($key, 60), 429, 'Batas permintaan latihan tercapai.', ['Retry-After' => '60']);
                RateLimiter::hit($key, 60);
            }
            return $operation(app(TrainingService::class), $actor);
        }));
    }
    public function identity(Request $r) { return $this->call($r, fn ($s, $u) => ['user_id' => (int) $u->id]); }
    public function loadout(Request $r, string $character) { return $this->call($r, fn ($s, $u) => $s->loadout($u, (int) $character)); }
    public function saveLoadout(Request $r, string $character) { return $this->call($r, fn ($s, $u) => $s->saveLoadout($u, (int) $character, $r->json()->all()), true); }
    public function start(Request $r) { return $this->call($r, fn ($s, $u) => $s->start($u, $r->json()->all()), true); }
    public function show(Request $r, string $run) { return $this->call($r, fn ($s, $u) => $s->get($u, $run)); }
    public function latest(Request $r, string $character) { return $this->call($r, fn ($s, $u) => ['run' => $s->latest($u, (int) $character)]); }
    public function action(Request $r, string $run) { return $this->call($r, fn ($s, $u) => $s->action($u, $run, $r->json()->all()), true); }
}
