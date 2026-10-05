{{-- Seletor de cor do cartão (radio com amostras). Espera: $model (propriedade Livewire), $selected, $colors. --}}
@php
    $swatches = [
        'roxo' => 'from-violet-500 to-purple-900',
        'azul' => 'from-blue-600 to-indigo-950',
        'preto' => 'from-zinc-600 to-black',
        'laranja' => 'from-orange-400 to-red-800',
        'verde' => 'from-emerald-500 to-teal-950',
        'vermelho' => 'from-red-500 to-red-950',
    ];
@endphp

<fieldset>
    <legend class="mb-2 text-sm font-medium text-zinc-800 dark:text-white">Cor do cartão</legend>
    <div class="flex flex-wrap gap-2">
        @foreach ($colors as $option)
            <label class="cursor-pointer" title="{{ $option->label() }}">
                <input type="radio" wire:model.live="{{ $model }}" value="{{ $option->value }}" class="peer sr-only">
                <span @class([
                    'block size-8 rounded-full bg-linear-to-br ring-offset-2 ring-offset-white transition peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500 dark:ring-offset-zinc-900',
                    $swatches[$option->value] ?? '',
                    'ring-2 ring-zinc-900 dark:ring-white' => $selected === $option->value,
                ])></span>
                <span class="sr-only">{{ $option->label() }}</span>
            </label>
        @endforeach
    </div>
    @error($model)
        <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
    @enderror
</fieldset>
