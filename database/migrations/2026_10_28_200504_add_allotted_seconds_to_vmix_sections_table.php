<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allotted time on any run-of-show entry. Breaks keep break_seconds as their type; copy those lengths over.
     */
    public function up(): void
    {
        Schema::table('vmix_sections', function (Blueprint $table) {
            $table->unsignedInteger('allotted_seconds')->nullable()->after('break_seconds');
        });

        DB::table('vmix_sections')->whereNotNull('break_seconds')->update([
            'allotted_seconds' => DB::raw('break_seconds'),
        ]);
    }

    public function down(): void
    {
        Schema::table('vmix_sections', function (Blueprint $table) {
            $table->dropColumn('allotted_seconds');
        });
    }
};
