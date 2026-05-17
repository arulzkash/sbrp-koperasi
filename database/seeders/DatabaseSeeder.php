<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Student;
use App\Models\Fleet;
use App\Services\PricingService;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    const SCHOOL_LAT = -6.826864390637824;
    const SCHOOL_LNG = 107.63886429303408;

    public function run(): void
    {
        $pricingService = app(PricingService::class);

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
        $services = array_merge(array_fill(0, 70, 'full'), array_fill(0, 20, 'dropoff_only'), array_fill(0, 10, 'pickup_only'));
        $levels = array_merge(array_fill(0, 50, 'SD'), array_fill(0, 40, 'SMP'), array_fill(0, 10, 'TK'));
        $scheduleByLevel = config('student_schedule.levels');

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

                $pricing = $pricingService->calculatePricing($distanceMeters, $this->estimateDurationMin($distanceMeters), 0);
                $pricePerMonth = $pricingService->calculateServicePrice(
                    $pricing['monthly_pp'],
                    $serviceType,
                );

                $schoolLevel = $levels[array_rand($levels)];
                $classOption = $scheduleByLevel[$schoolLevel][array_rand($scheduleByLevel[$schoolLevel])];
                $classRoomNote = $schoolLevel === 'TK' ? null : ['A', 'B', 'C'][rand(0, 2)];

                Student::create([
                    'user_id' => $parents[$parentIndex]->id,
                    'fleet_id' => $fleet->id,
                    'name' => 'Siswa ' . $studentCounter,
                    'school_level' => $schoolLevel,
                    'class_room' => $classOption['value'],
                    'class_room_note' => $classRoomNote,
                    'service_type' => $serviceType,
                    'session_in' => '06:30:00',
                    'session_out' => $classOption['session_out'] . ':00',
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

    private function estimateDurationMin(float $distanceMeters): float
    {
        $speedKmh = config('pricing.fallback_speed_kmh', 18);

        return (($distanceMeters / 1000) / $speedKmh) * 60;
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
