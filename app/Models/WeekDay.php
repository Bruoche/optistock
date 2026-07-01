<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Persisted lookup row for a weekday. The allowed set is owned by the
 * App\Enums\WeekDay enum; this table mirrors its values (label) so a driver's
 * schedule can be related to days via a pivot. Static reference data — no timestamps.
 */
#[Fillable(['label'])]
class WeekDay extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsToMany<Driver, $this>
     */
    public function drivers(): BelongsToMany
    {
        return $this->belongsToMany(Driver::class, 'driver_week_day');
    }
}
