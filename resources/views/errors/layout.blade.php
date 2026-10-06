{{-- Sem sessão/auth aqui: um 404 de rota inexistente é renderizado antes do middleware de sessão. --}}
@php
    $status = $exception->getStatusCode();

    [$titulo, $mensagem] = match ($status) {
        403 => ['Acesso negado', 'Você não tem permissão para ver esta página.'],
        404 => ['Página não encontrada', 'Procurei em todas as gavetas e não achei esta página. Talvez ela tenha mudado de lugar.'],
        419 => ['Sessão expirada', 'Sua sessão expirou por inatividade. Volte e tente de novo.'],
        429 => ['Calma lá', 'Muitas requisições em pouco tempo. Espere um instante e tente de novo.'],
        503 => ['Em manutenção', 'Estou arrumando a casa. Volte em alguns minutos.'],
        default => $status >= 500
            ? ['Algo deu errado', 'Tive um problema do meu lado. Tente atualizar a página em instantes.']
            : ['Não deu certo', 'Não consegui atender este pedido.'],
    };
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => $titulo])
    </head>
    <body class="min-h-screen bg-background text-foreground antialiased">
        <div class="pointer-events-none fixed -top-48 -left-40 size-[420px] rounded-full bg-accent/15 blur-[120px]" aria-hidden="true"></div>
        <div class="pointer-events-none fixed top-24 right-0 size-[360px] rounded-full bg-primary/15 blur-[120px]" aria-hidden="true"></div>

        <main class="relative flex min-h-svh items-center justify-center p-4">
            <div class="glass-panel animate-rise w-full max-w-md rounded-3xl p-8 text-center">
                <x-app-logo-icon class="mx-auto h-24 w-20 animate-mascot-tip" />
                <p class="eyebrow mt-4">erro {{ $status }}</p>
                <h1 class="mt-1 text-2xl font-bold">{{ $titulo }}</h1>
                <p class="mt-2 text-sm text-muted-foreground">{{ $mensagem }}</p>
                <div class="mt-6 flex flex-wrap justify-center gap-2">
                    <a href="javascript:history.back()" class="inline-flex h-10 items-center rounded-full bg-line/45 px-4 text-sm font-semibold ring-1 ring-line/70 transition-colors hover:bg-line/70">Voltar</a>
                    <a href="{{ route('dashboard') }}" class="inline-flex h-10 items-center rounded-full bg-accent px-4 text-sm font-semibold text-accent-foreground shadow-lg shadow-accent/20 transition-colors hover:bg-accent-hover">Ir para o início</a>
                </div>
            </div>
        </main>
    </body>
</html>
