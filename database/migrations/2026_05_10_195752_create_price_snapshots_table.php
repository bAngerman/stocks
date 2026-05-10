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
        Schema::create('price_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('ticker');
            $table->decimal('price', 10, 4);
            $table->decimal('change_percent', 8, 4);
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index(['ticker', 'fetched_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_snapshots');
    }
};
