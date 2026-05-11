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
        Schema::create('persona_portfolio_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained()->cascadeOnDelete();
            $table->decimal('total_value', 15, 2);
            $table->decimal('cash_balance', 15, 2);
            $table->timestamp('snapshotted_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('persona_portfolio_snapshots');
    }
};
