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
        Schema::table('comp_definitions', function (Blueprint $table) {
            // Start of the period the imported comp data describes: only
            // matches played since then count as the current meta.
            $table->timestamp('valid_from')->nullable()->after('levelling');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('comp_definitions', function (Blueprint $table) {
            $table->dropColumn('valid_from');
        });
    }
};
