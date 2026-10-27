<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Rewards\StaffGrantService;
use App\Support\Rewards\RewardRules;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class StaffGrants extends Page
{
    protected static ?string $slug = 'staff-grants';
    protected static ?string $title = 'Staff Grants';
    protected static ?string $navigationLabel = 'Staff Grants';
    protected string $view = 'filament.pages.staff-grants';
    public string $characterId = '';
    public string $rewardType = 'tokens';
    public string $contentKey = '';
    public string $quantity = '1';
    public string $reason = '';
    #[Locked]
    public string $requestKey = '';
    #[Locked]
    public string $receiptId = '';

    public static function canAccess(): bool
    {
        return app()->environment(['local', 'testing']) && auth()->check()
            && (int) DB::table('users')->where('id', auth()->id())->value('account_type') === 99;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->requestKey = (string) Str::uuid();
    }

    public function grant(): void
    {
        abort_unless(static::canAccess(), 403);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        if ($this->receiptId !== '') { return; }
        $limitKey = 'nl-staff-grant:'.$actor->id;
        if (RateLimiter::tooManyAttempts($limitKey, 10)) { RewardRules::reject('Terlalu banyak grant. Coba kembali setelah satu menit.'); }
        RateLimiter::hit($limitKey, 60);
        $this->validate([
            'characterId' => ['required', 'integer', 'min:1'],
            'rewardType' => ['required', 'in:tokens,gold,item,skill,pet'],
            'contentKey' => ['nullable', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reason' => ['required', 'string', 'min:8', 'max:160'],
        ]);
        $result = app(StaffGrantService::class)->grant($actor, (int) $this->characterId,
            [['type' => $this->rewardType, 'key' => $this->contentKey, 'quantity' => (int) $this->quantity]],
            $this->reason, $this->requestKey);
        // Keep the identity after commit. A deliberate new operation is required to issue again.
        $this->receiptId = $result['id'];
        Notification::make()->title('Grant tercatat')->success()->send();
    }

    public function newGrant(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->reset('characterId', 'rewardType', 'contentKey', 'quantity', 'reason', 'receiptId');
        $this->requestKey = (string) Str::uuid();
        $this->resetErrorBag();
    }
}
