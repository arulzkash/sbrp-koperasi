<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    protected $fillable = [
        'user_id',
        'fleet_id',
        'name',
        'school_level',
        'address_text',
        'latitude',
        'longitude',
        'distance_to_school_meters',
        'price_per_month',
        'status',
        'payment_status',
        'route_order',
    ];

    public function fleet()
    {
        return $this->belongsTo(Fleet::class);
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
