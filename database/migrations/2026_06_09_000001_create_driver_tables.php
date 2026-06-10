<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('image_path')->nullable();
            $table->timestamps();
        });

        Schema::create('delivery_modes', function (Blueprint $table) {
            $table->id();
            $table->string('label')->unique();
        });

        Schema::create('driver_delivery_mode', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_mode_id')->constrained()->cascadeOnDelete();

            $table->unique(['driver_id', 'delivery_mode_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_delivery_mode');
        Schema::dropIfExists('delivery_modes');
        Schema::dropIfExists('drivers');
    }
};
