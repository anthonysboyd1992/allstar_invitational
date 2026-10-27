<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A placeholder is a named run-of-show slot with no fields. It is not in the master file.
     */
    public function up(): void
    {
        Schema::table('vmix_sections', function (Blueprint $table) {
            $table->boolean('is_placeholder')->default(false)->after('is_template');
        });
    }

    public function down(): void
    {
        Schema::table('vmix_sections', function (Blueprint $table) {
            $table->dropColumn('is_placeholder');
        });
    }
};
