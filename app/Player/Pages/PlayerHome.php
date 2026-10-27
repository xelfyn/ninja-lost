<?php

namespace App\Player\Pages;

use App\Models\User;
use App\Services\Rewards\RewardService;
use App\Support\Rewards\RewardRules;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class PlayerHome extends Page
{
    use WithPagination;

    protected static ?string $slug = 'home';
    protected static ?string $title = 'Portal Pemain';
    protected static ?string $navigationLabel = 'Karakter & Reward';
    protected string $view = 'player.home';
    public string $characterId = '';
    public string $campaignId = '';
    public string $choiceKey = '';
    public string $redeemCode = '';
    public string $redeemChoice = 'default';
    public string $inventoryKind = 'item';
    #[Locked]
    public string $requestKey = '';

    public function mount(): void
    {
        $this->characterId = (string) (DB::table('characters')->where('user_id', auth()->id())->orderBy('id')->value('id') ?? '');
        $this->requestKey = (string) Str::uuid();
    }

    public function updatedCharacterId(): void { $this->resetPage('inventoryPage'); }
    public function updatedInventoryKind(): void { $this->resetPage('inventoryPage'); }
    public function updatedCampaignId(): void { $this->choiceKey = ''; }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->canAccessPanel(\Filament\Facades\Filament::getPanel('player')), 403);
        $key = 'nl-claim:'.$actor->id;
        if (RateLimiter::tooManyAttempts($key, 20)) {
            RewardRules::reject('Terlalu banyak percobaan. Coba lagi setelah satu menit.');
        }
        RateLimiter::hit($key, 60);
        return $actor;
    }

    public function claimStarter(): void
    {
        $actor = $this->actor();
        $this->validate(['characterId' => ['required', 'integer', 'min:1'], 'campaignId' => ['required', 'integer', 'min:1'], 'choiceKey' => ['required', 'string', 'max:40']]);
        $result = app(RewardService::class)->starter($actor, (int) $this->characterId, (int) $this->campaignId, $this->choiceKey, $this->requestKey);
        $this->campaignId = ''; $this->choiceKey = '';
        $this->completed($result);
    }

    public function redeem(): void
    {
        $actor = $this->actor();
        $this->validate(['characterId' => ['required', 'integer', 'min:1'], 'redeemCode' => ['required', 'string', 'max:64'], 'redeemChoice' => ['required', 'string', 'max:40']]);
        $result = app(RewardService::class)->redeem($actor, (int) $this->characterId, $this->redeemCode, $this->redeemChoice, $this->requestKey);
        $this->redeemCode = '';
        $this->completed($result);
    }

    private function completed(array $result): void
    {
        $this->requestKey = (string) Str::uuid();
        $this->resetErrorBag();
        // Notification is outside the database transaction; ledger remains the source of truth.
        Notification::make()->title($result['replayed'] ? 'Klaim sudah tercatat' : 'Hadiah berhasil diterima')->success()->send();
    }

    protected function getViewData(): array
    {
        $userId = auth()->id();
        abort_unless($userId, 403);
        $characters = DB::table('characters')->where('user_id', $userId)->orderBy('id')->limit(100)->get(['id', 'name']);
        $character = $this->characterId === '' ? null : DB::table('characters')->where('user_id', $userId)->where('id', $this->characterId)->first();
        abort_if($this->characterId !== '' && !$character, 404);
        abort_unless(isset(RewardRules::CONTENT[$this->inventoryKind]), 422);
        [$definitions, $column, $table] = RewardRules::CONTENT[$this->inventoryKind];
        $inventory = DB::table($table.' as inventory')->leftJoin($definitions.' as definition', 'definition.'.$column, '=', 'inventory.'.$column)
            ->where('inventory.character_id', $character?->id ?? 0)
            ->select(['inventory.id', 'inventory.'.$column.' as content_key', 'definition.name as title'])
            ->addSelect($this->inventoryKind === 'item' ? 'inventory.quantity' : DB::raw('1 as quantity'))
            ->orderByDesc('inventory.id')->paginate(25, ['*'], 'inventoryPage');
        $campaigns = DB::table('nl_campaigns')->where('kind', 'starter')->where('status', 'active')
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())->whereColumn('claimed_count', '<', 'max_claims')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('nl_claims')->whereColumn('campaign_id', 'nl_campaigns.id')->where('user_id', $userId))
            ->orderBy('id')->limit(100)->get();
        $selected = $campaigns->firstWhere('id', (int) $this->campaignId);
        $choices = $selected ? json_decode($selected->choices, true, 32, JSON_THROW_ON_ERROR) : [];
        return [
            'characters' => $characters, 'character' => $character, 'inventory' => $inventory,
            'tokens' => (int) DB::table('users')->where('id', $userId)->value('tokens'),
            'campaigns' => $campaigns, 'choices' => $choices,
            'history' => DB::table('nl_claims')->where('user_id', $userId)->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get(),
        ];
    }
}
