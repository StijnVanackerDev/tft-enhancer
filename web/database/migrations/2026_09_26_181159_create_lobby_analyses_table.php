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
        Schema::create('lobby_analyses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 10);
            // "match": the lobby of a finished match, "live": a game in progress.
            $table->string('source', 10);
            $table->string('source_id', 40);
            $table->json('participants');
            // Only matches that started before this moment count as history.
            $table->timestamp('history_before')->nullable();
            $table->unsignedSmallInteger('set_number')->nullable();
            $table->string('status', 10)->default('queued');
            $table->unsignedInteger('progress_done')->default(0);
            $table->unsignedInteger('progress_total')->default(0);
            $table->timestamp('waiting_until')->nullable();
            $table->string('message')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->index(['source', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lobby_analyses');
    }
};
