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
        Schema::create('tft_matches', function (Blueprint $table) {
            $table->id();
            $table->string('match_id', 40)->unique();
            $table->string('platform', 10);
            $table->timestamp('played_at');
            $table->unsignedInteger('game_length');
            $table->string('game_version');
            $table->unsignedInteger('queue_id')->nullable();
            $table->unsignedSmallInteger('set_number')->nullable();
            $table->string('game_type')->nullable();
            $table->timestamps();

            $table->index('played_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tft_matches');
    }
};
