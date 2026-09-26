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
        Schema::table('tft_matches', function (Blueprint $table) {
            // Rank of the player through whom we found this match (e.g.
            // "CHALLENGER", "DIAMOND"): a rough indication of the lobby's level.
            $table->string('sample_tier', 20)->nullable()->after('game_type');
            $table->index('sample_tier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tft_matches', function (Blueprint $table) {
            $table->dropIndex(['sample_tier']);
            $table->dropColumn('sample_tier');
        });
    }
};
