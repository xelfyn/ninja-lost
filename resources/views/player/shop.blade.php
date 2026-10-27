<x-filament-panels::page>
    @include('rewards.table-style')
    <p>Toko evaluasi memakai saldo dan inventory backend yang sama. Ini bukan client game playable atau pembayaran uang nyata. Ditampilkan maksimal 100 penawaran aktif terbaru.</p>
    @foreach($errors->all() as $message)<p role="alert" class="nl-error">{{ $message }}</p>@endforeach
    <x-filament::section heading="Saldo dan pembelian">
        <dl class="nl-facts"><div><dt>Token akun</dt><dd data-testid="shop-tokens">{{ $tokens }}</dd></div><div><dt>Gold karakter</dt><dd data-testid="shop-gold">{{ $character?->gold ?? 0 }}</dd></div></dl>
        <p>Identitas permintaan: <code id="shop-request-key" style="overflow-wrap:anywhere">{{ $requestKey }}</code></p>
        @if($receiptId !== '')
            <p>Pembelian tercatat: <strong id="shop-receipt" style="overflow-wrap:anywhere">{{ $receiptId }}</strong>. Mengulang permintaan ini tidak mengurangi saldo lagi.</p>
            <x-filament::button wire:click="newPurchase" color="gray">Pembelian baru</x-filament::button>
        @else
            <form wire:submit="buy" class="nl-form">
                <label for="shop-character">Karakter penerima</label><x-filament::input.wrapper><x-filament::input.select id="shop-character" wire:model.live="characterId"><option value="">Pilih karakter</option>@foreach($characters as $entry)<option value="{{ $entry->id }}">{{ $entry->name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
                <label for="shop-offer">Penawaran aktif</label><x-filament::input.wrapper><x-filament::input.select id="shop-offer" wire:model.live="offerId"><option value="">Pilih penawaran</option>@foreach($offers as $offer)<option value="{{ $offer->id }}">{{ $offer->title }} — {{ $offer->price_tokens }} token + {{ $offer->price_gold }} gold / unit</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
                <label for="shop-quantity">Jumlah</label><x-filament::input.wrapper><x-filament::input id="shop-quantity" wire:model.live="quantity" type="number" min="1" max="1000" /></x-filament::input.wrapper>
                @if($selected)<p>Konten: {{ $selected->kind }} / {{ $selected->content_key }}. Maksimum {{ $selected->max_quantity }} unit.</p><p data-testid="shop-total">Total: {{ $selected->price_tokens * $units }} token + {{ $selected->price_gold * $units }} gold.</p>@endif
                @if($offers->isEmpty())<p>Belum ada penawaran aktif.</p>@endif
                <p>Server memeriksa ulang harga, saldo, stok, level, dan kepemilikan saat transaksi. Skill memerlukan slot elemen yang sesuai.</p>
                <x-filament::button type="submit" wire:loading.attr="disabled" :disabled="!$character || !$selected || !$units">Beli dengan saldo game</x-filament::button>
            </form>
        @endif
    </x-filament::section>
    <x-filament::section heading="Riwayat pembelian">
        <p>20 transaksi terakhir akun. Token dan gold di kolom biaya adalah saldo yang dikurangi, bukan hadiah.</p>
        <div class="nl-scroll"><table class="nl-table"><thead><tr><th>Pembelian</th><th>Jumlah</th><th>Biaya</th><th>Waktu (UTC)</th></tr></thead><tbody>
            @forelse($history as $entry)<tr><td>{{ $entry->reason }}</td><td>{{ $entry->quantity }}</td><td>{{ $entry->charged_tokens }} token + {{ $entry->charged_gold }} gold</td><td>{{ $entry->created_at }}</td></tr>
            @empty<tr><td colspan="4">Belum ada pembelian berhasil.</td></tr>@endforelse
        </tbody></table></div>
    </x-filament::section>
</x-filament-panels::page>
