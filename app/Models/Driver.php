<?php

namespace App\Models;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use Database\Factories\DriverFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'image_path'])]
class Driver extends Model
{
    /** @use HasFactory<DriverFactory> */
    use HasFactory;

    /**
     * The delivery modes this driver can run (one or more).
     *
     * @return BelongsToMany<DeliveryMode, $this>
     */
    public function deliveryModes(): BelongsToMany
    {
        return $this->belongsToMany(DeliveryMode::class, 'driver_delivery_mode');
    }

    /**
     * Public URL for the driver's image, or null when none is stored.
     *
     * @return Attribute<string|null, never>
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->image_path === null
            ? null
            : Storage::disk('public')->url($this->image_path));
    }

    /**
     * Drivers able to run a tour of the given mode, alphabetical by name.
     *
     * @param  Builder<Driver>  $query
     * @return Builder<Driver>
     */
    public function scopeAvailable(Builder $query, DeliveryModeEnum $mode): Builder
    {
        return $query
            ->whereHas('deliveryModes', fn (Builder $modes) => $modes->where('label', $mode->value))
            ->with('deliveryModes')
            ->orderBy('name');
    }
}
