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
            $table->boolean('assigns_by_pill')->default(false)->after('combines_groups');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('race_types', function (Blueprint $table) {
            $table->dropColumn('assigns_by_pill');
        });
    }
};
