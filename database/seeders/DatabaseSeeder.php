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

        for ($i=1;$i<=220;$i++) {

            $parents[] = User::create([
                'name' => 'Parent '.$i,
                'email' => 'parent'.$i.'@mail.com',
                'password' => Hash::make('password'),
                'role' => 'parent'
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | FLEETS (REAL DRIVER LIST)
        |--------------------------------------------------------------------------
        */

        $drivers = [

            ['name'=>'Cecep','vehicle'=>'Elf','capacity'=>12],
            ['name'=>'Sukma','vehicle'=>'Avanza','capacity'=>6],
            ['name'=>'Sandi Safaat','vehicle'=>'Luxio','capacity'=>7],
            ['name'=>'Hendra','vehicle'=>'Elf','capacity'=>12],
            ['name'=>'Wira','vehicle'=>'Avanza','capacity'=>6],
            ['name'=>'Nandar','vehicle'=>'Luxio','capacity'=>7],
            ['name'=>'Anto','vehicle'=>'Avanza','capacity'=>6],
            ['name'=>'Rahmat','vehicle'=>'Elf','capacity'=>12],
            ['name'=>'Sandy','vehicle'=>'Luxio','capacity'=>7],
            ['name'=>'Ade','vehicle'=>'Avanza','capacity'=>6],
            ['name'=>'Jajang','vehicle'=>'Elf','capacity'=>12],
            ['name'=>'Asep','vehicle'=>'Avanza','capacity'=>6],
            ['name'=>'Wawan Irawan','vehicle'=>'Luxio','capacity'=>7],
            ['name'=>'Acep','vehicle'=>'Avanza','capacity'=>6],
            ['name'=>'Iwan','vehicle'=>'Luxio','capacity'=>7],
            ['name'=>'Yanto','vehicle'=>'Avanza','capacity'=>6],
            ['name'=>'Ajat','vehicle'=>'Elf','capacity'=>12],
            ['name'=>'Bu Tuti','vehicle'=>'Avanza','capacity'=>6],

        ];

        foreach ($drivers as $i => $driver) {

            Fleet::create([

                'name' => $driver['vehicle'].' '.str_pad($i+1,2,'0',STR_PAD_LEFT),
                'capacity' => $driver['capacity'],
                'driver_name' => 'Pak '.$driver['name'],
                'vehicle_type' => $driver['vehicle'],
                'license_plate' => 'D '.rand(1000,9999).' XX',

                'base_latitude' => -6.82 + (rand(-200,200)/10000),
                'base_longitude' => 107.63 + (rand(-200,200)/10000),

                'is_active' => true
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | REAL AREA CLUSTERS
        |--------------------------------------------------------------------------
        */

        $areas = [

            ['name'=>'Langensari','lat'=>-6.8115,'lng'=>107.6172,'count'=>40],
            ['name'=>'Sukajaya','lat'=>-6.8058,'lng'=>107.6105,'count'=>26],
            ['name'=>'Cibodas','lat'=>-6.7985,'lng'=>107.6120,'count'=>22],
            ['name'=>'Wangunsari','lat'=>-6.8032,'lng'=>107.5980,'count'=>18],
            ['name'=>'Gunung Sari','lat'=>-6.8090,'lng'=>107.6150,'count'=>15],

            ['name'=>'Cihideung','lat'=>-6.8420,'lng'=>107.5900,'count'=>26],
            ['name'=>'Cigugur Girang','lat'=>-6.8450,'lng'=>107.5850,'count'=>22],
            ['name'=>'Cihanjuang','lat'=>-6.8480,'lng'=>107.5750,'count'=>18],

            ['name'=>'Sersan Bajuri','lat'=>-6.8350,'lng'=>107.5950,'count'=>15],

            ['name'=>'Maribaya','lat'=>-6.8000,'lng'=>107.6400,'count'=>8],
            ['name'=>'Kolonel Masturi','lat'=>-6.8200,'lng'=>107.6000,'count'=>6],
            ['name'=>'Karyawangi','lat'=>-6.8300,'lng'=>107.5700,'count'=>4],

        ];


        $levels = ['TK','SD','SMP'];

        $sessions = [
            '13:00:00',
            '13:30:00',
            '14:30:00',
            '15:30:00',
            '15:45:00'
        ];


        /*
        |--------------------------------------------------------------------------
        | SERVICE DISTRIBUTION (REALISTIC)
        |--------------------------------------------------------------------------
        */

        $services = [
            'full','full','full','full','full','full','full',
            'pickup_only','pickup_only',
            'dropoff_only'
        ];


        /*
        |--------------------------------------------------------------------------
        | GENERATE STUDENTS
        |--------------------------------------------------------------------------
        */

        $studentIndex = 0;

        foreach ($areas as $area) {

            for ($i=0;$i<$area['count'];$i++) {

                $lat = $area['lat'] + (rand(-35,35)/10000);
                $lng = $area['lng'] + (rand(-35,35)/10000);

                $dist = $this->haversine(
                    $lat,
                    $lng,
                    self::SCHOOL_LAT,
                    self::SCHOOL_LNG
                );

                Student::create([

                    'user_id' => $parents[$studentIndex]->id,

                    'name' => 'Siswa '.($studentIndex+1),

                    'school_level' => $levels[array_rand($levels)],

                    'class_room' => rand(1,9),

                    'service_type' => $services[array_rand($services)],

                    'session_in' => '06:30:00',

                    'session_out' => $sessions[array_rand($sessions)],

                    'address_text' => $area['name'],

                    'latitude' => $lat,
                    'longitude' => $lng,

                    'distance_to_school_meters' => $dist * 1000,

                    'price_per_month' => $dist * 2000,

                    'payment_status' => 'paid',

                    'status' => 'registered'
                ]);

                $studentIndex++;
            }
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