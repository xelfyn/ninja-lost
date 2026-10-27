<x-filament-panels::page>
    <style>
        .nsx-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem}
        .nsx-stat{border:1px solid #d1d5db;border-radius:.65rem;padding:1rem}
        .nsx-stat-label{font-size:.875rem;opacity:.75}
        .nsx-stat-value{font-size:1.9rem;font-weight:700;line-height:1.3;margin-top:.35rem}
        .nsx-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem}
        .nsx-module{display:flex;justify-content:space-between;gap:1rem;padding:.9rem 1rem;border:1px solid #d1d5db;border-radius:.5rem;text-decoration:none;color:inherit}
        .nsx-module:hover{border-color:#a16207}
        .nsx-module:focus-visible{outline:3px solid #ca8a04;outline-offset:2px}
        .nsx-meta{font-size:.85rem;opacity:.75}
        .nsx-row{display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap}
        .nsx-note{border-left:3px solid #b45309;padding:.8rem 1rem;background:#fffbeb;color:#422006}
        .nsx-search{width:100%;padding:.75rem 1rem;border:1px solid #9ca3af;border-radius:.5rem;background:transparent;color:inherit;margin-bottom:1rem}
        .dark .nsx-stat,.dark .nsx-module{border-color:#4b5563}
        .dark .nsx-note{background:#292524;color:#fef3c7}
        @media(max-width:640px){.nsx-grid,.nsx-list{grid-template-columns:1fr}.nsx-stat-value{font-size:1.55rem}}
    </style>

    <x-filament::section>
        <x-slot name="heading">{{ $applicationName }} · Pengelolaan web</x-slot>
        <div class="nsx-row">
            <p>Satu panel untuk modul yang benar-benar terdaftar pada backend ini.</p>
            <x-filament::button color="gray" wire:click="$refresh" wire:loading.attr="disabled">
                Periksa ulang
            </x-filament::button>
        </div>
        <p class="nsx-meta">Pemeriksaan: {{ $checkedAt }} · Database: {{ $databaseReachable ? 'dapat diakses' : 'belum terverifikasi / bermasalah' }}</p>
    </x-filament::section>

    <div class="nsx-grid">
        @foreach ($totals as $label => $total)
            <div class="nsx-stat">
                <div class="nsx-stat-label">{{ $label }}</div>
                <div class="nsx-stat-value">{{ $total === null ? 'Belum tersedia' : number_format($total, 0, ',', '.') }}</div>
                <div class="nsx-meta">Jumlah data tersimpan; bukan pemain online.</div>
            </div>
        @endforeach
    </div>

    <x-filament::section>
        <x-slot name="heading">Modul backend · {{ count($modules) }} dapat dibuka</x-slot>
        <div x-data="{ query: '' }">
            <label for="nsx-module-search" class="nsx-meta">Cari pengaturan, reward, skill, item, atau karakter</label>
            <input id="nsx-module-search" class="nsx-search" type="search" x-model="query" placeholder="Cari modul…" autocomplete="off">
            <div class="nsx-list">
                @forelse ($modules as $module)
                    <a class="nsx-module" href="{{ $module['url'] }}" x-show="$el.textContent.toLocaleLowerCase().includes(query.toLocaleLowerCase())">
                        <span><strong>{{ $module['label'] }}</strong><br><span class="nsx-meta">{{ $module['kind'] }}</span></span>
                        <span aria-hidden="true">→</span>
                    </a>
                @empty
                    <p>Belum ada modul yang terdaftar dan diizinkan untuk akun ini.</p>
                @endforelse
            </div>
        </div>
        @if ($unresolved > 0)
            <p class="nsx-note">{{ $unresolved }} modul gagal diperiksa. Jangan anggap semua menu sudah berfungsi.</p>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Batas integrasi pada tahap ini</x-slot>
        <p class="nsx-note">Halaman ini tidak membuat akun game kedua dan tidak menerbitkan hadiah melalui jalur baru. Pengelolaan memakai modul backend yang sudah ada. Koneksi Discord dan client Android belum diverifikasi.</p>
        <p style="margin-top:1rem">Perubahan konfigurasi belum tentu langsung tampil pada client yang sedang online. Uji refresh, login ulang, dan konsistensi inventory sebelum mengaktifkan event publik.</p>
    </x-filament::section>
</x-filament-panels::page>
