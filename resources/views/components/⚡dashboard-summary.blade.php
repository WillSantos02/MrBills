<?php

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\Income;
use App\Models\User;
use Carbon\CarbonInterface;
use Livewire\Component;

new class extends Component
{
    public string $memberFilter = '';

    public function with(): array
    {
        $familyUserIds = auth()->user()->familyGroupUserIds();

        $userIds = $this->memberFilter !== '' ? [(int) $this->memberFilter] : $familyUserIds;

        $now = now();
        $today = $now->toDateString();

        $doMes = fn () => Bill::whereIn('user_id', $userIds)
            ->whereYear('actual_due_date', $now->year)
            ->whereMonth('actual_due_date', $now->month);

        // KPI: Total a Pagar — contas pendentes com vencimento no mês atual (inclui as já vencidas do mês).
        // Faturas de cartão são Bills comuns, então já entram aqui pelo vencimento delas.
        $totalAPagar = (float) $doMes()->where('status', BillStatus::Pendente->value)->sum('value');
        $qtdAPagar = $doMes()->where('status', BillStatus::Pendente->value)->count();

        $totalPago = (float) $doMes()->where('status', BillStatus::Pago->value)->sum('value');
        $qtdPagas = $doMes()->where('status', BillStatus::Pago->value)->count();
        $qtdContasMes = $doMes()->count();

        // "A vencer" do mês = pendentes ainda não vencidas (Vencido é derivado, ver BillStatus).
        $totalAVencerMes = (float) $doMes()->where('status', BillStatus::Pendente->value)
            ->where('actual_due_date', '>=', $today)
            ->sum('value');

        // Atrasadas: qualquer mês, mesma condição do status derivado "Vencido".
        $atrasadas = Bill::whereIn('user_id', $userIds)
            ->where('status', BillStatus::Pendente->value)
            ->where('actual_due_date', '<', $today);
        $totalAtrasado = (float) (clone $atrasadas)->sum('value');
        $qtdAtrasadas = (clone $atrasadas)->count();
        $maisAntiga = (clone $atrasadas)->min('actual_due_date');
        $diasAtraso = $maisAntiga ? (int) $now->copy()->startOfDay()->diffInDays($maisAntiga, true) : 0;

        // KPI: Carteira — entradas do mês atual.
        $totalCarteira = (float) Income::whereIn('user_id', $userIds)
            ->whereYear('date', $now->year)
            ->whereMonth('date', $now->month)
            ->sum('value');

        $saldoMes = $totalCarteira - $totalAPagar;

        // Quanto das entradas do mês já está comprometido com as contas do mês (pagas + pendentes).
        $comprometido = $totalCarteira > 0
            ? min(100, (int) round(($totalPago + $totalAPagar) / $totalCarteira * 100))
            : null;

        // Maiores despesas do mês, agrupadas por categoria.
        $despesasPorCategoria = Category::whereIn('user_id', $userIds)
            ->withSum(['bills as total_mes' => function ($query) use ($now) {
                $query->whereYear('actual_due_date', $now->year)
                    ->whereMonth('actual_due_date', $now->month);
            }], 'value')
            ->get()
            ->filter(fn ($categoria) => $categoria->total_mes > 0)
            ->sortByDesc('total_mes')
            ->values();

        // Próximos vencimentos — pendentes, vencendo hoje ou nos próximos 7 dias.
        $contasProximas = Bill::with('category')
            ->whereIn('user_id', $userIds)
            ->where('status', BillStatus::Pendente->value)
            ->whereBetween('actual_due_date', [$today, $now->copy()->addDays(7)->toDateString()])
            ->orderBy('actual_due_date')
            ->get();

        // Histórico de pagamentos: total pago por mês nos últimos 6 meses (o atual por último).
        $historicoPago = collect(range(5, 0))
            ->map(fn ($i) => $now->copy()->subMonths($i))
            ->map(fn (CarbonInterface $mes) => [
                'mes' => $mes->locale('pt_BR')->translatedFormat('M'),
                'valor' => (float) Bill::whereIn('user_id', $userIds)
                    ->where('status', BillStatus::Pago->value)
                    ->whereYear('actual_due_date', $mes->year)
                    ->whereMonth('actual_due_date', $mes->month)
                    ->sum('value'),
            ]);

        // Gráfico trimestral: últimos 3 meses, Entradas (Carteira) vs Saídas (Contas).
        $grafico = collect(range(2, 0))
            ->map(fn ($i) => $now->copy()->subMonths($i))
            ->map(function (CarbonInterface $mes) use ($userIds) {
                $entradas = Income::whereIn('user_id', $userIds)
                    ->whereYear('date', $mes->year)
                    ->whereMonth('date', $mes->month)
                    ->sum('value');

                $saidas = Bill::whereIn('user_id', $userIds)
                    ->whereYear('actual_due_date', $mes->year)
                    ->whereMonth('actual_due_date', $mes->month)
                    ->sum('value');

                return [
                    'mes' => $mes->format('m/Y'),
                    'entradas' => (float) $entradas,
                    'saidas' => (float) $saidas,
                ];
            });

        // Carrossel de cartões: ciclo aberto hoje (fatura que recebe as compras de agora) + fatura fechada
        // que ainda não foi paga, se houver.
        $cartoes = CreditCard::whereIn('user_id', $userIds)
            ->orderBy('bank')
            ->get()
            ->map(fn (CreditCard $card) => ['card' => $card] + $card->displaySummary() + [
                'closedInvoice' => $card->closedPendingInvoice(),
            ]);

        $primeiroNome = str(auth()->user()->name)->before(' ')->lower()->ucfirst()->toString();

        return [
            'cartoes' => $cartoes,
            'totalAPagar' => $totalAPagar,
            'qtdAPagar' => $qtdAPagar,
            'totalPago' => $totalPago,
            'qtdPagas' => $qtdPagas,
            'qtdContasMes' => $qtdContasMes,
            'totalAVencerMes' => $totalAVencerMes,
            'totalAtrasado' => $totalAtrasado,
            'qtdAtrasadas' => $qtdAtrasadas,
            'diasAtraso' => $diasAtraso,
            'totalCarteira' => $totalCarteira,
            'saldoMes' => $saldoMes,
            'comprometido' => $comprometido,
            'despesasPorCategoria' => $despesasPorCategoria,
            'contasProximas' => $contasProximas,
            'historicoPago' => $historicoPago,
            'grafico' => $grafico,
            'saudacao' => match (true) {
                $now->hour < 12 => 'Bom dia',
                $now->hour < 18 => 'Boa tarde',
                default => 'Boa noite',
            },
            'primeiroNome' => $primeiroNome,
            'dataExtenso' => mb_strtolower($now->copy()->locale('pt_BR')->translatedFormat('l · j \d\e F · Y')),
            'mesNome' => mb_strtolower($now->copy()->locale('pt_BR')->translatedFormat('F')),
            'familyMembers' => User::whereIn('id', $familyUserIds)->orderBy('name')->get(['id', 'name']),
        ];
    }
};
?>

