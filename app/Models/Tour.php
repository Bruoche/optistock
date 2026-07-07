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
use Illuminate\Support\Collection;

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
        return $this->belongsToMany(Driver::class, 'driver_tour')
            ->withPivot('date', 'start_latitude', 'start_longitude', 'end_latitude', 'end_longitude', 'sequence')
            ->withTimestamps();
    }

    /** Whether this tour has been assigned to a driver (past attribution, no longer editable). */
    public function isAssigned(): bool
    {
        return $this->drivers()->exists();
    }

    /**
     * The stops a driver may enter/leave the tour by: any stop on a loop, only the two
     * endpoints on a one-way trip.
     *
     * @return Collection<int, Stop>
     */
    public function startCandidates(): Collection
    {
        $stops = $this->stops;

        if ($this->loop || $stops->count() <= 1) {
            return $stops;
        }

        return collect([$stops->first(), $stops->last()]);
    }

    /** The end stop implied by a chosen start (loop → same stop, one-way → opposite endpoint). */
    public function endStopForStart(Stop $start): Stop
    {
        if ($this->loop) {
            return $start;
        }

        $stops = $this->stops;
        $first = $stops->first();

        return $start->is($first) ? $stops->last() : $first;
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
