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
            // Fatura de cartão: uma Bill por cartão por vencimento nominal (due_date). Ao excluir o cartão,
            // as faturas já pagas sobrevivem como despesa comum (histórico); as pendentes são removidas
            // explicitamente pelo componente antes da exclusão.
            $table->foreignId('credit_card_id')->nullable()->after('category_id')
                ->constrained('credit_cards')->nullOnDelete();

            $table->unique(['credit_card_id', 'due_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropUnique(['credit_card_id', 'due_date']);
            $table->dropConstrainedForeignId('credit_card_id');
        });
    }
};
