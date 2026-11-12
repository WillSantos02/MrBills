<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            // Gasto pontual (padaria, Pix na hora): lançado já pago, na data em que aconteceu — sem o rollover
            // de fim de semana, que só faz sentido para vencimento de boleto.
            $table->boolean('is_spot')->default(false)->after('is_recurrent');

            // Conta fixa (energia, água, aluguel): ocorrência de uma série mensal sem fim (ver fixed_bills).
            $table->boolean('is_fixed')->default(false)->after('is_spot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn(['is_spot', 'is_fixed']);
        });
    }
};
