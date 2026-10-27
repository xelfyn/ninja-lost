<x-filament-panels::page>
    @include('rewards.table-style')
    <style>
        .nl-offer-cards { display: none; }
        .nl-offer-card { padding: 16px; border: 1px solid #d1d5db; border-radius: 10px; }
        .nl-offer-card h3 { font-weight: 600; margin-bottom: 4px; overflow-wrap: anywhere; }
        .nl-offer-card dl { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 16px 0; }
        .nl-offer-card dt { font-size: 12px; opacity: .75; }
        .nl-offer-card dd { font-weight: 600; overflow-wrap: anywhere; }
        @media (max-width: 640px) {
            .nl-offer-table { display: none; }
            .nl-offer-cards { display: grid; gap: 16px; }
        }
    </style>
    <p>Toko evaluasi item dan skill. Harga menggunakan mata uang game, bukan pembayaran uang nyata. Harga dan isi penawaran tidak dapat diedit; buat penawaran baru untuk perubahan.</p>
    @foreach($errors->all() as $message)<p role="alert" class="nl-error">{{ $message }}</p>@endforeach
    <x-filament::section heading="Buat penawaran">
        @if($createdOfferId !== '')
            <p>Penawaran tersimpan: <strong id="offer-created">{{ $createdOfferId }}</strong>. Lihat status terbaru pada daftar di bawah.</p>
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
        <div class="nl-scroll nl-offer-table"><table class="nl-table"><thead><tr><th>Penawaran / katalog</th><th>Harga per unit</th><th>Status</th><th>Tindakan</th></tr></thead><tbody>
            @forelse($offers as $offer)
                <tr wire:key="offer-{{ $offer->id }}" data-testid="offer-row-{{ $offer->id }}"><td>{{ $offer->title }}<br>#{{ $offer->id }} / katalog #{{ $offer->catalog_id }}</td><td>{{ $offer->price_tokens }} token + {{ $offer->price_gold }} gold<br>Maks. {{ $offer->max_quantity }} unit</td><td>{{ $offer->active ? 'Aktif' : 'Nonaktif' }}</td><td>
                    @if($offer->active)<x-filament::button wire:click="pause({{ $offer->id }})" wire:loading.attr="disabled" color="gray">Nonaktifkan</x-filament::button>
                    @else<x-filament::button wire:click="activate({{ $offer->id }})" wire:confirm="Aktifkan penawaran dengan harga yang tercantum?" wire:loading.attr="disabled">Aktifkan</x-filament::button>@endif
                </td></tr>
            @empty<tr><td colspan="4">Belum ada penawaran. Tidak ada harga atau konten komunitas yang diaktifkan otomatis.</td></tr>@endforelse
        </tbody></table></div>
        <div class="nl-offer-cards">
            @forelse($offers as $offer)
                <article class="nl-offer-card" wire:key="offer-card-{{ $offer->id }}" data-testid="offer-card-{{ $offer->id }}" aria-label="{{ $offer->title }}">
                    <h3>{{ $offer->title }}</h3>
                    <p>#{{ $offer->id }} / katalog #{{ $offer->catalog_id }}</p>
                    <dl>
                        <div><dt>Token per unit</dt><dd>{{ $offer->price_tokens }}</dd></div>
                        <div><dt>Gold per unit</dt><dd>{{ $offer->price_gold }}</dd></div>
                        <div><dt>Batas transaksi</dt><dd>{{ $offer->max_quantity }} unit</dd></div>
                        <div><dt>Status</dt><dd>{{ $offer->active ? 'Aktif' : 'Nonaktif' }}</dd></div>
                    </dl>
                    @if($offer->active)<x-filament::button wire:click="pause({{ $offer->id }})" wire:loading.attr="disabled" color="gray">Nonaktifkan</x-filament::button>
                    @else<x-filament::button wire:click="activate({{ $offer->id }})" wire:confirm="Aktifkan penawaran dengan harga yang tercantum?" wire:loading.attr="disabled">Aktifkan</x-filament::button>@endif
                </article>
            @empty<p>Belum ada penawaran.</p>@endforelse
        </div>
        <div class="nl-pagination"><x-filament::button wire:click="previousPage" :disabled="$offers->onFirstPage()" color="gray">Sebelumnya</x-filament::button><span>{{ $offers->currentPage() }} / {{ $offers->lastPage() }}</span><x-filament::button wire:click="nextPage" :disabled="!$offers->hasMorePages()" color="gray">Berikutnya</x-filament::button></div>
    </x-filament::section>
</x-filament-panels::page>
