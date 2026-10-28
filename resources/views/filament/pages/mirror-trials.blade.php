<x-filament-panels::page>
    <x-filament::section>
        <p>Mode evaluasi cermin: snapshot karakter melawan mirror dengan serangan weapon otomatis. Bukan konfigurasi musuh misi legacy. Reward gold/XP memakai ledger game yang sama.</p>
        <p>Aturan tidak dapat diedit setelah dibuat; buat aturan baru untuk perubahan. Pause menutup pendaftaran baru, bukan membatalkan reward battle yang sudah dimulai. Kuota kemenangan berlaku per akun.</p>
        @if ($errors->any()) <div role="alert">{{ $errors->first() }}</div> @endif
        @if ($createdId !== '')
            <p>Aturan tersimpan nonaktif: <strong id="trial-created">{{ $createdId }}</strong>. Tinjau dan aktifkan pada daftar.</p>
            <x-filament::button wire:click="newTrial">Aturan baru</x-filament::button>
        @else
            <form wire:submit="createTrial" class="space-y-4">
                <label class="block">Slug <input id="trial-slug" wire:model="trialSlug" required maxlength="60"></label>
                <label class="block">Judul <input id="trial-title" wire:model="trialTitle" required maxlength="120"></label>
                <label class="block">Level minimum <input id="trial-level" type="number" min="1" max="85" wire:model="minimumLevel" required></label>
                <label class="block">Reward gold <input id="trial-gold" type="number" min="0" max="100000000" wire:model="rewardGold" required></label>
                <label class="block">Reward XP dasar <input id="trial-xp" type="number" min="0" max="100000000" wire:model="rewardXp" required></label>
                <label class="block">Batas kemenangan per akun <input id="trial-limit" type="number" min="1" max="1000" wire:model="winLimit" required></label>
                <x-filament::button type="submit" wire:loading.attr="disabled">Simpan nonaktif</x-filament::button>
            </form>
        @endif
    </x-filament::section>
    @foreach ($trials as $trial)
        <x-filament::section wire:key="trial-{{ $trial->id }}">
            <div data-testid="trial-row-{{ $trial->id }}">
                <h2>{{ $trial->title }} — {{ $trial->active ? 'Aktif' : 'Nonaktif' }}</h2>
                <p>Level {{ $trial->req_lvl }} · {{ $trial->gold }} gold · {{ $trial->xp }} XP dasar · maksimal {{ $trial->win_limit }} kemenangan per akun.</p>
                @if ($trial->active)
                    <x-filament::button wire:key="trial-pause-{{ $trial->id }}" wire:click="pause({{ $trial->id }})" wire:loading.attr="disabled" wire:confirm="Nonaktifkan pendaftaran baru? Reward battle yang sudah dimulai tetap berlaku.">Nonaktifkan</x-filament::button>
                @else
                    <x-filament::button wire:key="trial-activate-{{ $trial->id }}" wire:click="activate({{ $trial->id }})" wire:loading.attr="disabled" wire:confirm="Aktifkan aturan dan reward ini untuk evaluasi?">Aktifkan</x-filament::button>
                @endif
            </div>
        </x-filament::section>
    @endforeach
    {{ $trials->links() }}
</x-filament-panels::page>
