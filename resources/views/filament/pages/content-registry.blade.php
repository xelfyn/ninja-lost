<x-filament-panels::page>
    @include('rewards.table-style')
    <p>Registri mencatat sumber, revisi, dasar penggunaan, kompatibilitas, dan alokasi reward. Pernyataan operator bukan verifikasi lisensi otomatis. Definisi item/skill/pet harus sudah ada di katalog backend; metadata efek tidak menjalankan kode battle.</p>
    @error('reward')<p role="alert" class="nl-error">{{ $message }}</p>@enderror
    <x-filament::section heading="Konten terdaftar">
        <div class="nl-scroll"><table class="nl-table"><thead><tr><th>Konten</th><th>Revisi / dasar hak</th><th>Alokasi awal + penerbitan</th><th>Status</th></tr></thead><tbody>
            @forelse($entries as $entry)<tr wire:key="registry-{{ $entry->id }}"><td>{{ $entry->title }}<br>{{ $entry->kind }} / {{ $entry->content_key }}</td><td>{{ $entry->source_revision }}<br>{{ $entry->rights_basis }}</td><td>{{ $entry->issued }} / {{ $entry->supply_limit ?? 'tanpa batas registri' }}</td><td><x-filament::button wire:click="setEnabled({{ $entry->id }}, {{ $entry->enabled ? 'false' : 'true' }})" wire:loading.attr="disabled">{{ $entry->enabled ? 'Nonaktifkan' : 'Aktifkan' }}</x-filament::button></td></tr>
            @empty<tr><td colspan="4">Registri kosong. Tidak ada impor katalog atau aset otomatis.</td></tr>@endforelse
        </tbody></table></div>
        <p>100 entri terbaru. Provenance dan batas supply tidak ditimpa oleh halaman ini.</p>
    </x-filament::section>
    <x-filament::section heading="Daftarkan konten">
        <form wire:submit="registerContent" class="nl-form">{{ $this->form }}<x-filament::button type="submit" wire:loading.attr="disabled">Simpan registri nonaktif</x-filament::button></form>
    </x-filament::section>
</x-filament-panels::page>
