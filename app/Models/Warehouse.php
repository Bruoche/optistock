<?php

namespace App\Models;

use App\Services\Coordinate;
use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A place a driver departs from and returns to each day. */
#[Fillable(['name', 'latitude', 'longitude'])]
class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    /**
     * @return HasMany<Driver, $this>
     */
    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    /**
     * @return Attribute<Coordinate, never>
     */
    protected function coordinate(): Attribute
    {
        return Attribute::get(fn (): Coordinate => new Coordinate($this->latitude, $this->longitude));
    }
}
