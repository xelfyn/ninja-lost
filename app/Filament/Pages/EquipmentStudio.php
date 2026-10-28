<?php

namespace App\Filament\Pages;

use App\Content\EquipmentBlueprint;
use App\Content\SkillBlueprint;
use App\Services\Content\EquipmentStudio as Studio;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class EquipmentStudio extends Page
{
    protected static ?string $slug = 'equipment-studio';
    protected static ?string $title = 'Content Studio — Equipment dan Set';
    protected static ?string $navigationLabel = 'Equipment dan Set';
    protected static string|\UnitEnum|null $navigationGroup = 'Ninja Lost';
    protected string $view = 'filament.pages.equipment-studio';
    public array $definition = [];
    #[Locked] public ?int $draftId = null;
    #[Locked] public int $draftRevision = 0;
    #[Locked] public ?string $currentHead = null;
    #[Locked] public string $publishedId = '';
    #[Locked] public string $previewText = '';
    public static function canAccess(): bool { return app()->environment(['local', 'testing']) && auth()->check() && (int) DB::table('users')->where('id', auth()->id())->value('account_type') === 99; }
    public function mount(): void { $this->newDraft(); }
    public function newDraft(): void
    {
        abort_unless(static::canAccess(), 403); $this->reset('draftId', 'draftRevision', 'currentHead', 'publishedId', 'previewText'); $this->resetErrorBag();
        $this->definition = ['id' => 'gear_nl_'.strtolower(Str::random(12)), 'name' => '', 'lore' => '', 'pieces' => [], 'bonuses' => [],
            'source_url' => '', 'rights_basis' => 'self_authored', 'rights_evidence' => ''];
        $this->addPiece('weapon');
    }
    public function addPiece(string $slot): void
    {
        abort_unless(static::canAccess(), 403);
        if (!isset(EquipmentBlueprint::SLOTS[$slot]) || count($this->definition['pieces']) >= 4 || in_array($slot, array_column($this->definition['pieces'], 'slot'), true)) SkillBlueprint::reject('Slot sudah ada atau tidak valid.');
        $this->definition['pieces'][] = ['id' => EquipmentBlueprint::SLOTS[$slot].'_nl_'.strtolower(Str::random(12)), 'name' => '', 'slot' => $slot,
            'level' => 1, 'premium' => false, 'visual_key' => null, 'supply_limit' => null, 'modifiers' => []];
    }
    public function removePiece(int $index): void
    {
        abort_unless(static::canAccess(), 403); abort_unless(isset($this->definition['pieces'][$index]), 404);
        array_splice($this->definition['pieces'], $index, 1);
    }
    public function addModifier(int $index, bool $bonus = false): void
    {
        abort_unless(static::canAccess(), 403); $key = $bonus ? 'bonuses' : 'pieces';
        abort_unless(isset($this->definition[$key][$index]) && count($this->definition[$key][$index]['modifiers']) < 14, 422);
        $this->definition[$key][$index]['modifiers'][] = ['stat' => 'agility', 'unit' => 'flat', 'amount' => 0];
    }
    public function removeModifier(int $index, int $modifier, bool $bonus = false): void
    {
        abort_unless(static::canAccess(), 403); $key = $bonus ? 'bonuses' : 'pieces';
        abort_unless(isset($this->definition[$key][$index]['modifiers'][$modifier]), 404);
        array_splice($this->definition[$key][$index]['modifiers'], $modifier, 1);
    }
    public function addBonus(): void
    {
        abort_unless(static::canAccess(), 403); abort_unless(count($this->definition['bonuses']) < 3, 422);
        $this->definition['bonuses'][] = ['required' => 2, 'modifiers' => [['stat' => 'agility', 'unit' => 'flat', 'amount' => 0]]];
    }
    public function removeBonus(int $index): void
    {
        abort_unless(static::canAccess(), 403); abort_unless(isset($this->definition['bonuses'][$index]), 404); array_splice($this->definition['bonuses'], $index, 1);
    }
    public function previewDefinition(): void
    {
        abort_unless(static::canAccess(), 403); $this->previewText = EquipmentBlueprint::tooltip(EquipmentBlueprint::normalize($this->definition));
    }
    public function saveDraft(): void
    {
        abort_unless(static::canAccess(), 403); $d = EquipmentBlueprint::normalize($this->definition);
        $r = app(Studio::class)->save(auth()->user(), $d, $this->draftId, $this->draftId === null ? null : $this->draftRevision);
        $this->draftId = $r['id']; $this->draftRevision = $r['revision']; $this->publishedId = ''; $this->previewText = EquipmentBlueprint::tooltip($d);
        Notification::make()->title('Draft equipment tersimpan; versi aktif tidak berubah')->success()->send();
    }
    public function loadDraft(int $id): void
    {
        abort_unless(static::canAccess(), 403); $d = DB::table('nl_studio_drafts')->where('id', $id)->first();
        abort_unless($d && str_starts_with($d->content_key, 'gear_nl_'), 404);
        $this->definition = EquipmentBlueprint::normalize(json_decode($d->definition, true, 32, JSON_THROW_ON_ERROR));
        $this->draftId = $id; $this->draftRevision = (int) $d->revision; $this->currentHead = DB::table('nl_studio_heads')->where('draft_id', $id)->value('release_id');
        $this->publishedId = ''; $this->previewText = ''; $this->resetErrorBag();
    }
    public function duplicateDraft(): void
    {
        abort_unless(static::canAccess(), 403); $d = EquipmentBlueprint::normalize($this->definition); $d['id'] = 'gear_nl_'.strtolower(Str::random(12));
        foreach ($d['pieces'] as &$p) $p['id'] = EquipmentBlueprint::SLOTS[$p['slot']].'_nl_'.strtolower(Str::random(12)); unset($p);
        $this->reset('draftId', 'draftRevision', 'currentHead', 'publishedId', 'previewText'); $this->definition = $d; $this->resetErrorBag();
    }
    public function publishDraft(): void
    {
        abort_unless(static::canAccess(), 403); if ($this->draftId === null) SkillBlueprint::reject('Simpan draft terlebih dahulu.');
        $hash = hash('sha256', SkillBlueprint::json(EquipmentBlueprint::normalize($this->definition)));
        $r = app(Studio::class)->publish(auth()->user(), $this->draftId, $this->draftRevision, $hash); $this->publishedId = $r['id'];
    }
    public function activateRelease(string $id): void
    {
        abort_unless(static::canAccess(), 403); $owner = DB::table('nl_studio_releases')->where('id', $id)->value('draft_id');
        abort_unless($owner && (int) $owner === $this->draftId, 404);
        app(Studio::class)->activate(auth()->user(), $id, $this->currentHead); $this->currentHead = $id;
        Notification::make()->title('Versi equipment evaluasi aktif; inventory tidak diubah')->success()->send();
    }
    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        return ['drafts' => DB::table('nl_studio_drafts')->where('content_key', 'like', 'gear_nl_%')->orderByDesc('updated_at')->limit(30)->get(),
            'releases' => $this->draftId ? DB::table('nl_studio_releases')->where('draft_id', $this->draftId)->orderByDesc('draft_revision')->limit(20)->get() : collect(),
            'statRules' => EquipmentBlueprint::rules()['stats']];
    }
}
