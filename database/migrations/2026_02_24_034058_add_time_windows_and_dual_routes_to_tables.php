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
        // 1. Tambah detail supir dan mobil di tabel Fleets
        Schema::table('fleets', function (Blueprint $table) {
            $table->string('driver_name')->nullable()->after('name'); // Nama supir (Pak Cecep, dll)
            $table->string('license_plate')->nullable()->after('driver_name'); // Plat nomor
            $table->string('vehicle_type')->nullable()->after('license_plate'); // Tipe: Elf, Angkot
        });

        // 2. Tambah Sesi Waktu dan Rute Ganda di tabel Students
        Schema::table('students', function (Blueprint $table) {
            // Jenis layanan
            $table->enum('service_type', ['full', 'pickup_only', 'dropoff_only'])
                ->default('full')
                ->after('school_level'); // Antar-Jemput, Berangkat Saja, Pulang Saja

            // Sesi / Jam
            $table->time('session_in')->nullable()->after('service_type'); // Jam berangkat/masuk (misal: 06:30)
            $table->time('session_out')->nullable()->after('session_in'); // Jam pulang (misal: 12:00, 14:00)

            // Rute Berangkat (Pagi)
            $table->foreignId('morning_fleet_id')->nullable()->constrained('fleets')->nullOnDelete()->after('payment_status');
            $table->integer('morning_route_order')->nullable()->after('morning_fleet_id');

            // Rute Pulang (Siang/Sore)
            $table->foreignId('afternoon_fleet_id')->nullable()->constrained('fleets')->nullOnDelete()->after('morning_route_order');
            $table->integer('afternoon_route_order')->nullable()->after('afternoon_fleet_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['morning_fleet_id']);
            $table->dropForeign(['afternoon_fleet_id']);
            $table->dropColumn([
                'service_type', 'session_in', 'session_out', 
                'morning_fleet_id', 'morning_route_order', 
                'afternoon_fleet_id', 'afternoon_route_order'
            ]);
        });

        Schema::table('fleets', function (Blueprint $table) {
            $table->dropColumn(['driver_name', 'license_plate', 'vehicle_type']);
        });
    }
};
