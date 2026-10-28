@foreach($modifiers as $j => $modifier)
<div wire:key="{{ $scope }}-{{ $index }}-mod-{{ $j }}" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin:.5rem 0">
<label>Stat <select aria-label="Stat" data-testid="{{ $scope }}-{{ $index }}-stat-{{ $j }}" wire:model="definition.{{ $scope }}.{{ $index }}.modifiers.{{ $j }}.stat">
@foreach($statRules as $key => $rule)<option value="{{ $key }}">{{ $rule['label'] }}</option>@endforeach
</select></label>
<label>Satuan <select aria-label="Satuan" data-testid="{{ $scope }}-{{ $index }}-unit-{{ $j }}" wire:model="definition.{{ $scope }}.{{ $index }}.modifiers.{{ $j }}.unit"><option value="flat">Poin tetap</option><option value="percent">Persen relatif</option><option value="points">Poin persentase</option></select></label>
<label>Nilai <input aria-label="Nilai" data-testid="{{ $scope }}-{{ $index }}-amount-{{ $j }}" type="number" min="0" max="10000" style="width:7rem" wire:model="definition.{{ $scope }}.{{ $index }}.modifiers.{{ $j }}.amount"></label>
<x-filament::button type="button" size="sm" color="gray" wire:click="removeModifier({{ $index }}, {{ $j }}, {{ $scope === 'bonuses' ? 'true' : 'false' }})">Hapus bonus stat</x-filament::button>
</div>
@endforeach
