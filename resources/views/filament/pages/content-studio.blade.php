<x-filament-panels::page>
    @include('rewards.table-style')
    <p>Editor mekanik skill, bukan desain final. Draft → validasi → versi immutable → aktivasi evaluasi. Tidak ada upload kode/SWF, aktivasi client, atau perubahan saldo lewat halaman ini.</p>
    @foreach($errors->all() as $message)<p role="alert" class="nl-error">{{ $message }}</p>@endforeach
    <p>Draft: <strong id="studio-draft">{{ $draftId ?? 'baru' }}</strong> · Revision: <span id="studio-revision">{{ $draftRevision }}</span></p>
    <p style="overflow-wrap:anywhere">Versi aktif saat dimuat: <strong id="studio-active">{{ $currentHead ?? 'belum ada' }}</strong></p>
    <div class="flex flex-wrap gap-3">
        <x-filament::button wire:click="newDraft" color="gray">Draft baru</x-filament::button>
        <x-filament::button wire:click="duplicateDraft" color="gray">Duplikasi sebagai ID baru</x-filament::button>
    </div>
    <form wire:submit="saveDraft" class="space-y-6">
        {{ $this->form }}
        <div class="flex flex-wrap gap-3">
            <x-filament::button type="submit" wire:loading.attr="disabled">Simpan draft</x-filament::button>
            <x-filament::button type="button" wire:click="previewDefinition" wire:loading.attr="disabled" color="gray">Validasi dan tooltip</x-filament::button>
            <x-filament::button type="button" wire:click="publishDraft" wire:loading.attr="disabled" :disabled="$draftId === null">Terbitkan versi tersimpan</x-filament::button>
        </div>
    </form>
    @if($previewText !== '')<x-filament::section heading="Tooltip dari aturan"><pre id="studio-preview" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $previewText }}</pre><p>Damage akhir tetap mengikuti statistik dan interaksi engine. Cerita tidak mengubah efek.</p></x-filament::section>@endif
    @if($publishedId !== '')<p style="overflow-wrap:anywhere">Versi baru: <strong id="studio-published">{{ $publishedId }}</strong>. Aktivasi dilakukan terpisah pada daftar versi.</p>@endif
    <x-filament::section heading="Versi dan aktivasi">
        <p>Aktivasi versi lama adalah rollback konfigurasi, bukan penghapusan inventory atau pembatalan hadiah. Versi dalam satu battle harus tetap dipin. Maksimal 20 versi terakhir ditampilkan.</p>
        <div class="nl-scroll"><table class="nl-table"><thead><tr><th>Revision draft</th><th>Checksum paket</th><th>Tindakan</th></tr></thead><tbody>
        @forelse($releases as $release)
            <tr data-testid="studio-release-{{ $release->id }}" wire:key="studio-release-{{ $release->id }}"><td>{{ $release->draft_revision }}</td><td><code>{{ substr($release->payload_hash, 0, 16) }}</code></td><td>
            @if($currentHead === $release->id)<span>Aktif untuk evaluasi</span>
            @else<x-filament::button wire:click="activateRelease('{{ $release->id }}')" wire:confirm="Aktifkan versi mekanik evaluasi ini? Animasi belum diverifikasi dan game publik tetap tertutup." wire:loading.attr="disabled">Aktifkan evaluasi</x-filament::button>@endif
            </td></tr>
        @empty<tr><td colspan="3">Belum ada versi diterbitkan untuk draft ini.</td></tr>@endforelse
        </tbody></table></div>
    </x-filament::section>
    <x-filament::section heading="Draft tersimpan">
        <p>30 draft terakhir. Muat ulang untuk memperoleh revision dan head terbaru sebelum perubahan bersama.</p>
        <div class="nl-scroll"><table class="nl-table"><thead><tr><th>ID</th><th>Revision</th><th></th></tr></thead><tbody>
        @forelse($drafts as $draft)<tr wire:key="draft-{{ $draft->id }}"><td>{{ $draft->content_key }}</td><td>{{ $draft->revision }}</td><td><x-filament::button wire:click="loadDraft({{ $draft->id }})" color="gray">Muat #{{ $draft->id }}</x-filament::button></td></tr>
        @empty<tr><td colspan="3">Belum ada draft. Tidak ada data komunitas yang diaktifkan otomatis.</td></tr>@endforelse
        </tbody></table></div>
    </x-filament::section>
    <x-filament::section heading="Kamus mekanik profil awal">
        <p>Field efek dan compiler memakai registry yang sama. Efek lain, stat equipment, set bonus, talent, senjutsu, dan template event belum menjadi kemampuan editor ini.</p>
        @foreach($mechanics as $code => $rule)<p><strong>{{ $rule['label'] }}</strong> ({{ $code }}) — {{ $rule['target'] }}; nilai {{ $rule['min_amount'] }}–{{ $rule['max_amount'] }} {{ $rule['unit'] }}; durasi {{ $rule['min_duration'] }}–{{ $rule['max_duration'] }}. {{ $rule['timing'] }}</p>@endforeach
    </x-filament::section>
</x-filament-panels::page>
