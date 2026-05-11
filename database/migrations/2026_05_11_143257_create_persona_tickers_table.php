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
        Schema::create('persona_tickers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained()->cascadeOnDelete();
            $table->string('ticker');
            $table->string('status')->default('active');
            $table->string('source')->default('initial');
            $table->text('ai_rationale')->nullable();
            $table->integer('evaluations_without_signal')->default(0);
            $table->timestamp('promoted_at')->nullable();
            $table->timestamps();
            $table->unique(['persona_id', 'ticker']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('persona_tickers');
    }
};
