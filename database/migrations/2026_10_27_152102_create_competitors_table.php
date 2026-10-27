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
        Schema::create('competitors', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pill_number')->nullable()->unique();
            $table->string('car_number')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('hometown')->nullable();
            $table->unsignedInteger('weight')->nullable();
            $table->text('accolades')->nullable();
            $table->string('transponder')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('competitors');
    }
};
