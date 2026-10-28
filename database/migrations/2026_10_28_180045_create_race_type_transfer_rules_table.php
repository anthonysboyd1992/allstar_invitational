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
        Schema::create('race_type_transfer_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position_from');
            $table->unsignedInteger('position_to')->nullable();
            $table->foreignId('destination_race_type_id')->constrained('race_types')->cascadeOnDelete();
            $table->string('distribute', 20);
            $table->unsignedInteger('destination_start_slot')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('race_type_transfer_rules');
    }
};
