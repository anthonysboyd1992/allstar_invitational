<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hide a feed session from Live Timing and the Results picker until it is restored.
     */
    public function up(): void
    {
        Schema::table('timing_feed_results', function (Blueprint $table) {
            $table->timestamp('ignored_at')->nullable()->after('applied_at');
        });
    }

    public function down(): void
    {
        Schema::table('timing_feed_results', function (Blueprint $table) {
            $table->dropColumn('ignored_at');
        });
    }
};
