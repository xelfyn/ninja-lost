<x-filament-panels::page>
    @include('rewards.table-style')
    <p>Portal evaluasi terhubung ke akun, saldo, dan inventory backend yang sama. Ini belum merupakan client game playable.</p>
    @error('reward') <p role="alert" class="nl-error">{{ $message }}</p> @enderror
    <x-filament::section heading="Karakter aktif">
        <label for="nl-character">Pilih karakter milik akun Anda</label>
        <x-filament::input.wrapper>
            <x-filament::input.select id="nl-character" wire:model.live="characterId">
                <option value="">Pilih karakter</option>
                @foreach($characters as $entry)<option value="{{ $entry->id }}">{{ $entry->name }}</option>@endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
        @error('characterId')<p role="alert">{{ $message }}</p>@enderror
        @if($character)
            <dl class="nl-facts">
                <div><dt>Karakter</dt><dd>{{ $character->name }}</dd></div>
                <div><dt>Level</dt><dd>{{ $character->level }}</dd></div>
                <div><dt>Token akun</dt><dd data-testid="nl-tokens">{{ $tokens }}</dd></div>
                <div><dt>Gold karakter</dt><dd data-testid="nl-gold">{{ $character->gold }}</dd></div>
            </dl>
        @else
            <p>Belum ada karakter dipilih. Pembuatan karakter publik belum dibuka pada build evaluasi ini.</p>
        @endif
    </x-filament::section>
    <x-filament::section heading="Starter pack">
        <p>Satu klaim per akun untuk setiap campaign, termasuk saat berganti karakter.</p>
        <form wire:submit="claimStarter" class="nl-form">
            <label for="nl-campaign">Campaign</label>
            <x-filament::input.wrapper><x-filament::input.select id="nl-campaign" wire:model.live="campaignId">
                <option value="">Pilih campaign aktif</option>
                @foreach($campaigns as $entry)<option value="{{ $entry->id }}">{{ $entry->title }}</option>@endforeach
            </x-filament::input.select></x-filament::input.wrapper>
            @error('campaignId')<p role="alert">{{ $message }}</p>@enderror
            <label for="nl-choice">Pilihan hadiah</label>
            <x-filament::input.wrapper><x-filament::input.select id="nl-choice" wire:model="choiceKey">
                <option value="">Pilih hadiah</option>
                @foreach($choices as $choice)<option value="{{ $choice['key'] }}">{{ $choice['label'] }}</option>@endforeach
            </x-filament::input.select></x-filament::input.wrapper>
            @error('choiceKey')<p role="alert">{{ $message }}</p>@enderror
            @foreach($choices as $choice)
                <p><strong>{{ $choice['label'] }}:</strong> @foreach($choice['rewards'] as $reward){{ $reward['quantity'] }} {{ $reward['type'] }} {{ $reward['key'] }}@if(!$loop->last), @endif @endforeach</p>
            @endforeach
            @if($campaigns->isEmpty())<p>Tidak ada starter pack yang tersedia untuk akun ini.</p>@endif
            <x-filament::button type="submit" wire:loading.attr="disabled" :disabled="!$character || !$campaignId || !$choiceKey">Klaim starter pack</x-filament::button>
        </form>
    </x-filament::section>
    <x-filament::section heading="Redeem kode">
        <form wire:submit="redeem" class="nl-form">
            <label for="nl-code">Kode hadiah</label>
            <x-filament::input.wrapper><x-filament::input id="nl-code" wire:model="redeemCode" maxlength="64" autocomplete="off" /></x-filament::input.wrapper>
            @error('redeemCode')<p role="alert">{{ $message }}</p>@enderror
            <label for="nl-redeem-choice">ID pilihan dari pengumuman campaign</label>
            <x-filament::input.wrapper><x-filament::input id="nl-redeem-choice" wire:model="redeemChoice" maxlength="40" /></x-filament::input.wrapper>
            <p>Gunakan <code>default</code> untuk campaign dengan satu pilihan standar. Kode tidak disimpan di URL atau riwayat.</p>
            <x-filament::button type="submit" wire:loading.attr="disabled" :disabled="!$character">Redeem hadiah</x-filament::button>
        </form>
    </x-filament::section>
    <x-filament::section heading="Inventory karakter">
        <label for="nl-inventory-kind">Jenis inventory</label>
        <x-filament::input.wrapper><x-filament::input.select id="nl-inventory-kind" wire:model.live="inventoryKind">
            <option value="item">Item</option><option value="skill">Skill</option><option value="pet">Pet</option>
        </x-filament::input.select></x-filament::input.wrapper>
        <div class="nl-scroll"><table class="nl-table"><thead><tr><th>Konten</th><th>ID</th><th>Jumlah</th></tr></thead><tbody>
            @forelse($inventory as $entry)<tr><td>{{ $entry->title ?? 'Definisi belum tersedia' }}</td><td>{{ $entry->content_key }}</td><td>{{ $entry->quantity }}</td></tr>
            @empty<tr><td colspan="3">Inventory jenis ini belum berisi konten.</td></tr>@endforelse
        </tbody></table></div>
        <div class="nl-pagination">
            <x-filament::button wire:click="previousPage('inventoryPage')" :disabled="$inventory->onFirstPage()" color="gray">Sebelumnya</x-filament::button>
            <span>{{ $inventory->currentPage() }} / {{ $inventory->lastPage() }} · {{ $inventory->total() }} entri</span>
            <x-filament::button wire:click="nextPage('inventoryPage')" :disabled="!$inventory->hasMorePages()" color="gray">Berikutnya</x-filament::button>
        </div>
    </x-filament::section>
    <x-filament::section heading="Riwayat klaim">
        <p>20 klaim terakhir akun. Riwayat ini dibaca dari transaksi yang sudah berhasil.</p>
        <div class="nl-scroll"><table class="nl-table"><thead><tr><th>Campaign</th><th>Hadiah</th><th>Waktu (UTC)</th></tr></thead><tbody>
            @forelse($history as $claim)<tr><td>{{ $claim->reason }}</td><td>@foreach(json_decode($claim->granted, true) as $line){{ $line['quantity'] }} {{ $line['type'] }} {{ $line['key'] }}@if(!$loop->last)<br>@endif @endforeach</td><td>{{ $claim->created_at }}</td></tr>
            @empty<tr><td colspan="3">Belum ada klaim berhasil.</td></tr>@endforelse
        </tbody></table></div>
    </x-filament::section>
</x-filament-panels::page>
