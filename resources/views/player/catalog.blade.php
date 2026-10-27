<x-filament-panels::page>
    @include('rewards.table-style')
    <p>Hanya entri registri yang diaktifkan operator. Metadata efek bukan bukti implementasi efek battle atau kompatibilitas Android.</p>
    <div class="nl-form">
        <label for="nl-catalog-search">Cari nama atau ID konten</label>
        <x-filament::input.wrapper><x-filament::input id="nl-catalog-search" wire:model.live.debounce.350ms="search" maxlength="100" /></x-filament::input.wrapper>
        <label for="nl-catalog-kind">Jenis konten</label>
        <x-filament::input.wrapper><x-filament::input.select id="nl-catalog-kind" wire:model.live="kind"><option value="">Semua</option><option value="item">Item</option><option value="skill">Skill</option><option value="pet">Pet</option><option value="effect">Efek</option></x-filament::input.select></x-filament::input.wrapper>
    </div>
    <div class="nl-scroll"><table class="nl-table"><thead><tr><th>Konten</th><th>Jenis / ID</th><th>Versi sumber</th><th>Sisa alokasi reward</th></tr></thead><tbody>
        @forelse($entries as $entry)<tr><td>{{ $entry->title }}</td><td>{{ $entry->kind }} / {{ $entry->content_key }}</td><td>{{ $entry->source_revision }}</td><td>{{ $entry->supply_limit === null ? 'Tidak dibatasi registri' : max(0, $entry->supply_limit - $entry->issued) }}</td></tr>
        @empty<tr><td colspan="4">Belum ada konten aktif yang cocok. Katalog komunitas tidak diimpor otomatis.</td></tr>@endforelse
    </tbody></table></div>
    <div class="nl-pagination"><x-filament::button wire:click="previousPage" :disabled="$entries->onFirstPage()" color="gray">Sebelumnya</x-filament::button><span>{{ $entries->currentPage() }} / {{ $entries->lastPage() }} · {{ $entries->total() }} entri</span><x-filament::button wire:click="nextPage" :disabled="!$entries->hasMorePages()" color="gray">Berikutnya</x-filament::button></div>
</x-filament-panels::page>
