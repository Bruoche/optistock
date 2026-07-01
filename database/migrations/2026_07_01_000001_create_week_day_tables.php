<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('week_days', function (Blueprint $table) {
            $table->id();
            $table->string('label')->unique();
        });

        Schema::create('driver_week_day', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('week_day_id')->constrained()->cascadeOnDelete();

            $table->unique(['driver_id', 'week_day_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_week_day');
        Schema::dropIfExists('week_days');
    }
};
