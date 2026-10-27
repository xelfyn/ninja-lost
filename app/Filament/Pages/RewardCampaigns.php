<?php

namespace App\Filament\Pages;

use App\Services\Rewards\RewardManagement;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class RewardCampaigns extends Page
{
    protected static ?string $title = 'Reward Campaigns';
    protected static string|\UnitEnum|null $navigationGroup = 'Ninja Lost';
    protected string $view = 'filament.pages.reward-campaigns';
    public ?array $data = [];
    public static function canAccess(): bool { return auth()->check() && (int) auth()->user()->account_type === 99 && app()->environment(['local', 'testing']); }
    public function mount(): void { $this->form->fill(); }
    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            TextInput::make('slug')->required()->maxLength(80)->helperText('ID versi campaign, huruf kecil/angka/tanda minus.'),
            TextInput::make('title')->label('Nama campaign')->required()->maxLength(160),
            Select::make('kind')->label('Jenis')->options(['starter' => 'Starter pack pilihan', 'redeem' => 'Redeem kode'])->default('starter')->required(),
            TextInput::make('code')->label('Kode redeem (kosong untuk starter)')->password()->revealable()->maxLength(64)->helperText('12–64 huruf/angka/minus. Simpan sendiri; hanya hash disimpan server.'),
            DateTimePicker::make('starts_at')->label('Mulai (UTC)')->timezone('UTC')->required(),
            DateTimePicker::make('ends_at')->label('Berakhir (UTC)')->timezone('UTC')->required(),
            TextInput::make('max_claims')->label('Kuota klaim akun')->integer()->minValue(1)->maxValue(1000000)->required(),
            Repeater::make('choices')->label('Pilihan paket')->minItems(1)->maxItems(8)->defaultItems(1)->schema([
                TextInput::make('key')->label('ID pilihan')->default('default')->required()->maxLength(40),
                TextInput::make('label')->label('Nama pilihan')->required()->maxLength(80),
                Repeater::make('rewards')->label('Isi hadiah')->minItems(1)->maxItems(12)->defaultItems(1)->schema([
                    Select::make('type')->label('Jenis hadiah')->options(['tokens' => 'Token akun', 'gold' => 'Gold karakter', 'item' => 'Item', 'skill' => 'Skill', 'pet' => 'Pet'])->required(),
                    TextInput::make('key')->label('ID konten backend')->maxLength(100)->helperText('Kosong untuk token/gold. ID lainnya harus terdaftar di Content Registry.'),
                    TextInput::make('quantity')->label('Jumlah')->integer()->minValue(1)->maxValue(100000000)->required(),
                ]),
            ]),
        ]);
    }
    public function createCampaign(): void
    {
        $state = $this->form->getState();
        // Filament displays UTC but dehydrates dates into the legacy app timezone.
        // Restore an offset-bearing instant at this adapter boundary; service input is UTC.
        foreach (['starts_at', 'ends_at'] as $field) {
            $state[$field] = CarbonImmutable::parse($state[$field], config('app.timezone'))->utc()->toIso8601String();
        }
        app(RewardManagement::class)->createCampaign(auth()->user(), $state);
        $this->form->fill();
        Notification::make()->title('Draft campaign disimpan')->success()->send();
    }
    public function changeStatus(int $id, string $status): void
    {
        app(RewardManagement::class)->setCampaignStatus(auth()->user(), $id, $status);
        Notification::make()->title('Status campaign diperbarui')->success()->send();
    }
    protected function getViewData(): array
    {
        return ['campaigns' => DB::table('nl_campaigns')->orderByDesc('id')->limit(100)->get(['id', 'title', 'kind', 'status', 'claimed_count', 'max_claims', 'starts_at', 'ends_at'])];
    }
}
