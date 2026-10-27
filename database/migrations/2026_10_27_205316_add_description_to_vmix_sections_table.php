<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operator notes for templates, sections, breaks and placeholders. Not included in the master file.
     */
    public function up(): void
    {
        Schema::table('vmix_sections', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('vmix_sections', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
