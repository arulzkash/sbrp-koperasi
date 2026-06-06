<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FleetTrip extends Model
{
    protected $fillable = [
        'fleet_id',
        'direction',
        'departure_time',
        'trip_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function fleet()
    {
        return $this->belongsTo(Fleet::class);
    }
}
