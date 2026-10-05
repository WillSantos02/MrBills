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
        Schema::create('credit_card_purchases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('credit_card_id')->constrained('credit_cards')->onDelete('cascade');
            // A fatura (Bill) a que a compra pertence — calculada a partir de purchase_date e do ciclo do cartão.
            $table->foreignId('bill_id')->constrained('bills')->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->string('description');
            $table->decimal('value', 10, 2);
            $table->date('purchase_date');
            $table->text('notes')->nullable();

            // Parcelamento: mesmo padrão de Bill (todas as parcelas criadas de uma vez, uma por fatura).
            $table->integer('total_installments')->default(1);
            $table->integer('current_installments')->default(1);
            $table->uuid('installment_group_id')->nullable()->index();

            // Lançamento gerado pelo sistema: saldo de uma fatura que venceu sem ser paga, transportado
            // para a fatura seguinte. Único — cada fatura vencida é transportada uma vez só.
            $table->foreignId('carried_from_bill_id')->nullable()->unique()
                ->constrained('bills')->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_card_purchases');
    }
};
