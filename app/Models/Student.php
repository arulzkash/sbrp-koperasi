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
        'class_room',
        'service_type',
        'session_in',
        'session_out',
        'address_text',
        'latitude',
        'longitude',
        'distance_to_school_meters',
        'price_per_month',
        'status',
        'payment_status',
        'route_order',
        'morning_fleet_id',
        'morning_route_order',
        'afternoon_fleet_id',
        'afternoon_route_order',
    ];

    public function fleet()
    {
        return $this->belongsTo(Fleet::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function morningFleet()
    {
        return $this->belongsTo(Fleet::class, 'morning_fleet_id');
    }

    public function afternoonFleet()
    {
        return $this->belongsTo(Fleet::class, 'afternoon_fleet_id');
    }
}
