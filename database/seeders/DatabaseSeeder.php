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
        | 1. USERS (ADMIN & PARENTS)
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

        // Buat pool parent untuk di-assign ke siswa
        $parents = [];
        for ($i = 1; $i <= 200; $i++) {
            $parents[] = User::create([
                'name' => 'Parent ' . $i,
                'email' => 'parent' . $i . '@mail.com',
                'password' => Hash::make('password'),
                'role' => 'parent'
            ]);
        }
        $parentIndex = 0;

        /*
        |--------------------------------------------------------------------------
        | 2. ZONASI / BOUNDING BOX (REALISTIS LEMBANG AREA)
        |--------------------------------------------------------------------------
        */
        $zones = [
            'Cikole' => [
                'lat_min' => -6.7900,
                'lat_max' => -6.8150,
                'lng_min' => 107.6300,
                'lng_max' => 107.6500,
            ],
            'Cibodas_Maribaya' => [
                'lat_min' => -6.8100,
                'lat_max' => -6.8300,
                'lng_min' => 107.6500,
                'lng_max' => 107.6800,
            ],
            'Parongpong_Cigugur' => [
                'lat_min' => -6.8200,
                'lat_max' => -6.8500,
                'lng_min' => 107.5700,
                'lng_max' => 107.6000,
            ],
            'Kayuambon_Pusat' => [
                'lat_min' => -6.8150,
                'lat_max' => -6.8300,
                'lng_min' => 107.6100,
                'lng_max' => 107.6350,
            ],
            'Cijengkol_Areng' => [
                'lat_min' => -6.8400,
                'lat_max' => -6.8600,
                'lng_min' => 107.6100,
                'lng_max' => 107.6400,
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | 3. FLEETS (ARMADA RIIL DENGAN ASSIGNMENT ZONA)
        |--------------------------------------------------------------------------
        */
        $drivers = [
            ['name' => 'Yanto', 'vehicle' => 'Grandmax', 'capacity' => 10, 'zone' => 'Cikole'],
            ['name' => 'Wawan Irawan', 'vehicle' => 'Carry', 'capacity' => 12, 'zone' => 'Cikole'],
            ['name' => 'Sukma', 'vehicle' => 'Grandmax', 'capacity' => 10, 'zone' => 'Kayuambon_Pusat'],
            ['name' => 'Sandy', 'vehicle' => 'Grandmax', 'capacity' => 10, 'zone' => 'Kayuambon_Pusat'],
            ['name' => 'Rahmat', 'vehicle' => 'Carry', 'capacity' => 12, 'zone' => 'Cibodas_Maribaya'],
            ['name' => 'Iwan', 'vehicle' => 'Carry', 'capacity' => 12, 'zone' => 'Cibodas_Maribaya'],
            ['name' => 'Nandar', 'vehicle' => 'Kijang', 'capacity' => 8, 'zone' => 'Parongpong_Cigugur'],
            ['name' => 'Ade', 'vehicle' => 'Carry', 'capacity' => 12, 'zone' => 'Parongpong_Cigugur'],
            ['name' => 'Arul', 'vehicle' => 'Carry', 'capacity' => 12, 'zone' => 'Cijengkol_Areng'],
        ];

        // Proporsi Layanan & Jenjang Sekolah (Weighted Distribution)
        // 70% Full, 20% Pulang, 10% Pergi
        $services = array_merge(array_fill(0, 70, 'full'), array_fill(0, 20, 'dropoff_only'), array_fill(0, 10, 'pickup_only'));
        // 50% SD, 40% SMP, 10% TK
        $levels = array_merge(array_fill(0, 50, 'SD'), array_fill(0, 40, 'SMP'), array_fill(0, 10, 'TK'));

        // Proporsi Sesi Pulang: Mayoritas di 14:30 dan 15:30
        $sessionsOut = [
            '13:00:00', // Porsi kecil (biasanya TK)
            '13:30:00',
            '13:30:00', // Porsi lumayan
            '14:30:00',
            '14:30:00',
            '14:30:00',
            '14:30:00', // Porsi paling padat (SD/SMP)
            '15:30:00',
            '15:30:00',
            '15:30:00', // Porsi padat kedua
            '15:45:00' // Porsi kecil (ekskul / telat)
        ];

        /*
        |--------------------------------------------------------------------------
        | 4. GENERATE SISWA & ASSIGN ARMADA
        |--------------------------------------------------------------------------
        */
        $studentCounter = 1;

        foreach ($drivers as $i => $driver) {
            $zoneData = $zones[$driver['zone']];

            // Set base armada di tengah-tengah kotak zona mereka
            $baseLat = ($zoneData['lat_min'] + $zoneData['lat_max']) / 2;
            $baseLng = ($zoneData['lng_min'] + $zoneData['lng_max']) / 2;

            $fleet = Fleet::create([
                'name' => $driver['vehicle'] . ' ' . str_pad($i + 1, 2, '0', STR_PAD_LEFT),
                'capacity' => $driver['capacity'],
                'driver_name' => 'Pak ' . $driver['name'],
                'vehicle_type' => $driver['vehicle'],
                'license_plate' => 'D ' . rand(1000, 9999) . ' XX',
                'base_latitude' => $baseLat,
                'base_longitude' => $baseLng,
                'is_active' => true
            ]);

            // Generate siswa sesuai kapasitas maksimum mobil agar visual rute logis
            $totalStudentsInFleet = rand($driver['capacity'] - 2, $driver['capacity']);

            for ($j = 0; $j < $totalStudentsInFleet; $j++) {
                // Generate titik acak di dalam bounding box area tersebut
                $lat = $this->randomFloat($zoneData['lat_min'], $zoneData['lat_max']);
                $lng = $this->randomFloat($zoneData['lng_min'], $zoneData['lng_max']);

                $distanceMeters = $this->haversine($lat, $lng, self::SCHOOL_LAT, self::SCHOOL_LNG) * 1000;
                $serviceType = $services[array_rand($services)];

                // Kalkulasi harga menggunakan logic dari Vue
                $pricePerMonth = $this->calculatePricing($distanceMeters, $serviceType);

                Student::create([
                    'user_id' => $parents[$parentIndex]->id,
                    'fleet_id' => $fleet->id, // Langsung direlasikan!
                    'name' => 'Siswa ' . $studentCounter,
                    'school_level' => $levels[array_rand($levels)],
                    'class_room' => rand(1, 9) . ['A', 'B', 'C'][rand(0, 2)],
                    'service_type' => $serviceType,
                    'session_in' => '06:30:00',
                    'session_out' => $sessionsOut[array_rand($sessionsOut)],
                    'address_text' => 'Area ' . str_replace('_', ' ', $driver['zone']),
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'distance_to_school_meters' => $distanceMeters,
                    'price_per_month' => $pricePerMonth,
                    'payment_status' => 'paid',
                    'status' => 'registered'
                ]);

                $studentCounter++;
                $parentIndex++;
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | LOGIC TRANSLATION FROM VUE.JS
    |--------------------------------------------------------------------------
    */

    private function calculatePricing($distanceMeters, $serviceType)
    {
        $BASE_MONTHLY_PP = 250000;
        $RATE_MONTHLY_PER_MINUTE_PP = 1000;
        $ONE_WAY_RATIO = 0.52;
        $FALLBACK_SPEED_KMH = 18;

        $distanceKm = $distanceMeters / 1000;
        // Simulasi durasi karena tidak tembak API OSRM di seeder
        $durationMin = ($distanceKm / $FALLBACK_SPEED_KMH) * 60;

        $distanceCharge = $this->calculateDistanceCharge($distanceMeters);
        $durationCharge = $durationMin * $RATE_MONTHLY_PER_MINUTE_PP;

        $monthlyPPRaw = $BASE_MONTHLY_PP + $distanceCharge + $durationCharge;

        // Pembulatan ke atas kelipatan 1000
        $monthlyPP = ceil($monthlyPPRaw / 1000) * 1000;
        $monthlyOneWay = ceil(($monthlyPP * $ONE_WAY_RATIO) / 1000) * 1000;

        return ($serviceType === 'full') ? $monthlyPP : $monthlyOneWay;
    }

    private function calculateDistanceCharge($meters)
    {
        $bands = [
            ['upto' => 1000, 'rate' => 15],
            ['upto' => 2000, 'rate' => 50],
            ['upto' => 4000, 'rate' => 55],
            ['upto' => 10000, 'rate' => 13],
            ['upto' => INF, 'rate' => 8],
        ];

        $total = 0;
        $previousLimit = 0;

        foreach ($bands as $band) {
            $upperLimit = $band['upto'];

            if ($upperLimit === INF) {
                $bandMeters = max(0, $meters - $previousLimit);
            } else {
                $bandMeters = max(0, min($meters, $upperLimit) - $previousLimit);
            }

            $total += $bandMeters * $band['rate'];
            $previousLimit = $upperLimit;

            if ($meters <= $upperLimit) {
                break;
            }
        }

        return $total;
    }

    private function haversine($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371; // KM

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c; // Return in KM
    }

    private function randomFloat($min, $max)
    {
        return $min + mt_rand() / mt_getrandmax() * ($max - $min);
    }
}
