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
        Schema::create('comp_definitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('set_number');
            $table->string('external_id', 40);
            $table->string('name');
            // Unit API names that make up the comp.
            $table->json('units');
            // The units named in the comp name (its carries).
            $table->json('carries');
            $table->json('traits');
            // e.g. "Fast 8", "Fast 9", "lvl 6" (reroll at level 6).
            $table->string('levelling')->nullable();
            $table->timestamps();

            $table->unique(['set_number', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comp_definitions');
    }
};
