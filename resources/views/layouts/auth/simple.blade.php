<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-background text-foreground antialiased">
        <div class="pointer-events-none fixed -top-48 -left-40 size-[420px] rounded-full bg-accent/15 blur-[120px]" aria-hidden="true"></div>
        <div class="pointer-events-none fixed top-24 right-0 size-[360px] rounded-full bg-primary/15 blur-[120px]" aria-hidden="true"></div>

        <div class="relative flex min-h-svh flex-col items-center justify-center gap-6 p-4 md:p-10">
            <div class="flex w-full max-w-md flex-col gap-5">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2" wire:navigate>
                    <x-app-logo-icon class="h-20 w-16 animate-mascot-tip" />
                    <span class="text-xl font-bold">MrBills</span>
                    <span class="eyebrow -mt-1.5 text-[10px]">mordomo financeiro</span>
                </a>

                <div class="glass-panel animate-rise flex flex-col gap-6 rounded-3xl p-6 sm:p-8">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
