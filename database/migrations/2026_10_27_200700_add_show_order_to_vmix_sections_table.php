<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Position in the run of show, shared by template items and standalone sections. Null means not in the run of show.
     */
    public function up(): void
    {
        Schema::table('vmix_sections', function (Blueprint $table) {
            $table->unsignedInteger('show_order')->nullable()->after('sort_order');
        });

        DB::table('vmix_sections')->whereNotNull('template_id')->update(['show_order' => DB::raw('sort_order')]);
    }

    public function down(): void
    {
        Schema::table('vmix_sections', function (Blueprint $table) {
            $table->dropColumn('show_order');
        });
    }
};
