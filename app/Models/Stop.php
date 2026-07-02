<?php

namespace App\Models;

use App\Services\Coordinate;
use Database\Factories\StopFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stop in a persisted tour: its coordinate, per-stop delivery duration (007),
 * and 0-based position in the optimized visiting order.
 */
#[Fillable(['tour_id', 'latitude', 'longitude', 'duration_s', 'position'])]
class Stop extends Model
{
    /** @use HasFactory<StopFactory> */
    use HasFactory;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'duration_s' => 'integer',
        'position' => 'integer',
    ];

    /**
     * @return BelongsTo<Tour, $this>
     */
    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }

    /**
     * @return Attribute<Coordinate, never>
     */
    protected function coordinate(): Attribute
    {
        return Attribute::get(fn (): Coordinate => new Coordinate($this->latitude, $this->longitude));
    }
}
