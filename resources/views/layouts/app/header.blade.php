@php
    $navItems = [
        ['route' => 'dashboard', 'label' => 'Painel', 'icon' => 'home'],
        ['route' => 'bills.index', 'label' => 'Despesas', 'icon' => 'document-currency-dollar'],
        ['route' => 'wallet.index', 'label' => 'Receitas', 'icon' => 'wallet'],
        ['route' => 'cards.index', 'label' => 'Cartões', 'icon' => 'credit-card'],
        ['route' => 'categories.index', 'label' => 'Categorias', 'icon' => 'tag'],
        ['route' => 'family.index', 'label' => 'Família', 'icon' => 'users'],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-background text-foreground antialiased">
        <div class="pointer-events-none fixed -top-48 -left-40 size-[420px] rounded-full bg-accent/15 blur-[120px]" aria-hidden="true"></div>
        <div class="pointer-events-none fixed top-24 right-0 size-[360px] rounded-full bg-primary/15 blur-[120px]" aria-hidden="true"></div>

        {{-- Menu mobile (gaveta) --}}
        <flux:sidebar collapsible="mobile" sticky class="lg:hidden border-e border-line/60 bg-popover">
            <flux:sidebar.header>
                <x-app-logo :href="route('dashboard')" wire:navigate />
                <flux:sidebar.collapse />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                @foreach ($navItems as $item)
                    <flux:sidebar.item :icon="$item['icon']" :href="route($item['route'])" :current="request()->routeIs($item['route'])" wire:navigate>
                        {{ $item['label'] }}
                    </flux:sidebar.item>
                @endforeach
            </flux:sidebar.nav>
        </flux:sidebar>

        {{-- Largura fluida com o mesmo recuo do flux:main do layout antigo (p-6 lg:p-8). --}}
        <div class="relative">
            <header class="sticky top-0 z-30 flex items-center justify-between gap-3 border-b border-line/60 bg-surface/80 px-6 py-3 backdrop-blur-xl lg:px-8">
                <div class="flex items-center gap-2">
                    <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
                    <x-app-logo :href="route('dashboard')" wire:navigate />
                </div>

                <nav class="hidden items-center gap-1 lg:flex" aria-label="Principal">
                    @foreach ($navItems as $item)
                        @php($current = request()->routeIs($item['route']))
                        <a href="{{ route($item['route']) }}" wire:navigate @if ($current) aria-current="page" @endif
                           @class([
                               'inline-flex h-8 items-center rounded-full px-3 text-xs font-semibold transition-all duration-200',
                               'bg-foreground text-background' => $current,
                               'text-muted-foreground hover:bg-line/40 hover:text-foreground' => ! $current,
                           ])>
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </nav>

                <div class="flex items-center gap-1">
                    <livewire:notification-center />
                    <x-desktop-user-menu />
                </div>
            </header>

            <main class="p-6 lg:p-8">
                {{ $slot }}
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
