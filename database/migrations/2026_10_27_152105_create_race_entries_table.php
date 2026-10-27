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
        Schema::create('race_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competitor_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('start_position');
            $table->timestamps();

            $table->unique(['race_id', 'competitor_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('race_entries');
    }
};
