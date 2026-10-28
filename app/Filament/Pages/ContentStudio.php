<?php

namespace App\Filament\Pages;

use App\Content\EffectRegistry;
use App\Content\SkillBlueprint;
use App\Services\Content\SkillStudio;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class ContentStudio extends Page
{
    protected static ?string $slug = 'content-studio';
    protected static ?string $title = 'Content Studio — Skill';
    protected static ?string $navigationLabel = 'Content Studio';
    protected static string|\UnitEnum|null $navigationGroup = 'Ninja Lost';
    protected string $view = 'filament.pages.content-studio';
    public ?array $data = [];
    #[Locked] public ?int $draftId = null;
    #[Locked] public int $draftRevision = 0;
    #[Locked] public ?string $currentHead = null;
    #[Locked] public string $publishedId = '';
    #[Locked] public string $previewText = '';

    public static function canAccess(): bool
    {
        return app()->environment(['local', 'testing']) && auth()->check()
            && (int) DB::table('users')->where('id', auth()->id())->value('account_type') === 99;
    }
    public function mount(): void { abort_unless(static::canAccess(), 403); $this->newDraft(); }
    public function newDraft(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->reset('draftId', 'draftRevision', 'currentHead', 'publishedId', 'previewText');
        $this->resetErrorBag();
        $this->form->fill(['id' => 'skill_nl_'.strtolower(Str::random(12)), 'name' => '', 'lore' => '', 'element' => 2, 'level' => 1, 'premium' => false,
            'damage' => 70, 'cp_cost' => 0, 'cooldown' => 0, 'animation_key' => null, 'hit_frames' => '', 'effects' => [],
            'source_url' => '', 'rights_basis' => 'self_authored', 'rights_evidence' => '', 'supply_limit' => null]);
    }
    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Identitas dan mekanik')->columns(2)->schema([
                Forms\Components\TextInput::make('id')->label('ID skill')->required()->maxLength(100)->disabled(fn () => $this->draftId !== null)->dehydrated(),
                Forms\Components\TextInput::make('name')->label('Nama skill')->required()->maxLength(120),
                Forms\Components\Textarea::make('lore')->label('Deskripsi cerita (bukan aturan combat)')->maxLength(500)->columnSpanFull(),
                Forms\Components\Select::make('element')->label('Elemen')->options([1 => 'Wind', 2 => 'Fire', 3 => 'Lightning', 4 => 'Earth', 5 => 'Water'])->required(),
                Forms\Components\TextInput::make('level')->label('Level minimum')->integer()->minValue(1)->maxValue(200)->required(),
                Forms\Components\Toggle::make('premium')->label('Persyaratan akun premium'),
                Forms\Components\TextInput::make('damage')->label('Damage dasar')->integer()->minValue(1)->maxValue(1000000)->required()->helperText('Profil awal adalah skill damage satu target. Skill tanpa damage dan multi-hit belum dibuka.'),
                Forms\Components\TextInput::make('cp_cost')->label('Biaya CP')->integer()->minValue(0)->maxValue(1000000)->required(),
                Forms\Components\TextInput::make('cooldown')->label('Cooldown giliran')->integer()->minValue(0)->maxValue(50)->required(),
            ]),
            Section::make('Efek terstruktur')->schema([
                Forms\Components\Repeater::make('effects')->label('Daftar efek')->defaultItems(0)->maxItems(5)->reorderable(false)->addActionLabel('Tambah efek')->schema([
                    Forms\Components\Select::make('code')->label('Jenis efek')->options(EffectRegistry::options())->default('stun')->required(),
                    Forms\Components\TextInput::make('chance')->label('Peluang persen')->integer()->minValue(0)->maxValue(100)->default(100)->required(),
                    Forms\Components\TextInput::make('duration')->label('Durasi giliran target')->integer()->minValue(0)->maxValue(10)->default(1)->required(),
                    Forms\Components\TextInput::make('amount')->label('Besaran sesuai satuan efek')->integer()->minValue(0)->maxValue(100)->default(0)->required(),
                ])->columns(2)->helperText('Target, satuan, pemicu, dan handler mengikuti kamus di bawah. Durasi/nilai yang tidak cocok ditolak, bukan diabaikan.'),
            ]),
            Section::make('Referensi visual — belum diverifikasi di client')->columns(2)->schema([
                Forms\Components\TextInput::make('animation_key')->label('ID SWF dasar (opsional)')->maxLength(90)->helperText('ID skill, bukan path atau file unggahan. Referensi bukan bukti bahwa aset tersedia/berizin.'),
                Forms\Components\TextInput::make('hit_frames')->label('Frame hit tunggal (opsional)')->maxLength(6)->helperText('Satu indeks frame, bukan jumlah hit atau besaran damage. Kosong untuk belum ditetapkan.'),
            ]),
            Section::make('Asal dan batas penerbitan')->schema([
                Forms\Components\TextInput::make('source_url')->label('URL sumber HTTPS')->url()->required()->maxLength(500),
                Forms\Components\Select::make('rights_basis')->label('Dasar penggunaan menurut operator')->options(['self_authored' => 'Dibuat sendiri', 'permission_documented' => 'Izin terdokumentasi'])->required(),
                Forms\Components\Textarea::make('rights_evidence')->label('Keterangan atau bukti izin')->required()->minLength(10)->maxLength(2000),
                Forms\Components\TextInput::make('supply_limit')->label('Batas supply skill (kosong = tanpa cap)')->integer()->minValue(1)->maxValue(2147483647)->helperText('Cap tidak dapat diganti lewat aktivasi versi setelah penerbitan awal. Angka form bukan aturan ekonomi live.'),
            ]),
        ]);
    }
    private function definition(): array
    {
        $input = $this->form->getState();
        $frames = trim((string) ($input['hit_frames'] ?? ''));
        if ($frames !== '' && !preg_match('/\A[0-9]{1,5}\z/D', $frames)) SkillBlueprint::reject('Frame harus satu bilangan bulat nonnegatif.');
        $input['hit_frames'] = $frames === '' ? [] : [(int) $frames];
        $input['effects'] = array_values($input['effects'] ?? []);
        return SkillBlueprint::normalize($input);
    }
    public function previewDefinition(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->previewText = SkillBlueprint::tooltip($this->definition());
    }
    public function saveDraft(): void
    {
        app(\App\Services\Rewards\RewardManagement::class)::authorize(auth()->user());
        $d = $this->definition();
        $result = app(SkillStudio::class)->save(auth()->user(), $d, $this->draftId, $this->draftId === null ? null : $this->draftRevision);
        $this->draftId = $result['id']; $this->draftRevision = $result['revision']; $this->publishedId = '';
        $this->previewText = SkillBlueprint::tooltip($d);
        Notification::make()->title('Draft tersimpan; belum mengubah versi aktif')->success()->send();
    }
    public function loadDraft(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $row = DB::table('nl_studio_drafts')->where('id', $id)->first();
        abort_unless($row && str_starts_with($row->content_key, 'skill_nl_'), 404);
        $d = json_decode($row->definition, true, 32, JSON_THROW_ON_ERROR); $d['hit_frames'] = implode(',', $d['hit_frames']);
        $this->draftId = $id; $this->draftRevision = (int) $row->revision;
        $this->currentHead = DB::table('nl_studio_heads')->where('draft_id', $id)->value('release_id');
        $this->publishedId = ''; $this->previewText = ''; $this->resetErrorBag(); $this->form->fill($d);
    }
    public function duplicateDraft(): void
    {
        abort_unless(static::canAccess(), 403);
        $d = $this->definition(); $d['id'] = 'skill_nl_'.strtolower(Str::random(12)); $d['hit_frames'] = implode(',', $d['hit_frames']);
        $this->reset('draftId', 'draftRevision', 'currentHead', 'publishedId', 'previewText'); $this->form->fill($d); $this->resetErrorBag();
    }
    public function publishDraft(): void
    {
        abort_unless(static::canAccess(), 403);
        if ($this->draftId === null) SkillBlueprint::reject('Simpan draft terlebih dahulu.');
        $hash = hash('sha256', SkillBlueprint::json($this->definition()));
        $result = app(SkillStudio::class)->publish(auth()->user(), $this->draftId, $this->draftRevision, $hash);
        $this->publishedId = $result['id'];
        Notification::make()->title('Versi diterbitkan, belum diaktifkan')->success()->send();
    }
    public function activateRelease(string $id): void
    {
        abort_unless(static::canAccess(), 403);
        $owner = DB::table('nl_studio_releases')->where('id', $id)->value('draft_id');
        abort_unless($owner && (int) $owner === $this->draftId, 404);
        app(SkillStudio::class)->activate(auth()->user(), $id, $this->currentHead);
        $this->currentHead = $id;
        Notification::make()->title('Versi mekanik evaluasi aktif; bukan aktivasi game publik')->success()->send();
    }
    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        return ['drafts' => DB::table('nl_studio_drafts')->where('content_key', 'like', 'skill_nl_%')->orderByDesc('updated_at')->limit(30)->get(),
            'releases' => $this->draftId ? DB::table('nl_studio_releases')->where('draft_id', $this->draftId)->orderByDesc('draft_revision')->limit(20)->get() : collect(),
            'mechanics' => EffectRegistry::RULES];
    }
}
