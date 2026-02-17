<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    protected $fillable = [
        'name',
        'latitude',
        'longitude',
        'fleet_id',
        'route_order',
    ];

    public function fleet()
    {
        return $this->belongsTo(Fleet::class);
    }
}
