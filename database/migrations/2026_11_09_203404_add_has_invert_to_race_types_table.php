<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Feature-style types invert at a pit break; heats do not.
     */
    public function up(): void
    {
        Schema::table('race_types', function (Blueprint $table) {
            $table->boolean('has_invert')->default(false)->after('has_results');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('race_types', function (Blueprint $table) {
            $table->dropColumn('has_invert');
        });
    }
};
