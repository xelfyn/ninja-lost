<?php

namespace App\Filament\Pages;

use App\Evaluation\Trials\TrialBoundary;
use App\Evaluation\Trials\TrialRules;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class MirrorTrials extends Page
{
    use WithPagination;
    protected static ?string $slug = 'mirror-trials';
    protected static ?string $title = 'Tantangan Cermin';
    protected static ?string $navigationLabel = 'Tantangan Cermin';
    protected string $view = 'filament.pages.mirror-trials';
    public string $trialSlug = '';
    public string $trialTitle = '';
    public string $minimumLevel = '1';
    public string $rewardGold = '0';
    public string $rewardXp = '0';
    public string $winLimit = '1';
    #[Locked]
    public string $createdId = '';
    public static function canAccess(): bool { return TrialBoundary::enabled() && StaffGrants::canAccess(); }
    public function mount(): void { abort_unless(static::canAccess(), 403); }
    public function createTrial(): void
    {
        abort_unless(static::canAccess(), 403);
        if ($this->createdId !== '') return;
        $this->createdId = (string) app(TrialRules::class)->create(auth()->user(), ['slug' => $this->trialSlug, 'title' => $this->trialTitle,
            'minimum_level' => $this->minimumLevel, 'gold' => $this->rewardGold, 'xp' => $this->rewardXp, 'win_limit' => $this->winLimit]);
    }
    public function activate(int $id): void { abort_unless(static::canAccess(), 403); app(TrialRules::class)->setActive(auth()->user(), $id, true); }
    public function pause(int $id): void { abort_unless(static::canAccess(), 403); app(TrialRules::class)->setActive(auth()->user(), $id, false); }
    public function newTrial(): void
    {
        abort_unless(static::canAccess(), 403); $this->reset('trialSlug', 'trialTitle', 'minimumLevel', 'rewardGold', 'rewardXp', 'winLimit', 'createdId'); $this->resetErrorBag();
    }
    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        return ['trials' => DB::table('nl_trial_rules as t')->join('nl_battle_rules as b', 'b.id', '=', 't.battle_rule_id')
            ->join('missions as m', 'm.mission_id', '=', 'b.mission_id')->orderByDesc('t.id')->paginate(20, ['t.*', 'b.active', 'm.req_lvl', 'm.gold', 'm.xp'])];
    }
}
