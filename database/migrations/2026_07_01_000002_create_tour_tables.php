<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_mode_id')->constrained();
            $table->boolean('loop');
            // Nullable: null = road travel time undetermined (no routing call / API or
            // trace failure), kept distinct from a genuine zero (FR-012).
            $table->unsignedInteger('travel_duration_s')->nullable();
            $table->unsignedInteger('total_distance_m')->nullable();
            $table->timestamps();
        });

        Schema::create('stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_id')->constrained()->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('duration_s');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['tour_id', 'position']);
        });

        Schema::create('driver_tour', function (Blueprint $table) {
            $table->id();
            // Unique: one driver per tour. Delete = un-assign, update = re-assign (future).
            $table->foreignId('tour_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->timestamps();

            $table->index(['driver_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_tour');
        Schema::dropIfExists('stops');
        Schema::dropIfExists('tours');
    }
};
