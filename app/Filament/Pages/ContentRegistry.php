<?php

namespace App\Filament\Pages;

use App\Services\Rewards\RewardManagement;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class ContentRegistry extends Page
{
    protected static ?string $title = 'Content Registry';
    protected static string|\UnitEnum|null $navigationGroup = 'Ninja Lost';
    protected string $view = 'filament.pages.content-registry';
    public ?array $data = [];
    public static function canAccess(): bool { return auth()->check() && (int) auth()->user()->account_type === 99 && app()->environment(['local', 'testing']); }
    public function mount(): void { $this->form->fill(); }
    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Select::make('kind')->label('Jenis')->options(['item'=>'Item','skill'=>'Skill','pet'=>'Pet','effect'=>'Metadata efek'])->required(),
            TextInput::make('content_key')->label('ID konten backend')->required()->maxLength(100),
            TextInput::make('title')->label('Nama konten')->required()->maxLength(160),
            TextInput::make('source_url')->label('URL sumber HTTPS')->url()->required()->maxLength(500),
            TextInput::make('source_revision')->label('Versi / commit sumber')->required()->maxLength(100),
            Select::make('rights_basis')->label('Dasar penggunaan menurut operator')->options(['self_authored'=>'Konten dibuat sendiri','permission_documented'=>'Izin terdokumentasi'])->required(),
            Textarea::make('rights_evidence')->label('Bukti hak penggunaan')->required()->minLength(10)->maxLength(2000),
            Textarea::make('compatibility_note')->label('Catatan kompatibilitas / hasil pemeriksaan')->required()->minLength(10)->maxLength(2000),
            TextInput::make('supply_limit')->label('Batas supply reward (opsional)')->integer()->minValue(1)->maxValue(2147483647),
            KeyValue::make('effect_metadata')->label('Metadata efek opsional — bukan kode battle'),
        ]);
    }
    public function registerContent(): void
    {
        app(RewardManagement::class)->register(auth()->user(), $this->form->getState());
        $this->form->fill();
        Notification::make()->title('Konten tercatat, belum diaktifkan')->success()->send();
    }
    public function setEnabled(int $id, bool $enabled): void
    {
        app(RewardManagement::class)->setCatalogEnabled(auth()->user(), $id, $enabled);
        Notification::make()->title('Status konten diperbarui')->success()->send();
    }
    protected function getViewData(): array
    {
        return ['entries' => DB::table('nl_catalog')->orderByDesc('id')->limit(100)->get()];
    }
}
