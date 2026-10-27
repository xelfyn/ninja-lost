<?php

namespace App\Player\Pages;

use App\Services\Shop\ShopPurchase;
use App\Support\Rewards\RewardRules;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class PlayerShop extends Page
{
    protected static ?string $slug = 'shop';
    protected static ?string $title = 'Toko Evaluasi';
    protected static ?string $navigationLabel = 'Toko';
    protected string $view = 'player.shop';
    public string $characterId = '';
    public string $offerId = '';
    public string $quantity = '1';
    #[Locked]
    public string $requestKey = '';
    #[Locked]
    public string $receiptId = '';

    public static function canAccess(): bool
    {
        if (!app()->environment(['local', 'testing']) || !auth()->check()) { return false; }
        $role = DB::table('users')->where('id', auth()->id())->value('account_type');
        // A deleted account is not a normal (zero-role) account.
        return $role !== null && in_array((int) $role, [0, 1, 99], true);
    }
    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->characterId = (string) (DB::table('characters')->where('user_id', auth()->id())->orderBy('id')->value('id') ?? '');
        $this->requestKey = (string) Str::uuid();
    }
    public function buy(): void
    {
        abort_unless(static::canAccess(), 403);
        if ($this->receiptId !== '') { return; }
        $limit = 'nl-shop:'.auth()->id();
        if (RateLimiter::tooManyAttempts($limit, 20)) { RewardRules::reject('Terlalu banyak percobaan. Coba kembali setelah satu menit.'); }
        RateLimiter::hit($limit, 60);
        $result = app(ShopPurchase::class)->buy(auth()->user(), ['character_id' => $this->characterId, 'offer_id' => $this->offerId, 'quantity' => $this->quantity, 'request_key' => $this->requestKey]);
        $this->receiptId = $result['id'];
        $this->resetErrorBag();
        Notification::make()->title($result['replayed'] ? 'Pembelian sudah tercatat' : 'Pembelian berhasil')->success()->send();
    }
    public function newPurchase(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->reset('offerId', 'quantity', 'receiptId');
        $this->requestKey = (string) Str::uuid();
        $this->resetErrorBag();
    }
    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $characters = DB::table('characters')->where('user_id', auth()->id())->orderBy('id')->limit(100)->get(['id', 'name', 'gold']);
        $character = $this->characterId === '' ? null : DB::table('characters')->where('user_id', auth()->id())->where('id', $this->characterId)->first(['id', 'name', 'gold']);
        abort_if($this->characterId !== '' && !$character, 404);
        $offers = DB::table('nl_shop_offers as o')->join('nl_catalog as c', 'c.id', '=', 'o.catalog_id')->where('o.active', true)->where('c.enabled', true)
            ->whereIn('c.kind', ['item', 'skill'])->whereIn('c.rights_basis', ['self_authored', 'permission_documented'])
            ->orderByDesc('o.id')->limit(100)->get(['o.*', 'c.kind', 'c.content_key']);
        $selected = $offers->firstWhere('id', (int) $this->offerId);
        $quantity = ctype_digit($this->quantity) && (int) $this->quantity >= 1 && (int) $this->quantity <= 1000 ? (int) $this->quantity : 0;
        $history = DB::table('nl_shop_purchases as p')->join('nl_claims as c', 'c.id', '=', 'p.claim_id')->where('c.user_id', auth()->id())->orderByDesc('c.created_at')->orderByDesc('c.id')->limit(20)
            ->get(['p.claim_id', 'p.quantity', 'p.charged_tokens', 'p.charged_gold', 'c.reason', 'c.created_at']);
        return ['characters' => $characters, 'character' => $character, 'offers' => $offers, 'selected' => $selected, 'units' => $quantity,
            'tokens' => (int) DB::table('users')->where('id', auth()->id())->value('tokens'), 'history' => $history];
    }
}
