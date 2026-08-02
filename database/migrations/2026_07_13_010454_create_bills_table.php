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
        Schema::create('bills', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            $table->string('description');
            $table->decimal('value', 10, 2);

            $table->date('due_date');
            $table->date('actual_due_date');

            $table->boolean('is_recurrent')->default(false);

            $table->integer('total_installments')->default(1);
            $table->integer('current_installments')->default(1);

            $table->uuid('recurrence_group_id')->nullable()->index();

            $table->unsignedTinyInteger('status')->default(1);

            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
