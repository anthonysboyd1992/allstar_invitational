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
            $table->boolean('combines_groups')->default(false)->after('cars_allowed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('race_types', function (Blueprint $table) {
            $table->dropColumn('combines_groups');
        });
    }
};
