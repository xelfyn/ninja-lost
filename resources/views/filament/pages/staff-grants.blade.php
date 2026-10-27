<x-filament-panels::page>
    <x-filament::section heading="Pemberian reward terkontrol">
        <p>Evaluasi privat. Grant memakai saldo, inventory, receipt, ledger, dan batas supply yang sama dengan portal. Command massal legacy dikarantina.</p>
        <form wire:submit="grant" style="display:grid;gap:1rem;max-width:40rem;margin-top:1.5rem">
            <label for="staff-character">ID karakter penerima
                <x-filament::input.wrapper><x-filament::input id="staff-character" wire:model="characterId" type="number" min="1" required /></x-filament::input.wrapper>
            </label>
            <label for="staff-type">Jenis reward
                <x-filament::input.wrapper><x-filament::input.select id="staff-type" wire:model="rewardType">
                    <option value="tokens">Token akun</option><option value="gold">Gold karakter</option><option value="item">Item</option><option value="skill">Skill</option><option value="pet">Pet</option>
                </x-filament::input.select></x-filament::input.wrapper>
            </label>
            <label for="staff-content">ID konten (kosong untuk token/gold)
                <x-filament::input.wrapper><x-filament::input id="staff-content" wire:model="contentKey" maxlength="100" /></x-filament::input.wrapper>
            </label>
            <label for="staff-quantity">Jumlah
                <x-filament::input.wrapper><x-filament::input id="staff-quantity" wire:model="quantity" type="number" min="1" required /></x-filament::input.wrapper>
            </label>
            <label for="staff-reason">Alasan grant (8–160 karakter)
                <x-filament::input.wrapper><x-filament::input id="staff-reason" wire:model="reason" minlength="8" maxlength="160" required /></x-filament::input.wrapper>
            </label>
            @if ($errors->any())
                <div role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
            @endif
            <p style="overflow-wrap:anywhere">Request key: <code id="staff-request-key">{{ $requestKey }}</code></p>
            @if ($receiptId !== '')
                <p role="status" style="overflow-wrap:anywhere">Receipt: <code id="staff-receipt">{{ $receiptId }}</code></p>
                <x-filament::button wire:click="newGrant" type="button">Grant baru</x-filament::button>
            @else
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="grant">Terbitkan grant</x-filament::button>
            @endif
        </form>
    </x-filament::section>
</x-filament-panels::page>
