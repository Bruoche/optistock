<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature 013 — inter-tour travel time.
 *
 * A warehouse each driver departs from and returns to (mandatory, many-to-one), and
 * the geometry an assignment needs to chain a driver's day: the chosen start/end stop
 * coordinates and the day-ordering `sequence`. Pre-release with no production data, so
 * the added columns are plainly NOT NULL (a fresh migrate is expected) — no default or
 * backfill crutch; every driver-create path sets `warehouse_id` explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamps();
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
        });

        Schema::table('driver_tour', function (Blueprint $table) {
            // The start/end stop the driver enters/leaves this tour by (loop = same stop,
            // one-way = opposite endpoints), chosen as the closest valid start to the
            // preceding point at assignment time. Connecting legs are recomputed from these.
            $table->decimal('start_latitude', 10, 7);
            $table->decimal('start_longitude', 10, 7);
            $table->decimal('end_latitude', 10, 7);
            $table->decimal('end_longitude', 10, 7);
            // The driver's ordering of the day's tours; the max per (driver, date) is their
            // current latest tour (a future re-ordering feature rewrites it).
            $table->unsignedInteger('sequence');
        });
    }

    public function down(): void
    {
        Schema::table('driver_tour', function (Blueprint $table) {
            $table->dropColumn([
                'start_latitude', 'start_longitude', 'end_latitude', 'end_longitude', 'sequence',
            ]);
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });

        Schema::dropIfExists('warehouses');
    }
};
