<?php

namespace App\Models;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use App\Enums\WeekDay as WeekDayEnum;
use Database\Factories\DriverFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'image_path', 'warehouse_id'])]
class Driver extends Model
{
    /** @use HasFactory<DriverFactory> */
    use HasFactory;

    /**
     * The warehouse this driver departs from and returns to each day (feature 013;
     * mandatory — exactly one).
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

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
     * The weekdays this driver is scheduled to work (their schedule; may be empty).
     *
     * @return BelongsToMany<WeekDay, $this>
     */
    public function weekDays(): BelongsToMany
    {
        return $this->belongsToMany(WeekDay::class, 'driver_week_day');
    }

    /**
     * The tours assigned to this driver (with the assignment date on the pivot).
     *
     * @return BelongsToMany<Tour, $this>
     */
    public function tours(): BelongsToMany
    {
        return $this->belongsToMany(Tour::class, 'driver_tour')->withPivot('date')->withTimestamps();
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
     * Drivers able to run a tour of the given mode on the given weekday —
     * supporting the mode and scheduled to work that day, alphabetical by name.
     *
     * @param  Builder<Driver>  $query
     * @return Builder<Driver>
     */
    public function scopeAvailable(Builder $query, DeliveryModeEnum $mode, WeekDayEnum $day): Builder
    {
        return $query
            ->whereHas('deliveryModes', fn (Builder $modes) => $modes->where('label', $mode->value))
            ->whereHas('weekDays', fn (Builder $days) => $days->where('label', $day->value))
            ->with(['deliveryModes', 'warehouse'])
            ->orderBy('name');
    }
}
