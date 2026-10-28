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
        Schema::table('race_types', function (Blueprint $table) {
            $table->unsignedInteger('cars_allowed')->nullable()->after('cars_per_race');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('race_types', function (Blueprint $table) {
            $table->dropColumn('cars_allowed');
        });
    }
};
