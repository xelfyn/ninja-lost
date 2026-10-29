<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Content\ContentDirectoryService;
use App\Services\Rewards\StaffGrantService;
use App\Support\Rewards\RewardRules;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

class StaffGrants extends Page
{
    protected static ?string $slug = 'staff-grants';
    protected static ?string $title = 'Staff Grants';
    protected static ?string $navigationLabel = 'Staff Grants';
    protected string $view = 'filament.pages.staff-grants';

    public string $characterId = '';
    public string $characterSearch = '';
    public string $rewardType = 'tokens';
    public string $contentKey = '';
    public string $contentSearch = '';
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

    public function updatedCharacterSearch(): void
    {
        $this->characterId = '';
    }

    public function updatedContentSearch(): void
    {
        $this->contentKey = '';
    }

    public function updatedRewardType(): void
    {
        $this->contentKey = '';
        $this->contentSearch = '';
    }

    public function selectCharacter(int $id): void
    {
        abort_unless(static::canAccess(), 403);

        $entry = app(ContentDirectoryService::class)->character($id);

        if ($entry === null) {
            $this->addError('characterSearch', 'That character is no longer available.');
            return;
        }

        $this->characterId = (string) $entry['id'];
        $this->characterSearch = $entry['label'];
        $this->resetErrorBag('characterSearch');
    }

    public function selectContent(string $key): void
    {
        abort_unless(static::canAccess(), 403);

        if (! in_array($this->rewardType, ['item', 'skill', 'pet'], true)) {
            $this->addError('contentSearch', 'Choose Item, Skill, or Pet before selecting content.');
            return;
        }

        $entry = app(ContentDirectoryService::class)->content($this->rewardType, $key);

        if ($entry === null) {
            $this->addError('contentSearch', 'That content is no longer enabled or available.');
            return;
        }

        $this->contentKey = $entry['key'];
        $this->contentSearch = $entry['name'].' · '.$entry['key'];
        $this->resetErrorBag('contentSearch');
    }

    public function grant(): void
    {
        abort_unless(static::canAccess(), 403);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        if ($this->receiptId !== '') {
            return;
        }

        $limitKey = 'nl-staff-grant:'.$actor->id;
        if (RateLimiter::tooManyAttempts($limitKey, 10)) {
            RewardRules::reject('Too many grant attempts. Try again in one minute.');
        }
        RateLimiter::hit($limitKey, 60);

        $this->validate([
            'characterId' => ['required', 'integer', 'min:1'],
            'rewardType' => ['required', 'in:tokens,gold,item,skill,pet'],
            'contentKey' => [
                Rule::requiredIf(fn () => in_array($this->rewardType, ['item', 'skill', 'pet'], true)),
                'nullable',
                'string',
                'max:100',
            ],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reason' => ['required', 'string', 'min:8', 'max:160'],
        ]);

        $directory = app(ContentDirectoryService::class);

        if ($directory->character((int) $this->characterId) === null) {
            $this->addError('characterSearch', 'The selected character no longer exists.');
            return;
        }

        if (in_array($this->rewardType, ['item', 'skill', 'pet'], true)
            && $directory->content($this->rewardType, $this->contentKey) === null) {
            $this->addError('contentSearch', 'The selected content is no longer enabled or available.');
            return;
        }

        $result = app(StaffGrantService::class)->grant(
            $actor,
            (int) $this->characterId,
            [['type' => $this->rewardType, 'key' => $this->contentKey, 'quantity' => (int) $this->quantity]],
            $this->reason,
            $this->requestKey,
        );

        // Keep the request identity after commit. A deliberate new operation is
        // required before the operator can issue another reward.
        $this->receiptId = $result['id'];

        Notification::make()
            ->title($result['replayed'] ? 'Grant already recorded' : 'Grant issued')
            ->success()
            ->send();
    }

    public function newGrant(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->reset(
            'characterId',
            'characterSearch',
            'rewardType',
            'contentKey',
            'contentSearch',
            'quantity',
            'reason',
            'receiptId',
        );
        $this->quantity = '1';
        $this->rewardType = 'tokens';
        $this->requestKey = (string) Str::uuid();
        $this->resetErrorBag();
    }

    protected function getViewData(): array
    {
        $directory = app(ContentDirectoryService::class);
        $characterSearch = trim($this->characterSearch);
        $contentSearch = trim($this->contentSearch);

        return [
            'characterMatches' => $characterSearch === '' || $this->characterId !== ''
                ? []
                : $directory->searchCharacters($characterSearch, 12),
            'selectedCharacter' => $this->characterId === ''
                ? null
                : $directory->character((int) $this->characterId),
            'contentMatches' => $contentSearch === ''
                || $this->contentKey !== ''
                || ! in_array($this->rewardType, ['item', 'skill', 'pet'], true)
                    ? []
                    : $directory->searchContent($this->rewardType, $contentSearch, 12),
            'selectedContent' => $this->contentKey === ''
                || ! in_array($this->rewardType, ['item', 'skill', 'pet'], true)
                    ? null
                    : $directory->content($this->rewardType, $this->contentKey),
        ];
    }
}
