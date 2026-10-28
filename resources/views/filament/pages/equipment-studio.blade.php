<x-filament-panels::page>
<p>Profil evaluasi: equipment dan bonus set stat dasar. Tidak mengunggah SWF, tidak mengaktifkan client publik. Critical dan Dodge memakai poin persentase, bukan persen relatif.</p>
@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<form wire:submit="saveDraft" style="display:grid;gap:1rem;min-width:0">
<x-filament::section heading="Identitas paket">
<div style="display:grid;gap:.5rem;min-width:0">
<label>ID paket <input data-testid="gear-id" wire:model="definition.id" @readonly($draftId !== null) style="max-width:100%"></label>
<label>Nama paket <input data-testid="gear-name" wire:model="definition.name" maxlength="120" style="max-width:100%"></label>
<label>Cerita <textarea wire:model="definition.lore" maxlength="500" style="width:100%"></textarea></label>
</div></x-filament::section>
@foreach($definition['pieces'] as $i => $piece)
<x-filament::section :heading="'Bagian '.($i + 1).' — '.$piece['slot']" wire:key="piece-{{ $i }}">
<div style="display:grid;gap:.5rem;min-width:0">
<label>ID item <input data-testid="piece-id-{{ $i }}" wire:model="definition.pieces.{{ $i }}.id" style="max-width:100%"></label>
<label>Nama item <input data-testid="piece-name-{{ $i }}" wire:model="definition.pieces.{{ $i }}.name" maxlength="120" style="max-width:100%"></label>
<label>Level minimum <input type="number" min="1" max="200" wire:model="definition.pieces.{{ $i }}.level" style="width:6rem"></label>
<label><input type="checkbox" wire:model="definition.pieces.{{ $i }}.premium"> Premium</label>
<label>Referensi visual <input wire:model="definition.pieces.{{ $i }}.visual_key" placeholder="Belum diverifikasi di client" style="max-width:100%"></label>
<label>Cap supply (opsional) <input type="number" min="1" wire:model="definition.pieces.{{ $i }}.supply_limit" style="width:8rem"></label>
@include('filament.pages.equipment-modifiers', ['scope' => 'pieces', 'index' => $i, 'modifiers' => $piece['modifiers']])
<div><x-filament::button type="button" data-testid="add-modifier-{{ $i }}" wire:click="addModifier({{ $i }})">Tambah bonus stat</x-filament::button>
<x-filament::button type="button" color="gray" wire:click="removePiece({{ $i }})">Hapus bagian</x-filament::button></div>
</div></x-filament::section>
@endforeach
<div style="display:flex;gap:.5rem;flex-wrap:wrap">@foreach(\App\Content\EquipmentBlueprint::SLOTS as $slot => $prefix)<x-filament::button type="button" color="gray" data-testid="add-piece-{{ $slot }}" wire:click="addPiece('{{ $slot }}')">Tambah {{ $slot }}</x-filament::button>@endforeach</div>
<x-filament::section heading="Bonus kombinasi set">
<p>Semua ambang yang terpenuhi dijumlahkan. Salinan barang di inventory tidak menambah hitungan bagian. Anggota, slot dan cap terkunci setelah aktivasi pertama.</p>
@foreach($definition['bonuses'] as $i => $bonus)
<div wire:key="tier-{{ $i }}" style="margin:1rem 0">
<label>Jumlah bagian <input data-testid="tier-required-{{ $i }}" type="number" min="2" max="4" wire:model="definition.bonuses.{{ $i }}.required" style="width:5rem"></label>
@include('filament.pages.equipment-modifiers', ['scope' => 'bonuses', 'index' => $i, 'modifiers' => $bonus['modifiers']])
<x-filament::button type="button" size="sm" wire:click="addModifier({{ $i }}, true)">Tambah stat set</x-filament::button>
<x-filament::button type="button" size="sm" color="gray" wire:click="removeBonus({{ $i }})">Hapus ambang</x-filament::button>
</div>@endforeach
<x-filament::button type="button" data-testid="add-tier" wire:click="addBonus">Tambah ambang set</x-filament::button>
</x-filament::section>
<x-filament::section heading="Sumber dan hak penggunaan menurut operator">
<div style="display:grid;gap:.5rem">
<label>URL sumber <input type="url" data-testid="gear-source" wire:model="definition.source_url" style="max-width:100%"></label>
<label>Dasar penggunaan <select wire:model="definition.rights_basis"><option value="self_authored">Dibuat sendiri</option><option value="permission_documented">Izin terdokumentasi</option></select></label>
<label>Keterangan <textarea data-testid="gear-rights" wire:model="definition.rights_evidence" maxlength="2000" style="width:100%"></textarea></label>
</div></x-filament::section>
<div style="display:flex;flex-wrap:wrap;gap:.5rem">
<x-filament::button type="button" wire:click="previewDefinition">Validasi dan tooltip</x-filament::button>
<x-filament::button type="submit">Simpan draft</x-filament::button>
<x-filament::button type="button" wire:click="publishDraft">Terbitkan versi tersimpan</x-filament::button>
<x-filament::button type="button" color="gray" wire:click="duplicateDraft">Duplikasi paket</x-filament::button>
<x-filament::button type="button" color="gray" wire:click="newDraft">Paket baru</x-filament::button>
</div></form>
<p>Draft <span id="gear-draft">{{ $draftId ?? '-' }}</span>; revisi <span id="gear-revision">{{ $draftRevision }}</span></p>
<p style="overflow-wrap:anywhere">Aktif: <span id="gear-active">{{ $currentHead ?? '-' }}</span></p>
@if($publishedId)<p id="gear-published" style="overflow-wrap:anywhere">{{ $publishedId }}</p>@endif
@if($previewText)<pre id="gear-preview" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $previewText }}</pre>@endif
<x-filament::section heading="Versi tersimpan">
@forelse($releases as $release)<div data-testid="gear-release-{{ $release->id }}" style="overflow-wrap:anywhere;margin:.5rem 0">Revisi {{ $release->draft_revision }} — {{ $release->id }}
<x-filament::button type="button" size="sm" wire:click="activateRelease('{{ $release->id }}')" wire:confirm="Aktifkan versi evaluasi ini? Inventory dan saldo tidak dikembalikan oleh rollback.">Aktifkan evaluasi</x-filament::button></div>@empty<p>Belum ada versi terbit.</p>@endforelse
</x-filament::section>
<x-filament::section heading="Draft equipment">
@foreach($drafts as $draft)<div style="overflow-wrap:anywhere;margin:.5rem 0">{{ $draft->content_key }} — revisi {{ $draft->revision }} <x-filament::button type="button" size="sm" color="gray" wire:click="loadDraft({{ $draft->id }})">Muat</x-filament::button></div>@endforeach
</x-filament::section>
</x-filament-panels::page>
