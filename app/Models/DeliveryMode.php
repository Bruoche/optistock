<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Persisted lookup row for a delivery mode. The allowed set is owned by the
 * App\Enums\DeliveryMode enum; this table mirrors its values (label) so drivers
 * can be related to modes via a pivot. Static reference data — no timestamps.
 */
#[Fillable(['label'])]
class DeliveryMode extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsToMany<Driver, $this>
     */
    public function drivers(): BelongsToMany
    {
        return $this->belongsToMany(Driver::class, 'driver_delivery_mode');
    }
}
