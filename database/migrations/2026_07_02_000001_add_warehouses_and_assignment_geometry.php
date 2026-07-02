<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->decimal('start_latitude', 10, 7);
            $table->decimal('start_longitude', 10, 7);
            $table->decimal('end_latitude', 10, 7);
            $table->decimal('end_longitude', 10, 7);
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
