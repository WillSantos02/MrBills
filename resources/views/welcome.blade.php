<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => 'Seu mordomo financeiro'])
    </head>
    <body class="min-h-screen bg-background text-foreground antialiased">
        <div class="pointer-events-none fixed -top-48 -left-40 size-[420px] rounded-full bg-accent/15 blur-[120px]" aria-hidden="true"></div>
        <div class="pointer-events-none fixed top-24 right-0 size-[360px] rounded-full bg-primary/15 blur-[120px]" aria-hidden="true"></div>

        <div class="relative mx-auto flex min-h-svh max-w-[1200px] flex-col px-4 sm:px-5">
            <header class="flex items-center justify-between py-4">
                <x-app-logo :href="route('home')" />

                @if (Route::has('login'))
                    <nav class="flex items-center gap-2">
                        @auth
                            <flux:button :href="route('dashboard')" variant="primary" size="sm">Abrir painel</flux:button>
                        @else
                            <flux:button :href="route('login')" variant="ghost" size="sm">Entrar</flux:button>
                            @if (Route::has('register'))
                                <flux:button :href="route('register')" variant="primary" size="sm">Criar conta</flux:button>
                            @endif
                        @endauth
                    </nav>
                @endif
            </header>

            <main class="flex flex-1 items-center py-10">
                <div class="grid w-full items-center gap-10 md:grid-cols-2">
                    <div class="animate-rise">
                        <p class="eyebrow">contas a pagar · sem sustos</p>
                        <h1 class="mt-3 text-4xl font-extrabold tracking-tight sm:text-5xl">
                            Suas contas organizadas por um mordomo que nunca perde um vencimento.
                        </h1>
                        <p class="mt-4 max-w-[52ch] text-muted-foreground">
                            Despesas, receitas e faturas de cartão num só lugar. Vencimento no fim de semana vai para
                            segunda, parcelas já nascem prontas e o MrBills avisa antes de vencer.
                        </p>
                        <div class="mt-6 flex flex-wrap gap-2">
                            @auth
                                <flux:button :href="route('dashboard')" variant="primary">Abrir painel</flux:button>
                            @else
                                @if (Route::has('register'))
                                    <flux:button :href="route('register')" variant="primary">Começar agora</flux:button>
                                @endif
                                <flux:button :href="route('login')">Já tenho conta</flux:button>
                            @endauth
                        </div>
                    </div>

                    <div class="glass-panel animate-rise rounded-3xl p-6 [animation-delay:120ms]">
                        <p class="eyebrow">a pagar · este mês</p>
                        <p class="mt-3 text-4xl font-extrabold">R$ 2.847<span class="text-2xl">,90</span></p>
                        <p class="mt-1 text-sm text-muted-foreground">7 contas · 3 vencem esta semana</p>

                        <div class="mt-5 space-y-3">
                            @foreach ([['Pago', 'bg-paid', 'R$ 1.240,00'], ['A vencer', 'bg-due', 'R$ 2.658,00'], ['Atrasado', 'bg-late', 'R$ 189,90']] as [$label, $color, $value])
                                <div class="flex items-center justify-between rounded-xl bg-line/35 p-3">
                                    <div class="flex items-center gap-2"><span class="size-2 rounded-full {{ $color }}"></span><span class="text-sm">{{ $label }}</span></div>
                                    <span class="font-mono text-sm font-medium">{{ $value }}</span>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-5 flex items-center gap-3 rounded-2xl bg-foreground/95 p-3 text-background">
                            <x-app-logo-icon class="h-14 w-12 shrink-0 animate-mascot-tip" />
                            <p class="text-[13px] leading-snug">“Dei uma conferida: o aluguel e a internet estão em dia, só faltam três.”</p>
                        </div>
                    </div>
                </div>
            </main>
        </div>

        @fluxScripts
    </body>
</html>
