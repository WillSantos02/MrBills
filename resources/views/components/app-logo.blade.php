@props([
    'tagline' => true,
])

<a {{ $attributes->merge(['class' => 'flex shrink-0 items-center gap-3']) }}>
    <x-app-logo-icon class="h-11 w-9" />
    <span class="leading-tight">
        <span class="block text-[15px] font-bold text-foreground">MrBills</span>
        @if ($tagline)
            <span class="hidden font-mono text-[10px] uppercase tracking-[0.18em] text-muted-foreground sm:block">mordomo financeiro</span>
        @endif
    </span>
</a>
