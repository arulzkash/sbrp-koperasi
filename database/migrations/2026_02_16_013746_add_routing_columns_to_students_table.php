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
        // Relasi ke tabel fleets. Nullable karena di awal pendaftaran belum dapat mobil.
        $table->foreignId('fleet_id')->nullable()->constrained('fleets')->nullOnDelete();
        // Urutan jemput (1, 2, 3...)
        $table->integer('route_order')->nullable();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['fleet_id']);
            $table->dropColumn(['fleet_id', 'route_order']);
        });
    }
};
