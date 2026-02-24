<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fleet extends Model
{

    protected $fillable = [
        'name',
        'driver_name',
        'license_plate',
        'vehicle_type',
        'capacity',
        'base_latitude',
        'base_longitude',
        'is_active',
    ];

    // Relasi untuk rute pagi
    public function morningStudents()
    {
        return $this->hasMany(Student::class, 'morning_fleet_id');
    }

    // Relasi untuk rute pulang
    public function afternoonStudents()
    {
        return $this->hasMany(Student::class, 'afternoon_fleet_id');
    }

    public function students()
    {
        return $this->hasMany(Student::class);
    }
}
