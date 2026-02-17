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
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Relasi ke Ortu
            $table->string('name');
            $table->enum('school_level', ['TK', 'SD', 'SMP']);

            $table->text('address_text'); // Alamat ketikan manual
            $table->decimal('latitude', 10, 8); // Pin Map
            $table->decimal('longitude', 11, 8); // Pin Map

            // Data Logistik
            $table->integer('distance_to_school_meters')->nullable();
            $table->decimal('price_per_month', 10, 2)->nullable();

            // Status
            $table->enum('status', ['draft', 'registered', 'active', 'inactive'])->default('draft');
            $table->enum('payment_status', ['unpaid', 'paid'])->default('unpaid');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
