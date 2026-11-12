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
        // Modelo de uma conta fixa (mensal, sem fim). As ocorrências são Bills comuns, geradas numa janela
        // móvel à frente pelo comando bills:generate-fixed; esta linha guarda o "molde" e até qual ocorrência
        // já foi gerada — assim excluir uma ocorrência avulsa não faz o gerador recriá-la.
        Schema::create('fixed_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
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
        Schema::dropIfExists('fixed_bills');
    }
};
