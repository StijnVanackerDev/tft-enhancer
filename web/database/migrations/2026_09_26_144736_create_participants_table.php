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
        Schema::create('participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tft_match_id')->constrained()->cascadeOnDelete();
            $table->string('puuid', 100)->index();
            $table->string('game_name')->nullable();
            $table->string('tag_line', 10)->nullable();
            $table->unsignedTinyInteger('placement');
            $table->unsignedTinyInteger('level');
            $table->unsignedSmallInteger('gold_left');
            $table->unsignedSmallInteger('last_round');
            $table->unsignedSmallInteger('damage_to_players')->default(0);
            $table->unsignedTinyInteger('players_eliminated')->default(0);
            // Augments are deliberately not stored: Riot/Overwolf don't allow
            // showing augment placement stats.
            $table->json('traits');
            $table->json('units');
            $table->timestamps();

            $table->unique(['tft_match_id', 'puuid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('participants');
    }
};
