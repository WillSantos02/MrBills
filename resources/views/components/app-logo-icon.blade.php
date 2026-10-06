{{-- Mascote MrBills: um boleto de cartola. Mesmo desenho de public/favicon.svg (lá com cores fixas). --}}
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 112" fill="none" aria-hidden="true" {{ $attributes->merge(['class' => 'overflow-visible']) }}>
    <ellipse cx="48" cy="106" rx="30" ry="5" class="fill-foreground/10" />
    <path d="M29 26h38v15H29z" class="fill-mascot-hat" />
    <path d="M34 4h28l5 24H29L34 4Z" class="fill-mascot-hat" />
    <path d="M32 23h32v5H32z" class="fill-accent" />
    <rect x="14" y="36" width="68" height="66" rx="9" stroke-width="2" class="fill-mascot-paper stroke-line" />
    <path d="M14 44h68" stroke-width="2" class="stroke-gray-200" />
    <circle cx="37" cy="60" r="3" class="fill-mascot-ink" />
    <circle cx="59" cy="60" r="3" class="fill-mascot-ink" />
    <path d="M39 72c5 4 13 4 18 0" stroke-width="3" stroke-linecap="round" class="stroke-primary" />
    <path d="M25 85v10M30 82v13M35 86v9M41 81v14M47 84v11M54 81v14M60 85v10M65 82v13M71 86v9" stroke-width="2" class="stroke-mascot-ink" />
    <path d="M14 55 4 67M82 55l10 12" stroke-width="4" stroke-linecap="round" class="stroke-mascot-hat" />
</svg>
