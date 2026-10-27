<?php

namespace App\Filament\Pages;

use App\Services\Shop\ShopOffers as OfferService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class ShopOffers extends Page
{
    use WithPagination;
    protected static ?string $slug = 'shop-offers';
    protected static ?string $title = 'Penawaran Toko';
    protected static ?string $navigationLabel = 'Penawaran Toko';
    protected string $view = 'filament.pages.shop-offers';
    public string $offerSlug = '';
    public string $offerTitle = '';
    public string $catalogId = '';
    public string $priceTokens = '0';
    public string $priceGold = '0';
    public string $maxQuantity = '1';
    #[Locked]
    public string $createdOfferId = '';

    public static function canAccess(): bool { return StaffGrants::canAccess(); }
    public function mount(): void { abort_unless(static::canAccess(), 403); }
    public function createOffer(): void
    {
        abort_unless(static::canAccess(), 403);
        if ($this->createdOfferId !== '') { return; }
        $id = app(OfferService::class)->create(auth()->user(), [
            'slug' => $this->offerSlug, 'title' => $this->offerTitle, 'catalog_id' => $this->catalogId,
            'price_tokens' => $this->priceTokens, 'price_gold' => $this->priceGold, 'max_quantity' => $this->maxQuantity,
        ]);
        $this->createdOfferId = (string) $id;
        Notification::make()->title('Penawaran tersimpan nonaktif; tinjau sebelum mengaktifkan')->success()->send();
    }
    public function activate(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        app(OfferService::class)->setActive(auth()->user(), $id, true);
    }
    public function pause(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        app(OfferService::class)->setActive(auth()->user(), $id, false);
    }
    public function newOffer(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->reset('offerSlug', 'offerTitle', 'catalogId', 'priceTokens', 'priceGold', 'maxQuantity', 'createdOfferId');
        $this->resetErrorBag();
    }
    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        return ['offers' => DB::table('nl_shop_offers')->orderByDesc('id')->paginate(20)];
    }
}
