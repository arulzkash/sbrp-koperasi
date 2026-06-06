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
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('morning_fleet_trip_id')
                ->nullable()
                ->after('morning_fleet_id')
                ->constrained('fleet_trips')
                ->nullOnDelete();

            $table->foreignId('afternoon_fleet_trip_id')
                ->nullable()
                ->after('afternoon_fleet_id')
                ->constrained('fleet_trips')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['morning_fleet_trip_id']);
            $table->dropForeign(['afternoon_fleet_trip_id']);
            $table->dropColumn(['morning_fleet_trip_id', 'afternoon_fleet_trip_id']);
        });
    }
};
