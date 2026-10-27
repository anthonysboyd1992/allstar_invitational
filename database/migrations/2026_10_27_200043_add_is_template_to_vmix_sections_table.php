<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sections are now templates (is_template), run-of-show items (template_id set) or standalone sections (neither).
     * Every section without a template was a template until now.
     */
    public function up(): void
    {
        Schema::table('vmix_sections', function (Blueprint $table) {
            $table->boolean('is_template')->default(false)->after('template_id');
        });

        DB::table('vmix_sections')->whereNull('template_id')->update(['is_template' => true]);
    }

    public function down(): void
    {
        Schema::table('vmix_sections', function (Blueprint $table) {
            $table->dropColumn('is_template');
        });
    }
};
