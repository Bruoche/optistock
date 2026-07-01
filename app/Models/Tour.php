<?php

namespace App\Models;

use Database\Factories\TourFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A persisted optimized tour: its travel mode, shape (004), road totals, and its
 * ordered stops. Becomes durable when an optimization reaches `done`; a driver is
 * attached to it on assignment via the `driver_tour` association (one per tour).
 */
#[Fillable(['user_id', 'delivery_mode_id', 'loop', 'travel_duration_s', 'total_distance_m'])]
class Tour extends Model
{
    /** @use HasFactory<TourFactory> */
    use HasFactory;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'loop' => 'boolean',
        'travel_duration_s' => 'integer',
        'total_distance_m' => 'integer',
    ];

    /**
     * @return BelongsTo<DeliveryMode, $this>
     */
    public function deliveryMode(): BelongsTo
    {
        return $this->belongsTo(DeliveryMode::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Stop, $this>
     */
    public function stops(): HasMany
    {
        return $this->hasMany(Stop::class)->orderBy('position');
    }

    /**
     * The assigned drivers (at most one — the pivot's `tour_id` is unique).
     *
     * @return BelongsToMany<Driver, $this>
     */
    public function drivers(): BelongsToMany
    {
        return $this->belongsToMany(Driver::class, 'driver_tour')->withPivot('date')->withTimestamps();
    }

    /**
     * Total tour duration = road travel + per-stop delivery time. Propagates the
     * unknown state: a null travel duration (no routing call / API failure) yields
     * a null total, kept distinct from a genuine zero (FR-012). Never coerced to 0.
     *
     * @return Attribute<int|null, never>
     */
    protected function totalDurationS(): Attribute
    {
        return Attribute::get(fn (): ?int => $this->travel_duration_s === null
            ? null
            : $this->travel_duration_s + (int) $this->stops->sum('duration_s'));
    }
}
