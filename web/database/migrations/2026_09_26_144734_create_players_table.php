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
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->string('puuid', 100)->unique();
            $table->string('platform', 10);
            $table->string('game_name');
            $table->string('tag_line', 10);
            $table->json('league')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('sync_started_at')->nullable();
            $table->string('sync_error')->nullable();
            $table->timestamps();

            $table->index(['platform', 'game_name', 'tag_line']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
