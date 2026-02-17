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
        Schema::create('distance_matrices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('origin_id');
            $table->string('origin_type');
            $table->unsignedBigInteger('destination_id');
            $table->string('destination_type');

            $table->integer('distance_meters');
            $table->integer('duration_seconds');

            $table->timestamps();

            // Index biar ngebut pas searching cache
            $table->index(['origin_id', 'origin_type']);
            $table->index(['destination_id', 'destination_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('distance_matrices');
    }
};
