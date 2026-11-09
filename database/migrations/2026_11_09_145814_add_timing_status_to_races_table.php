<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pending and complete races stay off the scoring-computer pull; lineups come down.
     */
    public function up(): void
    {
        Schema::table('races', function (Blueprint $table) {
            $table->string('timing_status')->default('pending')->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('races', function (Blueprint $table) {
            $table->dropColumn('timing_status');
        });
    }
};
