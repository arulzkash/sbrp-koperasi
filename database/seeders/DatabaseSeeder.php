<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Student;
use App\Models\Fleet;
use App\Models\DistanceMatrix;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Koordinat Pusat Lembang (Alun-alun / Masjid Besar)
     * Kita anggap Sekolah ada di sekitar sini.
     */
    const SCHOOL_LAT = -6.815348;
    const SCHOOL_LNG = 107.616659;

    public function run(): void
    {
        // 1. Akun Pengelola Koperasi (Si Pengatur Rute)
        User::factory()->create([
            'name' => 'Pak Asep (Pengelola Ops)',
            'email' => 'admin.ops@koperasi.com',
            'password' => Hash::make('password'),
            'role' => 'manager',
        ]);

        // 2. Akun Admin Keuangan (Si Kasir/Bendahara)
        User::factory()->create([
            'name' => 'Bu Siti (Admin Keuangan)',
            'email' => 'admin.keuangan@koperasi.com',
            'password' => Hash::make('password'),
            'role' => 'finance',
        ]);

        // 3. Akun Orang Tua (User Biasa)
        $ortu = User::factory()->create([
            'name' => 'Bapak Budi (Ortu)',
            'email' => 'ortu@gmail.com',
            'password' => Hash::make('password'),
            'role' => 'parent',
        ]);

        // 2. Buat Armada (Fleets)
        // Kita sebar pool-nya di beberapa titik strategis
        $fleets = [
            [
                'name' => 'Armada 01 - Elf Long',
                'capacity' => 15,
                'base_latitude' => -6.812300, // Sekitaran Pasar Lembang
                'base_longitude' => 107.614500,
            ],
            [
                'name' => 'Armada 02 - Avanza',
                'capacity' => 6,
                'base_latitude' => -6.830000, // Sekitaran Farmhouse
                'base_longitude' => 107.605000,
            ],
            [
                'name' => 'Armada 03 - Luxio',
                'capacity' => 7,
                'base_latitude' => -6.800000, // Sekitaran Cikahuripan
                'base_longitude' => 107.620000,
            ],
        ];

        foreach ($fleets as $f) {
            Fleet::create($f);
        }

        // 3. Buat Data Siswa (Dummy Lembang Area)
        // Kita sebar di 3 cluster: Setiabudi, Maribaya, Parongpong
        $studentsData = [
            // Cluster A: Setiabudi/Ledeng (Jauh dari sekolah)
            ['name' => 'Anak A (Setiabudi)', 'lat' => -6.860123, 'lng' => 107.590123, 'status' => 'active', 'pay' => 'paid'],
            ['name' => 'Anak B (Setiabudi)', 'lat' => -6.862123, 'lng' => 107.592123, 'status' => 'active', 'pay' => 'paid'],

            // Cluster B: Maribaya (Timur)
            ['name' => 'Anak C (Maribaya)', 'lat' => -6.818000, 'lng' => 107.650000, 'status' => 'active', 'pay' => 'unpaid'], // Nunggak!
            ['name' => 'Anak D (Maribaya)', 'lat' => -6.819000, 'lng' => 107.651000, 'status' => 'active', 'pay' => 'paid'],

            // Cluster C: Parongpong (Barat)
            ['name' => 'Anak E (Parongpong)', 'lat' => -6.805000, 'lng' => 107.570000, 'status' => 'active', 'pay' => 'paid'],
            ['name' => 'Anak F (Parongpong)', 'lat' => -6.806000, 'lng' => 107.572000, 'status' => 'registered', 'pay' => 'unpaid'], // Baru daftar

            // Cluster D: Dekat Sekolah
            ['name' => 'Anak G (Alun-alun)', 'lat' => -6.816000, 'lng' => 107.617000, 'status' => 'active', 'pay' => 'paid'],
        ];

        foreach ($studentsData as $s) {
            // Hitung jarak garis lurus ke sekolah (Estimasi Harga)
            $distToSchool = $this->calculateHaversine($s['lat'], $s['lng'], self::SCHOOL_LAT, self::SCHOOL_LNG);
            $price = $distToSchool * 2000; // Misal Rp 2.000 per KM

            Student::create([
                'user_id' => $ortu->id, // Semua anak ini milik Pak Budi dulu biar gampang tes
                'name' => $s['name'],
                'school_level' => 'SD',
                'address_text' => 'Alamat Dummy Area Lembang',
                'latitude' => $s['lat'],
                'longitude' => $s['lng'],
                'distance_to_school_meters' => $distToSchool * 1000, // Convert KM to Meter
                'price_per_month' => $price,
                'status' => $s['status'],
                'payment_status' => $s['pay'],
            ]);
        }

        // 4. GENERATE DISTANCE MATRIX (CACHE)
        // Ini trik supaya Algoritma kamu nanti bisa jalan tanpa nembak Google Maps
        // Kita hitung jarak antar SEMUA siswa dan simpan di DB.

        $allStudents = Student::all();

        foreach ($allStudents as $origin) {
            foreach ($allStudents as $destination) {
                if ($origin->id != $destination->id) {

                    // Hitung jarak (KM)
                    $distKm = $this->calculateHaversine(
                        $origin->latitude,
                        $origin->longitude,
                        $destination->latitude,
                        $destination->longitude
                    );

                    // Kita asumsikan kecepatan rata-rata 30 KM/Jam di Lembang (macet dikit)
                    // Waktu (Jam) = Jarak / Kecepatan
                    $timeHours = $distKm / 30;
                    $timeSeconds = $timeHours * 3600;

                    DistanceMatrix::create([
                        'origin_id' => $origin->id,
                        'origin_type' => Student::class,
                        'destination_id' => $destination->id,
                        'destination_type' => Student::class,
                        'distance_meters' => $distKm * 1000,
                        'duration_seconds' => $timeSeconds,
                    ]);
                }
            }
        }
    }

    /**
     * Rumus Haversine (Hitung Jarak Garis Lurus antar 2 Koordinat Bumi)
     * Return: Kilometers
     */
    private function calculateHaversine($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371; // Radius bumi dalam KM

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
