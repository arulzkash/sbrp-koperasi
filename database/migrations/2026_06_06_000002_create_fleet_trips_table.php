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
        Schema::create('fleet_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_id')->constrained('fleets')->cascadeOnDelete();
            $table->enum('direction', ['morning', 'afternoon']);
            $table->time('departure_time');
            $table->unsignedInteger('trip_order');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['fleet_id', 'direction', 'departure_time', 'trip_order'], 'fleet_trips_unique_trip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fleet_trips');
    }
};
