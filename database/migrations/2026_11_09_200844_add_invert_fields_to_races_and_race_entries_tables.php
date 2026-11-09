<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pit-break invert stores restart spots without overwriting original starts.
     */
    public function up(): void
    {
        Schema::table('races', function (Blueprint $table) {
            $table->timestamp('inverted_at')->nullable()->after('timing_status');
            $table->unsignedInteger('invert_count')->nullable()->after('inverted_at');
            $table->foreignId('live_feed_id')->nullable()->after('invert_count')->constrained('timing_feed_results')->nullOnDelete();
        });

        Schema::table('race_entries', function (Blueprint $table) {
            $table->unsignedInteger('break_position')->nullable()->after('start_position');
            $table->unsignedInteger('invert_start_position')->nullable()->after('break_position');
        });
    }

    public function down(): void
    {
        Schema::table('race_entries', function (Blueprint $table) {
            $table->dropColumn(['break_position', 'invert_start_position']);
        });

        Schema::table('races', function (Blueprint $table) {
            $table->dropConstrainedForeignId('live_feed_id');
            $table->dropColumn(['inverted_at', 'invert_count']);
        });
    }
};
