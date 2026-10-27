<x-filament-panels::page>
    @include('rewards.table-style')
    <p>Toko evaluasi item dan skill. Harga menggunakan mata uang game, bukan pembayaran uang nyata. Harga dan isi penawaran tidak dapat diedit; buat penawaran baru untuk perubahan.</p>
    @foreach($errors->all() as $message)<p role="alert" class="nl-error">{{ $message }}</p>@endforeach
    <x-filament::section heading="Buat penawaran">
        @if($createdOfferId !== '')
            <p>Penawaran tersimpan nonaktif: <strong id="offer-created">{{ $createdOfferId }}</strong>. Tinjau harga pada tabel sebelum mengaktifkan.</p>
            <x-filament::button wire:click="newOffer" color="gray">Penawaran baru</x-filament::button>
        @else
            <form wire:submit="createOffer" class="nl-form">
                <label for="offer-slug">Slug unik</label><x-filament::input.wrapper><x-filament::input id="offer-slug" wire:model="offerSlug" maxlength="80" /></x-filament::input.wrapper>
                <label for="offer-title">Judul penawaran</label><x-filament::input.wrapper><x-filament::input id="offer-title" wire:model="offerTitle" maxlength="120" /></x-filament::input.wrapper>
                <label for="offer-catalog">ID entri Content Registry yang sudah aktif</label><x-filament::input.wrapper><x-filament::input id="offer-catalog" wire:model="catalogId" type="number" min="1" /></x-filament::input.wrapper>
                <label for="offer-tokens">Harga token per unit</label><x-filament::input.wrapper><x-filament::input id="offer-tokens" wire:model="priceTokens" type="number" min="0" /></x-filament::input.wrapper>
                <label for="offer-gold">Harga gold per unit</label><x-filament::input.wrapper><x-filament::input id="offer-gold" wire:model="priceGold" type="number" min="0" /></x-filament::input.wrapper>
                <p>Apabila kedua harga lebih dari nol, keduanya dibebankan. Tidak ada pemilihan mata uang otomatis.</p>
                <label for="offer-max">Jumlah maksimum per transaksi (skill: 1)</label><x-filament::input.wrapper><x-filament::input id="offer-max" wire:model="maxQuantity" type="number" min="1" max="1000" /></x-filament::input.wrapper>
                <x-filament::button type="submit" wire:loading.attr="disabled">Simpan nonaktif</x-filament::button>
            </form>
        @endif
    </x-filament::section>
    <x-filament::section heading="Penawaran tersimpan">
        <div class="nl-scroll"><table class="nl-table"><thead><tr><th>Penawaran / katalog</th><th>Harga per unit</th><th>Status</th><th>Tindakan</th></tr></thead><tbody>
            @forelse($offers as $offer)
                <tr wire:key="offer-{{ $offer->id }}" data-testid="offer-row-{{ $offer->id }}"><td>{{ $offer->title }}<br>#{{ $offer->id }} / katalog #{{ $offer->catalog_id }}</td><td>{{ $offer->price_tokens }} token + {{ $offer->price_gold }} gold<br>Maks. {{ $offer->max_quantity }} unit</td><td>{{ $offer->active ? 'Aktif' : 'Nonaktif' }}</td><td>
                    @if($offer->active)<x-filament::button wire:click="pause({{ $offer->id }})" wire:loading.attr="disabled" color="gray">Nonaktifkan</x-filament::button>
                    @else<x-filament::button wire:click="activate({{ $offer->id }})" wire:confirm="Aktifkan penawaran dengan harga yang tercantum?" wire:loading.attr="disabled">Aktifkan</x-filament::button>@endif
                </td></tr>
            @empty<tr><td colspan="4">Belum ada penawaran. Tidak ada harga atau konten komunitas yang diaktifkan otomatis.</td></tr>@endforelse
        </tbody></table></div>
        <div class="nl-pagination"><x-filament::button wire:click="previousPage" :disabled="$offers->onFirstPage()" color="gray">Sebelumnya</x-filament::button><span>{{ $offers->currentPage() }} / {{ $offers->lastPage() }}</span><x-filament::button wire:click="nextPage" :disabled="!$offers->hasMorePages()" color="gray">Berikutnya</x-filament::button></div>
    </x-filament::section>
</x-filament-panels::page>
