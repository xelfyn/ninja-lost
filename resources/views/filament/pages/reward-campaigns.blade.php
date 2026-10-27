<x-filament-panels::page>
    @include('rewards.table-style')
    <p>Campaign selalu dibuat sebagai draft. Satu klaim per akun per campaign; token masuk akun, gold dan konten masuk karakter terpilih. Isi campaign dan kuota tidak dapat ditimpa setelah dibuat: buat versi baru.</p>
    @error('reward')<p role="alert" class="nl-error">{{ $message }}</p>@enderror
    <x-filament::section heading="Campaign terbaru">
        <div class="nl-scroll"><table class="nl-table"><thead><tr><th>Campaign</th><th>Status</th><th>Klaim / kuota</th><th>Tindakan</th></tr></thead><tbody>
        @forelse($campaigns as $campaign)<tr wire:key="campaign-{{ $campaign->id }}"><td>{{ $campaign->title }}<br>{{ $campaign->kind }}</td><td>{{ $campaign->status }}</td><td>{{ $campaign->claimed_count }} / {{ $campaign->max_claims }}</td><td><div class="nl-actions">
            @if($campaign->status !== 'active')<x-filament::button wire:click="changeStatus({{ $campaign->id }}, 'active')" wire:loading.attr="disabled">Aktifkan</x-filament::button>@else<x-filament::button wire:click="changeStatus({{ $campaign->id }}, 'paused')" wire:loading.attr="disabled" color="gray">Jeda</x-filament::button>@endif
        </div></td></tr>@empty<tr><td colspan="4">Belum ada campaign. Tidak ada reward live bawaan.</td></tr>@endforelse
        </tbody></table></div>
        <p>Ditampilkan maksimal 100 campaign terbaru. Aktivasi memeriksa registri; klaim tetap memeriksa jadwal dan supply secara transaksional.</p>
    </x-filament::section>
    <x-filament::section heading="Buat draft campaign">
        <form wire:submit="createCampaign" class="nl-form">{{ $this->form }}<x-filament::button type="submit" wire:loading.attr="disabled">Simpan draft campaign</x-filament::button></form>
    </x-filament::section>
</x-filament-panels::page>