<div>
    @php
        $brl = fn (float $v) => 'R$ '.number_format($v, 2, ',', '.');

        // "R$ 2.847<span>,90</span>": reais em destaque, centavos menores, como no protótipo.
        $money = function (float $v, string $centsClass = 'text-2xl') {
            [$reais, $centavos] = explode(',', number_format(abs($v), 2, ',', '.'));

            return ($v < 0 ? '-' : '').'R$ '.$reais.'<span class="'.$centsClass.'">,'.$centavos.'</span>';
        };

        $dueLabel = function (\App\Models\Bill $bill) {
            $dias = (int) now()->startOfDay()->diffInDays($bill->actual_due_date, true);

            return match ($dias) {
                0 => 'vence hoje',
                1 => 'vence amanhã',
                default => "vence em {$dias} dias",
            }.' · '.$bill->actual_due_date->format('d/m');
        };
    @endphp

    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">{{ $dataExtenso }}</p>
            <h1 class="mt-1 text-3xl font-bold sm:text-4xl">{{ $saudacao }}, {{ $primeiroNome }}.</h1>
        </div>

        @if ($familyMembers->count() > 1)
            <div class="w-full sm:w-60">
                <flux:select wire:model.live="memberFilter" size="sm" aria-label="Membro da família">
                    <flux:select.option value="">Toda a família</flux:select.option>
                    @foreach ($familyMembers as $member)
                        <flux:select.option value="{{ $member->id }}">{{ $member->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-3 md:grid-cols-12">
        {{-- A pagar no mês --}}
        <section class="glass-panel animate-rise relative flex flex-col overflow-hidden rounded-3xl p-5 md:col-span-5 md:row-span-2 md:p-6">
            <p class="eyebrow">a pagar · {{ $mesNome }}</p>
            <p class="mt-3 text-4xl font-extrabold sm:text-5xl">{!! $money($totalAPagar) !!}</p>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ $qtdAPagar }} {{ Str::plural('conta', $qtdAPagar) }} pendente{{ $qtdAPagar === 1 ? '' : 's' }}
                @if ($contasProximas->isNotEmpty())
                    · {{ $contasProximas->count() }} vence{{ $contasProximas->count() === 1 ? '' : 'm' }} em 7 dias
                @endif
            </p>

            <div class="mt-6 mb-5 space-y-3">
                <div>
                    <div class="mb-1 flex justify-between text-xs text-muted-foreground">
                        <span>Comprometido das receitas</span>
                        <span class="font-mono">{{ $comprometido === null ? 'sem receitas' : $comprometido.'%' }}</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-line/55">
                        <div @class(['h-full rounded-full', 'bg-late' => ($comprometido ?? 0) >= 90, 'bg-accent' => ($comprometido ?? 0) < 90]) style="width: {{ $comprometido ?? 0 }}%"></div>
                    </div>
                </div>

                <a href="{{ route('wallet.index') }}" wire:navigate class="flex items-center gap-2 rounded-2xl border border-line/60 bg-line/30 p-3 transition-colors hover:bg-line/50">
                    <span class="grid size-9 place-items-center rounded-full bg-paid/15 text-paid"><flux:icon.arrow-down-left variant="micro" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold">Receitas do mês</span>
                        <span class="block font-mono text-[11px] text-muted-foreground">entradas registradas</span>
                    </span>
                    <span class="font-mono text-sm font-medium text-paid">{{ $brl($totalCarteira) }}</span>
                </a>

                <div class="flex items-center gap-2 rounded-2xl border border-line/60 bg-line/30 p-3">
                    <span class="grid size-9 place-items-center rounded-full bg-primary/15 font-mono text-xs font-medium text-primary">=</span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold">Saldo do mês</span>
                        <span class="block font-mono text-[11px] text-muted-foreground">receitas − a pagar</span>
                    </span>
                    <span @class(['font-mono text-sm font-medium', 'text-paid' => $saldoMes >= 0, 'text-late' => $saldoMes < 0])>{{ $brl($saldoMes) }}</span>
                </div>
            </div>

            <div class="mt-5 flex items-center gap-3 rounded-2xl bg-foreground/95 p-3 text-background md:mt-auto">
                <x-app-logo-icon class="h-14 w-12 shrink-0 animate-mascot-tip" />
                <p class="text-[13px] leading-snug">
                    @if ($qtdAtrasadas > 0)
                        “Opa, {{ $primeiroNome }}: {{ $qtdAtrasadas }} {{ Str::plural('conta', $qtdAtrasadas) }} em atraso somando {{ $brl($totalAtrasado) }}. Melhor resolver logo.”
                    @elseif ($contasProximas->isNotEmpty())
                        “Dei uma conferida, {{ $primeiroNome }}. {{ $contasProximas->count() }} {{ Str::plural('conta', $contasProximas->count()) }} vence{{ $contasProximas->count() === 1 ? '' : 'm' }} nos próximos 7 dias, somando {{ $brl((float) $contasProximas->sum('value')) }}.”
                    @else
                        “Tudo em dia, {{ $primeiroNome }}. Nenhum vencimento nos próximos 7 dias.”
                    @endif
                </p>
            </div>
        </section>

        {{-- Pago este mês --}}
        <section class="glass-panel animate-rise rounded-3xl p-5 md:col-span-4 [animation-delay:60ms]">
            <p class="eyebrow">pago este mês</p>
            <p class="mt-2 text-3xl font-bold">{!! $money($totalPago, 'text-lg') !!}</p>
            <p class="mt-1 text-xs text-muted-foreground">{{ $qtdPagas }} de {{ $qtdContasMes }} {{ Str::plural('conta', $qtdContasMes) }} quitada{{ $qtdPagas === 1 ? '' : 's' }}</p>

            @php($maxPago = max(1, $historicoPago->max('valor')))
            <div class="mt-4 flex h-14 items-end gap-1.5" role="img" aria-label="Total pago nos últimos 6 meses">
                @foreach ($historicoPago as $mes)
                    <div @class(['w-full rounded-t', 'bg-accent' => $loop->last, 'bg-line/60' => ! $loop->last])
                         style="height: {{ max(6, round($mes['valor'] / $maxPago * 100)) }}%"
                         title="{{ $mes['mes'] }}: {{ $brl($mes['valor']) }}"></div>
                @endforeach
            </div>
        </section>

        {{-- Atrasado --}}
        <section class="glass-panel animate-rise rounded-3xl p-5 md:col-span-3 [animation-delay:120ms]">
            <p class="eyebrow">atrasado</p>
            <p @class(['mt-2 text-3xl font-bold', 'text-late' => $qtdAtrasadas > 0])>{!! $money($totalAtrasado, 'text-lg') !!}</p>
            <p class="mt-1 text-xs text-muted-foreground">
                @if ($qtdAtrasadas > 0)
                    {{ $qtdAtrasadas }} {{ Str::plural('conta', $qtdAtrasadas) }} · {{ $diasAtraso }} {{ Str::plural('dia', $diasAtraso) }}
                @else
                    Tudo em dia
                @endif
            </p>
            @if ($qtdAtrasadas > 0)
                <a href="{{ route('bills.index') }}" wire:navigate class="mt-4 inline-flex h-10 w-full items-center justify-center rounded-full bg-late/10 text-sm font-semibold text-late ring-1 ring-late/20 transition-all hover:bg-late/15">
                    Ver atrasadas
                </a>
            @else
                <span class="mt-4 inline-flex h-10 w-full items-center justify-center gap-2 rounded-full bg-line/45 text-sm font-semibold ring-1 ring-line/70">
                    <flux:icon.check variant="micro" /> Em dia
                </span>
            @endif
        </section>

        {{-- Próximos vencimentos --}}
        <section class="glass-panel animate-rise rounded-3xl p-4 md:col-span-7 md:p-5 [animation-delay:180ms]">
            <div class="mb-3 flex items-center justify-between">
                <span class="eyebrow">próximos vencimentos</span>
                <a href="{{ route('bills.index') }}" wire:navigate class="inline-flex h-8 items-center gap-1 rounded-full px-3 text-xs font-semibold text-muted-foreground transition-colors hover:bg-line/40 hover:text-foreground">
                    ver todas <flux:icon.chevron-right variant="micro" class="size-3" />
                </a>
            </div>

            <div class="space-y-1">
                @forelse ($contasProximas->take(6) as $bill)
                    <div wire:key="proxima-{{ $bill->id }}" class="flex items-center gap-3 rounded-2xl p-2.5 transition-colors hover:bg-line/40">
                        <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-line/55 font-mono text-xs font-medium text-muted-foreground">
                            {{ Str::of($bill->category?->name ?? $bill->description)->substr(0, 3)->ucfirst() }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold">{{ $bill->display_description }}</p>
                            <p class="font-mono text-[11px] text-muted-foreground">{{ $dueLabel($bill) }}</p>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1 sm:flex-row sm:items-center sm:gap-3">
                            <span class="font-mono text-sm font-semibold">{{ $brl((float) $bill->value) }}</span>
                            @if ($bill->actual_due_date->isToday())
                                <span class="rounded-full bg-late/10 px-2.5 py-1 text-[11px] font-medium text-late ring-1 ring-late/20">hoje</span>
                            @else
                                <span class="rounded-full bg-due/10 px-2.5 py-1 text-[11px] font-medium text-due ring-1 ring-due/20">a vencer</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="flex items-center gap-3 rounded-2xl p-2.5 text-sm text-muted-foreground">
                        <flux:icon.check-circle class="text-paid" /> Nenhuma conta vencendo nos próximos 7 dias.
                    </div>
                @endforelse
            </div>
        </section>

        {{-- Status do mês --}}
        <section class="glass-panel animate-rise rounded-3xl p-5 md:col-span-5 [animation-delay:240ms]">
            <span class="eyebrow">status do mês</span>
            <div class="mt-4 space-y-3">
                @foreach ([['Pago', 'bg-paid', $totalPago], ['A vencer', 'bg-due', $totalAVencerMes], ['Atrasado', 'bg-late', $totalAtrasado]] as [$label, $color, $value])
                    <div class="flex items-center justify-between rounded-xl bg-line/35 p-3">
                        <div class="flex items-center gap-2"><span class="size-2 rounded-full {{ $color }}"></span><span class="text-sm">{{ $label }}</span></div>
                        <span class="font-mono text-sm font-medium">{{ $brl($value) }}</span>
                    </div>
                @endforeach
            </div>
            @if ($despesasPorCategoria->isNotEmpty())
                <div class="mt-4 flex items-center gap-3 rounded-xl border border-dashed border-line/80 p-3 text-xs text-muted-foreground">
                    <x-app-logo-icon class="h-9 w-8 shrink-0" />
                    <span>A maior despesa do mês é <strong class="text-foreground">{{ $despesasPorCategoria->first()->name }}</strong>, com {{ $brl((float) $despesasPorCategoria->first()->total_mes) }}.</span>
                </div>
            @endif
        </section>

        {{-- Movimentação trimestral --}}
        <section class="glass-panel animate-rise rounded-3xl p-5 md:col-span-7 [animation-delay:300ms]">
            <span class="eyebrow">movimentação trimestral</span>

            <div
                wire:ignore
                x-data="{
                    chart: null,
                    labels: @js($grafico->pluck('mes')),
                    entradas: @js($grafico->pluck('entradas')),
                    saidas: @js($grafico->pluck('saidas')),
                    initChart() {
                        // Lê os tokens do tema (oklch) e converte para rgb, que o Chart.js sabe manipular no hover.
                        const css = getComputedStyle(this.$el);
                        const ctx = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
                        const token = (name) => {
                            ctx.clearRect(0, 0, 1, 1);
                            ctx.fillStyle = css.getPropertyValue(name).trim();
                            ctx.fillRect(0, 0, 1, 1);
                            const [r, g, b] = ctx.getImageData(0, 0, 1, 1).data;
                            return `rgb(${r}, ${g}, ${b})`;
                        };
                        Chart.defaults.font.family = css.getPropertyValue('--font-sans');
                        Chart.defaults.color = token('--mb-muted-foreground');
                        this.chart = new Chart(this.$refs.canvas, {
                            type: 'bar',
                            data: {
                                labels: this.labels,
                                datasets: [
                                    { label: 'Entradas', data: this.entradas, backgroundColor: token('--mb-paid'), borderRadius: 6 },
                                    { label: 'Saídas', data: this.saidas, backgroundColor: token('--mb-late'), borderRadius: 6 },
                                ],
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: { legend: { labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 6, boxHeight: 6 } } },
                                scales: {
                                    x: { grid: { display: false } },
                                    y: { grid: { color: token('--mb-line') }, border: { display: false } },
                                },
                            },
                        });
                    },
                }"
                x-init="
                    if (typeof Chart === 'undefined') {
                        let script = document.createElement('script');
                        script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
                        script.onload = () => initChart();
                        document.head.appendChild(script);
                    } else {
                        initChart();
                    }
                "
                class="mt-4 h-64"
            >
                <canvas x-ref="canvas"></canvas>
            </div>
        </section>

        {{-- Maiores despesas por categoria --}}
        <section class="glass-panel animate-rise rounded-3xl p-5 md:col-span-5 [animation-delay:360ms]">
            <span class="eyebrow">maiores despesas · por categoria</span>

            @php($maxCategoria = max(1, (float) $despesasPorCategoria->max('total_mes')))
            <div class="mt-4 space-y-3">
                @forelse ($despesasPorCategoria->take(6) as $categoria)
                    <div wire:key="cat-{{ $categoria->id }}">
                        <div class="mb-1 flex justify-between gap-3 text-sm">
                            <span class="truncate">{{ $categoria->name }}</span>
                            <span class="font-mono font-medium">{{ $brl((float) $categoria->total_mes) }}</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-line/55">
                            <div class="h-full rounded-full bg-accent" style="width: {{ round($categoria->total_mes / $maxCategoria * 100) }}%"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-muted-foreground">Nenhuma despesa registrada este mês.</p>
                @endforelse
            </div>
        </section>
        {{-- Cartões --}}
        <section class="glass-panel animate-rise min-w-0 rounded-3xl p-5 md:col-span-7 [animation-delay:420ms]"
                 x-data="{
                     scroll(direction) {
                         const track = this.$refs.track;
                         const step = (track.firstElementChild?.offsetWidth ?? 300) + 16;
                         track.scrollBy({ left: direction * step, behavior: 'smooth' });
                     },
                 }">
            <div class="mb-4 flex items-center justify-between">
                <span class="eyebrow">Cartões de Crédito</span>

                @if ($cartoes->count() > 1)
                    <div class="flex gap-1">
                        <flux:button size="sm" variant="ghost" icon="chevron-left" x-on:click="scroll(-1)" aria-label="Cartão anterior" />
                        <flux:button size="sm" variant="ghost" icon="chevron-right" x-on:click="scroll(1)" aria-label="Próximo cartão" />
                    </div>
                @endif
            </div>

            @if ($cartoes->isEmpty())
                <p class="text-sm text-muted-foreground">
                    Nenhum cartão cadastrado.
                    <a href="{{ route('cards.index') }}" wire:navigate class="font-medium text-accent-content hover:underline">Cadastrar cartão</a>
                </p>
            @else
                <div x-ref="track" class="flex snap-x snap-mandatory gap-4 overflow-x-auto scroll-smooth pb-2 [scrollbar-width:thin]">
                    @foreach ($cartoes as $item)
                        <a href="{{ route('cards.index') }}" wire:navigate wire:key="dash-card-{{ $item['card']->id }}"
                           class="w-[85%] shrink-0 snap-start transition hover:-translate-y-0.5 sm:w-80">
                            <x-credit-card :card="$item['card']" :total="$item['total']" :closing="$item['closing']" :due="$item['due']"
                                           :used="$item['used']" :overdue="$item['overdue']" />

                            @if ($item['closedInvoice'] && $item['overdue'] <= 0)
                                <p class="mt-2 font-mono text-[11px] text-muted-foreground">
                                    fatura fechada:
                                    <span class="font-semibold text-late">{{ $brl((float) $item['closedInvoice']->value) }}</span>
                                    · vence {{ $item['closedInvoice']->actual_due_date->format('d/m/Y') }}
                                </p>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

    </div>
</div>
