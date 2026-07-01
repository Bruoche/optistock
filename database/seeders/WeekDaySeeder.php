<?php

namespace Database\Seeders;

use App\Enums\WeekDay as WeekDayEnum;
use App\Models\WeekDay;
use Illuminate\Database\Seeder;

/**
 * Seeds the week_days lookup with the exact App\Enums\WeekDay values so the table
 * mirrors the enum. Idempotent — safe to run repeatedly.
 */
class WeekDaySeeder extends Seeder
{
    public function run(): void
    {
        foreach (WeekDayEnum::cases() as $day) {
            WeekDay::updateOrCreate(['label' => $day->value]);
        }
    }
}
