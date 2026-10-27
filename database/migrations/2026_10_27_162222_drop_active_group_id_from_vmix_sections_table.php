<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vmix_sections', function (Blueprint $table) {
            $table->dropColumn('active_group_id');
        });

        Schema::table('vmix_sections', function (Blueprint $table) {
            $table->boolean('on_air')->default(false)->after('key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vmix_sections', function (Blueprint $table) {
            $table->dropColumn('on_air');
        });

        Schema::table('vmix_sections', function (Blueprint $table) {
            $table->unsignedBigInteger('active_group_id')->nullable()->after('key');
        });
    }
};
