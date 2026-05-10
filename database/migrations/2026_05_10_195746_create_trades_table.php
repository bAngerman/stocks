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
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained()->cascadeOnDelete();
            $table->string('ticker');
            $table->string('action');
            $table->decimal('shares', 15, 4);
            $table->decimal('price_per_share', 10, 4);
            $table->decimal('total_value', 15, 2);
            $table->text('signal_reason');
            $table->text('ai_rationale')->nullable();
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->index(['persona_id', 'executed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
