<?php

namespace Tests\Unit;

use App\Enums\WeekDay as WeekDayEnum;
use App\Models\WeekDay;
use Carbon\Carbon;
use Database\Seeders\WeekDaySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeekDayTest extends TestCase
{
    use RefreshDatabase;

    public function test_from_date_matches_the_calendar_weekday(): void
    {
        // Walk two weeks so every weekday is exercised; compare against PHP's own
        // day-name formatting (independent of the enum's ISO mapping).
        $date = Carbon::parse('2026-01-01');

        for ($i = 0; $i < 14; $i++) {
            $expected = strtolower($date->format('l'));

            $this->assertSame($expected, WeekDayEnum::fromDate($date)->value, $date->toDateString());

            $date = $date->copy()->addDay();
        }
    }

    public function test_seeded_labels_match_the_enum(): void
    {
        $this->seed(WeekDaySeeder::class);

        $labels = WeekDay::query()->pluck('label')->sort()->values()->all();
        $expected = collect(WeekDayEnum::cases())
            ->map(fn (WeekDayEnum $day): string => $day->value)
            ->sort()->values()->all();

        $this->assertSame($expected, $labels);
    }
}
