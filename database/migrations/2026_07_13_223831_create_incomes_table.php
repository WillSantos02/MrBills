<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incomes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('income_category_id')->nullable()->constrained('income_categories')->nullOnDelete();

            $table->string('description');
            $table->decimal('value', 10, 2);
            $table->date('date');

            $table->boolean('is_recurrent')->default(false);
            $table->integer('total_installments')->default(1);
            $table->integer('current_installments')->default(1);
            $table->uuid('recurrence_group_id')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incomes');
    }
};
