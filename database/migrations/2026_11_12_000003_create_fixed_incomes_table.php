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
        Schema::table('incomes', function (Blueprint $table) {
            // Receita fixa (salário): ocorrência de uma série mensal sem fim (ver fixed_incomes).
            $table->boolean('is_fixed')->default(false)->after('is_recurrent');
        });

        // Mesmo papel de fixed_bills, para Receitas.
        Schema::create('fixed_incomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('income_category_id')->nullable()->constrained('income_categories')->nullOnDelete();
            $table->string('description');
            $table->decimal('value', 10, 2);
            $table->date('start_date');
            $table->uuid('recurrence_group_id')->unique();
            $table->unsignedInteger('last_generated_occurrence')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fixed_incomes');

        Schema::table('incomes', function (Blueprint $table) {
            $table->dropColumn('is_fixed');
        });
    }
};
