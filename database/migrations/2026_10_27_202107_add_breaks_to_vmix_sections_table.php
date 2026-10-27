<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A break is a run-of-show entry with a length in seconds (break_seconds) that counts down from when it goes on air (on_air_at).
     */
    public function up(): void
    {
        Schema::table('vmix_sections', function (Blueprint $table) {
            $table->unsignedInteger('break_seconds')->nullable()->after('show_order');
            $table->timestamp('on_air_at')->nullable()->after('on_air');
        });
    }

    public function down(): void
    {
        Schema::table('vmix_sections', function (Blueprint $table) {
            $table->dropColumn(['break_seconds', 'on_air_at']);
        });
    }
};
