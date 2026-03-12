<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Student;
use App\Models\Fleet;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    const SCHOOL_LAT = -6.826864390637824;
    const SCHOOL_LNG = 107.63886429303408;

    public function run(): void
    {

        /*
        |--------------------------------------------------------------------------
        | USERS
        |--------------------------------------------------------------------------
        */

        User::create([
            'name' => 'Pak Asep (Manager)',
            'email' => 'manager@koperasi.com',
            'password' => Hash::make('password'),
            'role' => 'manager'
        ]);

        User::create([
            'name' => 'Bu Siti (Finance)',
            'email' => 'finance@koperasi.com',
            'password' => Hash::make('password'),
            'role' => 'finance'
        ]);

        $parents = [];

        for ($i=1;$i<=30;$i++) {

            $parents[] = User::create([
                'name' => 'Parent '.$i,
                'email' => 'parent'.$i.'@mail.com',
                'password' => Hash::make('password'),
                'role' => 'parent'
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | FLEETS
        |--------------------------------------------------------------------------
        */

        Fleet::create([
            'name' => 'Elf 01',
            'capacity' => 12,
            'driver_name' => 'Pak Cecep',
            'vehicle_type' => 'Elf',
            'license_plate' => 'D 1234 AB',
            'base_latitude' => -6.8123,
            'base_longitude' => 107.6145,
            'is_active' => true
        ]);

        Fleet::create([
            'name' => 'Avanza 01',
            'capacity' => 6,
            'driver_name' => 'Pak Asep',
            'vehicle_type' => 'Avanza',
            'license_plate' => 'D 2234 AC',
            'base_latitude' => -6.8300,
            'base_longitude' => 107.6050,
            'is_active' => true
        ]);

        Fleet::create([
            'name' => 'Luxio 01',
            'capacity' => 7,
            'driver_name' => 'Pak Dedi',
            'vehicle_type' => 'Luxio',
            'license_plate' => 'D 9988 XY',
            'base_latitude' => -6.8000,
            'base_longitude' => 107.6200,
            'is_active' => true
        ]);

        Fleet::create([
            'name' => 'Elf 02',
            'capacity' => 12,
            'driver_name' => 'Pak Ujang',
            'vehicle_type' => 'Elf',
            'license_plate' => 'D 7777 ZZ',
            'base_latitude' => -6.8450,
            'base_longitude' => 107.6250,
            'is_active' => true
        ]);

        /*
        |--------------------------------------------------------------------------
        | STUDENT AREA CLUSTERS (Bandung Utara)
        |--------------------------------------------------------------------------
        */

        $areas = [

            ['lat'=>-6.8600,'lng'=>107.5900], // Setiabudi
            ['lat'=>-6.8500,'lng'=>107.6100], // Dago
            ['lat'=>-6.8400,'lng'=>107.6200], // Ciumbuleuit
            ['lat'=>-6.8300,'lng'=>107.6400], // Lembang
            ['lat'=>-6.8800,'lng'=>107.6000], // Parongpong
            ['lat'=>-6.8100,'lng'=>107.6500], // Maribaya

        ];

        $levels = ['TK','SD','SMP'];

        $sessions = [
            '13:00:00',
            '13:30:00',
            '14:30:00',
            '15:30:00',
            '15:45:00'
        ];

        $services = [
            'full',
            'pickup_only',
            'dropoff_only'
        ];

        /*
        |--------------------------------------------------------------------------
        | GENERATE 30 STUDENTS
        |--------------------------------------------------------------------------
        */

        for ($i=0;$i<30;$i++) {

            $area = $areas[array_rand($areas)];

            $lat = $area['lat'] + (rand(-50,50)/10000);
            $lng = $area['lng'] + (rand(-50,50)/10000);

            $dist = $this->haversine(
                $lat,
                $lng,
                self::SCHOOL_LAT,
                self::SCHOOL_LNG
            );

            Student::create([

                'user_id' => $parents[$i]->id,

                'name' => 'Siswa '.($i+1),

                'school_level' => $levels[array_rand($levels)],

                'class_room' => rand(1,9),

                'service_type' => $services[array_rand($services)],

                'session_in' => '06:30:00',

                'session_out' => $sessions[array_rand($sessions)],

                'address_text' => 'Bandung Utara',

                'latitude' => $lat,
                'longitude' => $lng,

                'distance_to_school_meters' => $dist * 1000,

                'price_per_month' => $dist * 2000,

                'payment_status' => 'paid',

                'status' => 'registered'
            ]);
        }
    }

    private function haversine($lat1,$lon1,$lat2,$lon2)
    {
        $earthRadius = 6371;

        $dLat = deg2rad($lat2-$lat1);
        $dLon = deg2rad($lon2-$lon1);

        $a =
            sin($dLat/2)*sin($dLat/2) +
            cos(deg2rad($lat1)) *
            cos(deg2rad($lat2)) *
            sin($dLon/2)*sin($dLon/2);

        $c = 2 * atan2(sqrt($a), sqrt(1-$a));

        return $earthRadius * $c;
    }
}