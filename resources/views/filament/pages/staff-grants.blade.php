<x-filament-panels::page>
    <x-filament::section heading="Issue a controlled grant">
        <p>
            Private evaluation workflow. Grants use the same wallet, inventory, receipt, ledger,
            ownership and supply controls as the player portal. Search by human-readable names;
            internal IDs remain secondary metadata.
        </p>

        <form wire:submit="grant" class="mt-6 flex max-w-2xl flex-col gap-5">
            <div class="flex flex-col gap-2">
                <label for="staff-character-search" class="font-medium">Recipient</label>
                <x-filament::input.wrapper>
                    <x-filament::input
                        id="staff-character-search"
                        wire:model.live.debounce.300ms="characterSearch"
                        autocomplete="off"
                        maxlength="100"
                        placeholder="Search character name, username, or character ID"
                        :disabled="$receiptId !== ''"
                    />
                </x-filament::input.wrapper>
                @error('characterSearch') <p role="alert">{{ $message }}</p> @enderror
                @error('characterId') <p role="alert">{{ $message }}</p> @enderror

                @if ($selectedCharacter)
                    <p role="status" data-testid="selected-character">
                        Selected: <strong>{{ $selectedCharacter['name'] }}</strong>
                        · Lv. {{ $selectedCharacter['level'] }}
                        · &#64;{{ $selectedCharacter['username'] }}
                        · <code>#{{ $selectedCharacter['id'] }}</code>
                    </p>
                @elseif (trim($characterSearch) !== '')
                    <div role="listbox" aria-label="Character search results" class="flex flex-col gap-2">
                        @forelse ($characterMatches as $entry)
                            <x-filament::button
                                type="button"
                                color="gray"
                                size="sm"
                                wire:key="grant-character-{{ $entry['id'] }}"
                                wire:click="selectCharacter({{ $entry['id'] }})"
                            >
                                {{ $entry['label'] }}
                            </x-filament::button>
                        @empty
                            <p>No matching characters.</p>
                        @endforelse
                    </div>
                @endif
            </div>

            <div class="flex flex-col gap-2">
                <label for="staff-type" class="font-medium">Reward type</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select
                        id="staff-type"
                        wire:model.live="rewardType"
                        :disabled="$receiptId !== ''"
                    >
                        <option value="tokens">Account Tokens</option>
                        <option value="gold">Character Gold</option>
                        <option value="item">Item</option>
                        <option value="skill">Skill</option>
                        <option value="pet">Pet</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                @error('rewardType') <p role="alert">{{ $message }}</p> @enderror
            </div>

            @if (in_array($rewardType, ['item', 'skill', 'pet'], true))
                <div class="flex flex-col gap-2">
                    <label for="staff-content-search" class="font-medium">Content</label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            id="staff-content-search"
                            wire:model.live.debounce.300ms="contentSearch"
                            autocomplete="off"
                            maxlength="100"
                            placeholder="Search {{ ucfirst($rewardType) }} by name or source ID"
                            :disabled="$receiptId !== ''"
                        />
                    </x-filament::input.wrapper>
                    @error('contentSearch') <p role="alert">{{ $message }}</p> @enderror
                    @error('contentKey') <p role="alert">{{ $message }}</p> @enderror

                    @if ($selectedContent)
                        <p role="status" data-testid="selected-content">
                            Selected: <strong>{{ $selectedContent['name'] }}</strong>
                            @if($selectedContent['level'] !== null) · Lv. {{ $selectedContent['level'] }} @endif
                            · <code>{{ $selectedContent['key'] }}</code>
                            · visual: {{ $selectedContent['asset']['visual_status'] }}
                        </p>
                    @elseif (trim($contentSearch) !== '')
                        <div role="listbox" aria-label="Content search results" class="flex flex-col gap-2">
                            @forelse ($contentMatches as $entry)
                                <x-filament::button
                                    type="button"
                                    color="gray"
                                    size="sm"
                                    wire:key="grant-content-{{ $entry['kind'] }}-{{ $entry['key'] }}"
                                    wire:click="selectContent(@js($entry['key']))"
                                >
                                    {{ $entry['label'] }}
                                    · visual: {{ $entry['asset']['visual_status'] }}
                                </x-filament::button>
                            @empty
                                <p>No enabled matching content.</p>
                            @endforelse
                        </div>
                    @endif
                </div>
            @else
                <p>Tokens and Gold do not require a content selection.</p>
            @endif

            <div class="flex flex-col gap-2">
                <label for="staff-quantity" class="font-medium">Quantity</label>
                <x-filament::input.wrapper>
                    <x-filament::input
                        id="staff-quantity"
                        wire:model="quantity"
                        type="number"
                        min="1"
                        required
                        :disabled="$receiptId !== ''"
                    />
                </x-filament::input.wrapper>
                @error('quantity') <p role="alert">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-col gap-2">
                <label for="staff-reason" class="font-medium">Reason</label>
                <x-filament::input.wrapper>
                    <x-filament::input
                        id="staff-reason"
                        wire:model="reason"
                        minlength="8"
                        maxlength="160"
                        placeholder="Explain why this grant is being issued"
                        required
                        :disabled="$receiptId !== ''"
                    />
                </x-filament::input.wrapper>
                @error('reason') <p role="alert">{{ $message }}</p> @enderror
            </div>

            @if ($errors->any())
                <div role="alert">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <p class="break-all text-sm">
                Request key: <code id="staff-request-key">{{ $requestKey }}</code>
            </p>

            @if ($receiptId !== '')
                <p role="status" class="break-all">
                    Receipt: <code id="staff-receipt">{{ $receiptId }}</code>
                </p>
                <x-filament::button wire:click="newGrant" type="button">
                    Start a new grant
                </x-filament::button>
            @else
                <x-filament::button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="grant"
                    :disabled="$characterId === ''"
                >
                    Issue grant
                </x-filament::button>
            @endif
        </form>
    </x-filament::section>
</x-filament-panels::page>
