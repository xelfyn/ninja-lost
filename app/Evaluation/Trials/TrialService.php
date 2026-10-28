<?php

declare(strict_types=1);
namespace App\Evaluation\Trials;

use App\Content\SkillBlueprint;
use App\Evaluation\TrainingService;
use App\Models\User;
use App\Services\Battles\BattleService;
use App\Services\Rewards\RewardWriter;
use App\Support\Economy\StateRevision;
use App\Support\Rewards\RewardClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class TrialService
{
    private function locked(User $actor, callable $operation): mixed
    {
        abort_unless(TrialBoundary::enabled(), 404);
        return DB::transaction(function () use ($actor, $operation) {
            $user = User::whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($user && in_array((int) $user->account_type, [0, 1], true), 403);
            return $operation($user);
        }, 3);
    }
    private function link(User $user, string $battleId): object
    {
        $link = DB::table('nl_trial_runs')->where('battle_id', $battleId)->where('user_id', $user->id)->lockForUpdate()->first();
        abort_unless($link, 404); return $link;
    }
    private function view(User $user, object $link): array
    {
        $battle = DB::table('nl_battles')->where('id', $link->battle_id)->first();
        $trial = DB::table('nl_trial_rules')->where('id', $link->trial_id)->first();
        $terms = json_decode($battle->terms, true, 32, JSON_THROW_ON_ERROR);
        $status = $battle->status;
        if ($status === 'started' && RewardClock::now()->gte(RewardClock::parse($battle->expires_at))) $status = 'expired';
        return ['battle_id' => $link->battle_id, 'trial_id' => (int) $link->trial_id, 'title' => $trial->title,
            'battle_status' => $status, 'rewards' => ['gold' => (int) ($terms['rewards'][0]['quantity'] ?? 0), 'xp' => $terms['progression']['reward']],
            'run' => app(TrainingService::class)->get($user, $link->run_id),
            'result' => $battle->result === null ? null : json_decode($battle->result, true, 32, JSON_THROW_ON_ERROR)];
    }
    public function definitions(User $actor): array
    {
        return $this->locked($actor, function (User $user) {
            $rows = DB::table('nl_trial_rules as t')->join('nl_battle_rules as b', 'b.id', '=', 't.battle_rule_id')
                ->join('missions as m', 'm.mission_id', '=', 'b.mission_id')->where('b.active', true)->orderByDesc('t.id')->limit(100)
                ->get(['t.id', 't.title', 't.win_limit', 'm.req_lvl', 'm.gold', 'm.xp']);
            return $rows->map(fn ($r) => ['id' => (int) $r->id, 'title' => $r->title, 'minimum_level' => (int) $r->req_lvl,
                'gold' => (int) $r->gold, 'xp' => (int) $r->xp, 'win_limit' => (int) $r->win_limit, 'wins' => TrialRules::wins((int) $user->id, (int) $r->id)])->all();
        });
    }
    public function start(User $actor, array $input): array
    {
        Validator::make(['start' => $input], ['start' => ['required', 'array:trial_id,character_id,revision,request_key'],
            'start.trial_id' => ['required', 'integer', 'min:1'], 'start.character_id' => ['required', 'integer', 'min:1'],
            'start.revision' => ['required', 'integer', 'between:0,4294967295'], 'start.request_key' => ['required', 'uuid']])->validate();
        $input = ['trial_id' => (int) $input['trial_id'], 'character_id' => (int) $input['character_id'], 'revision' => (int) $input['revision'], 'request_key' => strtolower($input['request_key'])];
        return $this->locked($actor, function (User $user) use ($input) {
            $hash = hash('sha256', SkillBlueprint::json($input));
            $old = DB::table('nl_trial_runs')->where('user_id', $user->id)->where('start_key', $input['request_key'])->first();
            if ($old) { abort_unless(hash_equals($old->request_hash, $hash), 409); return ['replayed' => true, 'trial' => $this->view($user, $old)]; }
            $character = DB::table('characters')->where('id', $input['character_id'])->where('user_id', $user->id)->lockForUpdate()->first();
            abort_unless($character, 404);
            abort_unless($input['revision'] === StateRevision::current($user->id), 409, 'Loadout berubah; muat ulang.');
            $trial = DB::table('nl_trial_rules')->where('id', $input['trial_id'])->first(); abort_unless($trial, 404);
            abort_if(TrialRules::wins($user->id, $trial->id) >= $trial->win_limit, 409, 'Kuota kemenangan akun telah habis.');
            abort_if(DB::table('nl_trial_runs as t')->join('nl_battles as b', 'b.id', '=', 't.battle_id')->where('t.user_id', $user->id)
                ->where('b.status', 'started')->where('b.expires_at', '>', RewardClock::now())->exists(), 409, 'Selesaikan tantangan akun yang masih aktif.');
            $ticket = app(BattleService::class)->start($user, ['character_id' => $character->id, 'rule_id' => $trial->battle_rule_id, 'request_key' => (string) Str::uuid()]);
            $training = app(TrainingService::class)->start($user, ['character_id' => $character->id, 'revision' => $input['revision'], 'request_key' => (string) Str::uuid()]);
            $run = DB::table('nl_training_runs')->where('id', $training['run']['id'])->first();
            $battle = DB::table('nl_battles')->where('id', $ticket['battle_id'])->first();
            // Created together: existing standalone training can never be attached as reward proof.
            DB::table('nl_trial_runs')->insert(['battle_id' => $battle->id, 'run_id' => $run->id, 'trial_id' => $trial->id, 'user_id' => $user->id,
                'start_key' => $input['request_key'], 'request_hash' => $hash,
                'terms_hash' => hash('sha256', SkillBlueprint::json(json_decode($battle->terms, true, 32, JSON_THROW_ON_ERROR))),
                'bundle_hash' => $run->bundle_hash, 'runtime_hash' => $run->runtime_hash, 'created_at' => RewardClock::now()]);
            return ['replayed' => false, 'trial' => $this->view($user, $this->link($user, $battle->id))];
        });
    }
    public function get(User $actor, string $id): array { return $this->locked($actor, fn (User $u) => $this->view($u, $this->link($u, $id))); }
    public function latest(User $actor, int $characterId): ?array
    {
        return $this->locked($actor, function (User $user) use ($characterId) {
            abort_unless(DB::table('characters')->where('id', $characterId)->where('user_id', $user->id)->exists(), 404);
            $query = DB::table('nl_trial_runs as t')->join('nl_battles as b', 'b.id', '=', 't.battle_id')->where('t.user_id', $user->id)->where('b.character_id', $characterId);
            $link = (clone $query)->where('b.status', 'started')->where('b.expires_at', '>', RewardClock::now())->first(['t.*'])
                ?? $query->orderByDesc('t.id')->first(['t.*']);
            return $link ? $this->view($user, $link) : null;
        });
    }
    public function action(User $actor, string $id, array $input): array
    {
        return $this->locked($actor, function (User $user) use ($id, $input) {
            $link = $this->link($user, $id);
            $result = app(TrainingService::class)->action($user, $link->run_id, $input);
            return ['replayed' => $result['replayed'], 'accepted_revision' => $result['accepted_revision'], 'trial' => $this->view($user, $link)];
        });
    }
    public function finish(User $actor, string $id, array $input): array
    {
        abort_unless($input === [], 422, 'Finish hanya menerima identitas battle pada URL.');
        return $this->locked($actor, function (User $user) use ($id) {
            $link = $this->link($user, $id);
            $trial = DB::table('nl_trial_rules')->where('id', $link->trial_id)->first();
            $battle = DB::table('nl_battles')->where('id', $id)->first();
            if ($battle->result === null) abort_if(TrialRules::wins($user->id, $trial->id) >= $trial->win_limit, 409, 'Kuota kemenangan akun telah habis.');
            $result = (new BattleService(app(RewardWriter::class), new TrialVerifier()))->finish($user, ['battle_id' => $id, 'proof' => ['run_id' => $link->run_id]]);
            return ['replayed' => $result['replayed'], 'trial' => $this->view($user, $link)];
        });
    }
}
