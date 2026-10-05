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
        Schema::create('credit_cards', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            $table->string('bank');
            $table->char('last_four_digits', 4);
            $table->string('color');
            // Opcional: sem limite, o cartão não mostra a barra de uso nem bloqueia compras.
            $table->decimal('credit_limit', 10, 2)->nullable();

            $table->unsignedTinyInteger('closing_day');
            $table->unsignedTinyInteger('due_day');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_cards');
    }
};
