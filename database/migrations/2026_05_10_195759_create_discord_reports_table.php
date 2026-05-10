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
        Schema::create('discord_reports', function (Blueprint $table) {
            $table->id();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->json('payload');
            $table->timestamp('posted_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discord_reports');
    }
};
