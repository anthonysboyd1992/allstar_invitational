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
        Schema::table('race_entries', function (Blueprint $table) {
            $table->unsignedInteger('finish_position')->nullable()->after('start_position');
            $table->foreignId('source_race_id')->nullable()->after('finish_position')->constrained('races')->cascadeOnDelete();
            $table->unique(['race_id', 'finish_position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('race_entries', function (Blueprint $table) {
            $table->dropUnique(['race_id', 'finish_position']);
            $table->dropConstrainedForeignId('source_race_id');
            $table->dropColumn('finish_position');
        });
    }
};
