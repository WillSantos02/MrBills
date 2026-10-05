@props([
    'card',
    'total' => null,
    'closing' => null,
    'due' => null,
    'used' => null,
    'overdue' => 0,
])

@php
    /** @var \App\Models\CreditCard $card */
    // As classes ficam aqui (e não no enum) porque o Tailwind só escaneia resources/views.
    $gradient = match ($card->color) {
        \App\Enums\CardColor::Azul => 'from-blue-600 via-blue-800 to-indigo-950',
        \App\Enums\CardColor::Preto => 'from-zinc-600 via-zinc-800 to-black',
        \App\Enums\CardColor::Laranja => 'from-orange-400 via-orange-600 to-red-800',
        \App\Enums\CardColor::Verde => 'from-emerald-500 via-emerald-700 to-teal-950',
        \App\Enums\CardColor::Vermelho => 'from-red-500 via-red-700 to-red-950',
        default => 'from-violet-500 via-purple-700 to-purple-950',
    };

    $limit = $card->credit_limit !== null ? (float) $card->credit_limit : null;
    $usedPercent = ($limit !== null && $limit > 0 && $used !== null) ? min(100, ($used / $limit) * 100) : null;
    $barColor = match (true) {
        $usedPercent === null => '',
        $usedPercent >= 90 => 'bg-red-500',
        $usedPercent >= 70 => 'bg-amber-500',
        default => 'bg-emerald-500',
    };
@endphp

<div {{ $attributes->class('space-y-2') }}>
    <div @class([
        'relative aspect-[1.586/1] overflow-hidden rounded-2xl bg-linear-to-br p-5 text-white shadow-lg ring-1 select-none',
        $gradient,
        'ring-amber-400 ring-2' => $overdue > 0,
        'ring-white/10' => $overdue <= 0,
    ])>
        {{-- Brilho decorativo --}}
        <div class="pointer-events-none absolute -right-12 -top-16 size-48 rounded-full bg-white/10"></div>
        <div class="pointer-events-none absolute -bottom-20 -left-10 size-56 rounded-full bg-white/5"></div>

        <div class="relative flex h-full flex-col justify-between">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="truncate text-lg font-semibold leading-tight">{{ $card->bank }}</p>
                    <p class="text-[10px] uppercase tracking-[0.2em] text-white/70">Crédito</p>
                </div>

                @if ($overdue > 0)
                    <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-400 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-950 shadow"
                          title="Fatura vencida sem pagamento — R$ {{ number_format($overdue, 2, ',', '.') }} em aberto">
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 6a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 6Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
                        </svg>
                        Fatura pendente
                    </span>
                @else
                    <svg class="size-6 shrink-0 rotate-90 text-white/80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M8.5 16.5a5 5 0 0 1 0-9M12 19a8.5 8.5 0 0 0 0-14M15.5 21.5a12 12 0 0 0 0-19" stroke-linecap="round" />
                    </svg>
                @endif
            </div>

            <div class="flex items-center gap-4">
                {{-- Chip --}}
                <div class="relative h-8 w-11 shrink-0 overflow-hidden rounded-md bg-linear-to-br from-yellow-100 via-yellow-300 to-yellow-600 shadow-inner">
                    <div class="absolute inset-x-0 top-1/2 h-px bg-yellow-700/40"></div>
                    <div class="absolute inset-y-0 left-1/3 w-px bg-yellow-700/40"></div>
                    <div class="absolute inset-y-0 left-2/3 w-px bg-yellow-700/40"></div>
                </div>
                <p class="font-mono text-base tracking-[0.18em] sm:text-lg" aria-label="Final {{ $card->last_four_digits }}">
                    •••• •••• •••• {{ $card->last_four_digits }}
                </p>
            </div>

            <div class="flex items-end justify-between gap-3">
                <div class="min-w-0">
                    @if ($total !== null)
                        <p class="text-[10px] uppercase tracking-wider text-white/70">Fatura atual</p>
                        <p class="truncate text-xl font-semibold">R$ {{ number_format($total, 2, ',', '.') }}</p>
                    @endif
                </div>
                <div class="shrink-0 text-right text-[11px] leading-tight text-white/80">
                    <p>Fecha <span class="font-semibold text-white">{{ $closing ? $closing->format('d/m') : 'dia '.$card->closing_day }}</span></p>
                    <p>Vence <span class="font-semibold text-white">{{ $due ? $due->format('d/m') : 'dia '.$card->due_day }}</span></p>
                </div>
            </div>
        </div>
    </div>

    @if ($overdue > 0)
        <p class="text-xs font-medium text-amber-700 dark:text-amber-400">
            ⚠ Fatura pendente: R$ {{ number_format($overdue, 2, ',', '.') }} em aberto, somado à fatura seguinte.
        </p>
    @endif

    @if ($usedPercent !== null)
        <div>
            <div class="mb-1 flex justify-between text-xs text-gray-500 dark:text-gray-400">
                <span>Limite usado: R$ {{ number_format($used, 2, ',', '.') }}</span>
                <span>Disponível: R$ {{ number_format(max(0, $limit - $used), 2, ',', '.') }}</span>
            </div>
            <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-zinc-700"
                 role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round($usedPercent) }}"
                 aria-label="Limite usado do cartão">
                <div class="h-full rounded-full transition-all {{ $barColor }}" style="width: {{ $usedPercent }}%"></div>
            </div>
            <p class="mt-1 text-right text-[11px] text-gray-400">
                {{ number_format($usedPercent, 0) }}% de R$ {{ number_format($limit, 2, ',', '.') }}
            </p>
        </div>
    @endif
</div>
