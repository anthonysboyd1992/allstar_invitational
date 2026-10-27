<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vmix_fields', function (Blueprint $table) {
            $table->text('live_value')->nullable()->after('value');
            $table->boolean('needs_update')->default(false)->after('live_value');
        });

        DB::table('vmix_fields')->update(['live_value' => DB::raw('value')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vmix_fields', function (Blueprint $table) {
            $table->dropColumn(['live_value', 'needs_update']);
        });
    }
};
