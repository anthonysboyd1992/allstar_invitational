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
        Schema::table('timing_feed_results', function (Blueprint $table) {
            $table->unsignedSmallInteger('laps_to_go')->nullable()->after('flag');
            $table->unsignedSmallInteger('race_laps')->nullable()->after('laps_to_go');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('timing_feed_results', function (Blueprint $table) {
            $table->dropColumn(['laps_to_go', 'race_laps']);
        });
    }
};
