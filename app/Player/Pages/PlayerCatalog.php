<?php

namespace App\Player\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;

class PlayerCatalog extends Page
{
    use WithPagination;
    protected static ?string $slug = 'catalog';
    protected static ?string $title = 'Katalog Konten';
    protected string $view = 'player.catalog';
    public string $search = '';
    public string $kind = '';
    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedKind(): void { $this->resetPage(); }
    protected function getViewData(): array
    {
        $this->validate(['search' => ['string', 'max:100'], 'kind' => ['in:,item,skill,pet,effect']]);
        $query = DB::table('nl_catalog')->where('enabled', true);
        if ($this->kind !== '') { $query->where('kind', $this->kind); }
        if ($this->search !== '') {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$this->search.'%')->orWhere('content_key', 'like', '%'.$this->search.'%'));
        }
        return ['entries' => $query->orderBy('title')->paginate(25, ['id', 'kind', 'content_key', 'title', 'source_revision', 'supply_limit', 'issued'])];
    }
}
