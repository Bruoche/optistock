<?php

namespace Database\Seeders;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use App\Models\DeliveryMode;
use Illuminate\Database\Seeder;

/**
 * Seeds the delivery_modes lookup with the exact App\Enums\DeliveryMode values
 * so the table mirrors the enum. Idempotent — safe to run repeatedly.
 */
class DeliveryModeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (DeliveryModeEnum::cases() as $mode) {
            DeliveryMode::updateOrCreate(['label' => $mode->value]);
        }
    }
}
