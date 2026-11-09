<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mark the session the Windows puller is currently listening to.
     */
    public function up(): void
    {
        Schema::table('timing_feed_results', function (Blueprint $table) {
            $table->boolean('is_live')->default(false)->after('flag');
        });
    }

    public function down(): void
    {
        Schema::table('timing_feed_results', function (Blueprint $table) {
            $table->dropColumn('is_live');
        });
    }
};
