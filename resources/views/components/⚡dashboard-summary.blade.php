<?php

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\Category;
use App\Models\Income;
use Carbon\CarbonInterface;
use Livewire\Component;

new class extends Component
{
    public function with(): array
    {
        $userId = auth()->id();
        $now = now();

        // KPI: Total a Pagar — contas pendentes com vencimento no mês atual.
        $totalAPagar = Bill::where('user_id', $userId)
            ->where('status', BillStatus::Pendente->value)
            ->whereYear('actual_due_date', $now->year)
            ->whereMonth('actual_due_date', $now->month)
            ->sum('value');

        // KPI: Carteira — entradas do mês atual.
        $totalCarteira = Income::where('user_id', $userId)
            ->whereYear('date', $now->year)
            ->whereMonth('date', $now->month)
            ->sum('value');

        $saldoMes = $totalCarteira - $totalAPagar;

        // Maiores despesas do mês, agrupadas por categoria.
        $despesasPorCategoria = Category::where('user_id', $userId)
            ->withSum(['bills as total_mes' => function ($query) use ($now) {
                $query->whereYear('actual_due_date', $now->year)
                    ->whereMonth('actual_due_date', $now->month);
            }], 'value')
            ->get()
            ->filter(fn ($categoria) => $categoria->total_mes > 0)
            ->sortByDesc('total_mes')
            ->values();

        // Contas próximas — pendentes, vencendo nos próximos 3 dias.
        $contasProximas = Bill::with('category')
            ->where('user_id', $userId)
            ->where('status', BillStatus::Pendente->value)
            ->whereBetween('actual_due_date', [
                $now->toDateString(),
                $now->copy()->addDays(3)->toDateString(),
            ])
            ->orderBy('actual_due_date')
            ->get();

        // Gráfico trimestral: últimos 3 meses, Entradas (Carteira) vs Saídas (Contas).
        $grafico = collect(range(2, 0))
            ->map(fn ($i) => $now->copy()->subMonths($i))
            ->map(function (CarbonInterface $mes) use ($userId) {
                $entradas = Income::where('user_id', $userId)
                    ->whereYear('date', $mes->year)
                    ->whereMonth('date', $mes->month)
                    ->sum('value');

                $saidas = Bill::where('user_id', $userId)
                    ->whereYear('actual_due_date', $mes->year)
                    ->whereMonth('actual_due_date', $mes->month)
                    ->sum('value');

                return [
                    'mes' => $mes->format('m/Y'),
                    'entradas' => (float) $entradas,
                    'saidas' => (float) $saidas,
                ];
            });

        return [
            'totalAPagar' => $totalAPagar,
            'totalCarteira' => $totalCarteira,
            'saldoMes' => $saldoMes,
            'despesasPorCategoria' => $despesasPorCategoria,
            'contasProximas' => $contasProximas,
            'grafico' => $grafico,
        ];
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">

    {{-- KPIs --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="p-6 bg-white border border-gray-200 rounded-lg shadow-sm dark:bg-zinc-900 dark:border-zinc-700">
            <p class="text-sm text-gray-500 dark:text-gray-400">Total a Pagar (mês atual)</p>
            <p class="text-2xl font-semibold text-red-600 dark:text-red-400 mt-1">
                R$ {{ number_format($totalAPagar, 2, ',', '.') }}
            </p>
        </div>

        <div class="p-6 bg-white border border-gray-200 rounded-lg shadow-sm dark:bg-zinc-900 dark:border-zinc-700">
            <p class="text-sm text-gray-500 dark:text-gray-400">Carteira (entradas do mês)</p>
            <p class="text-2xl font-semibold text-green-600 dark:text-green-400 mt-1">
                R$ {{ number_format($totalCarteira, 2, ',', '.') }}
            </p>
        </div>

        <div class="p-6 bg-white border border-gray-200 rounded-lg shadow-sm dark:bg-zinc-900 dark:border-zinc-700">
            <p class="text-sm text-gray-500 dark:text-gray-400">Saldo do Mês</p>
            <p class="text-2xl font-semibold mt-1 {{ $saldoMes >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                R$ {{ number_format($saldoMes, 2, ',', '.') }}
            </p>
        </div>
    </div>

    {{-- Gráfico + Contas Próximas --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="md:col-span-2 p-6 bg-white border border-gray-200 rounded-lg shadow-sm dark:bg-zinc-900 dark:border-zinc-700">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Movimentação Trimestral</h3>

            <div
                wire:ignore
                x-data="{
                    chart: null,
                    labels: @js($grafico->pluck('mes')),
                    entradas: @js($grafico->pluck('entradas')),
                    saidas: @js($grafico->pluck('saidas')),
                    initChart() {
                        this.chart = new Chart(this.$refs.canvas, {
                            type: 'bar',
                            data: {
                                labels: this.labels,
                                datasets: [
                                    { label: 'Entradas', data: this.entradas, backgroundColor: '#22c55e' },
                                    { label: 'Saídas', data: this.saidas, backgroundColor: '#ef4444' },
                                ],
                            },
                            options: { responsive: true, maintainAspectRatio: false },
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
                class="h-64"
            >
                <canvas x-ref="canvas"></canvas>
            </div>
        </div>

        <div class="p-6 bg-white border border-gray-200 rounded-lg shadow-sm dark:bg-zinc-900 dark:border-zinc-700">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Contas Próximas (3 dias)</h3>

            <ul class="space-y-3">
                @forelse ($contasProximas as $bill)
                    <li class="flex justify-between items-start text-sm gap-2">
                        <div>
                            <p class="font-medium text-gray-900 dark:text-white">{{ $bill->display_description }}</p>
                            <p class="text-gray-500 dark:text-gray-400">{{ $bill->actual_due_date->format('d/m/Y') }}</p>
                        </div>
                        <span class="font-medium text-red-600 dark:text-red-400 whitespace-nowrap">
                            R$ {{ number_format($bill->value, 2, ',', '.') }}
                        </span>
                    </li>
                @empty
                    <li class="text-sm text-gray-500 dark:text-gray-400">
                        Nenhuma conta vencendo nos próximos 3 dias.
                    </li>
                @endforelse
            </ul>
        </div>
    </div>

    {{-- Maiores Despesas por Categoria --}}
    <div class="p-6 bg-white border border-gray-200 rounded-lg shadow-sm dark:bg-zinc-900 dark:border-zinc-700">
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Maiores Despesas do Mês (por Categoria)</h3>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                <tr>
                    <th class="px-6 py-3">Categoria</th>
                    <th class="px-6 py-3">Total no Mês</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($despesasPorCategoria as $categoria)
                    <tr class="bg-white border-b dark:bg-zinc-900 dark:border-zinc-700">
                        <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">{{ $categoria->name }}</td>
                        <td class="px-6 py-4">R$ {{ number_format($categoria->total_mes, 2, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" class="px-6 py-4 text-center text-gray-500">
                            Nenhuma despesa registrada este mês.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
