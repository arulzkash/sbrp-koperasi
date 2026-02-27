<?php

namespace App\Services;

use App\Models\Fleet;
use App\Models\Student;

class RouteOptimizerService
{
    // Titik Pusat (Sekolah)
    const SCHOOL_LAT = -6.815348;
    const SCHOOL_LNG = 107.616659;

    /**
     * Fungsi Utama yang dipanggil oleh tombol "Generate Route" di Admin
     */
    public function optimize()
    {
        // 1. RESET SEMUA RUTE LAMA
        // Kita kosongkan dulu mobil pagi & sore sebelum dihitung ulang
        Student::query()->update([
            'morning_fleet_id' => null,
            'morning_route_order' => null,
            'afternoon_fleet_id' => null,
            'afternoon_route_order' => null,
        ]);

        // Kembalikan status anak yang Lunas menjadi 'registered' dulu untuk dievaluasi ulang
        Student::where('payment_status', 'paid')->update(['status' => 'registered']);

        // 2. JALANKAN ALGORITMA PAGI (BERANGKAT)
        $this->optimizeMorningRoutes();

        // 3. JALANKAN ALGORITMA SIANG/SORE (PULANG) DENGAN TIME WINDOWS
        $this->optimizeAfternoonRoutes();

        // 4. UPDATE STATUS
        // Jika anak masuk ke minimal salah satu armada (pagi/sore), ubah status jadi Aktif
        Student::whereNotNull('morning_fleet_id')
            ->orWhereNotNull('afternoon_fleet_id')
            ->update(['status' => 'active']);
    }

    /**
     * ALGORITMA PAGI: Semua anak berangkat serentak
     */
    private function optimizeMorningRoutes()
    {
        $fleets = Fleet::where('is_active', true)->get();
        // Ambil anak yang Lunas & minta dijemput pagi (full atau pickup_only)
        $students = Student::where('payment_status', 'paid')
            ->whereIn('service_type', ['full', 'pickup_only'])
            ->get();

        if ($fleets->isEmpty() || $students->isEmpty()) return;

        // Bikin tracking kapasitas mobil untuk Pagi
        $fleetCapacities = [];
        foreach ($fleets as $fleet) {
            $fleetCapacities[$fleet->id] = $fleet->capacity;
        }

        // Loop setiap anak, cari mobil terdekat dari rumahnya
        foreach ($students as $student) {
            $bestFleetId = null;
            $minDistance = PHP_INT_MAX;

            foreach ($fleets as $fleet) {
                // Cek apakah mobil ini masih ada kursi kosong
                if ($fleetCapacities[$fleet->id] > 0) {
                    // Hitung jarak dari rumah anak ke Pool Mobil (Base)
                    $distance = $this->calculateDistance($student->latitude, $student->longitude, $fleet->base_latitude, $fleet->base_longitude);
                    
                    if ($distance < $minDistance) {
                        $minDistance = $distance;
                        $bestFleetId = $fleet->id;
                    }
                }
            }

            // Jika ketemu mobil yang cocok dan masih muat
            if ($bestFleetId) {
                // Urutan jemput: Hitung ada berapa anak di mobil ini untuk pagi hari
                $order = Student::where('morning_fleet_id', $bestFleetId)->count() + 1;

                $student->update([
                    'morning_fleet_id' => $bestFleetId,
                    'morning_route_order' => $order
                ]);

                // Kurangi sisa kursi mobil tersebut
                $fleetCapacities[$bestFleetId]--;
            }
        }
    }

    /**
     * ALGORITMA SORE: Berbasis Time Windows (Jam Pulang)
     */
    private function optimizeAfternoonRoutes()
    {
        $fleets = Fleet::where('is_active', true)->get();
        
        // Ambil anak yang Lunas & minta diantar pulang (full atau dropoff_only)
        $studentsAll = Student::where('payment_status', 'paid')
            ->whereIn('service_type', ['full', 'dropoff_only'])
            ->get();

        if ($fleets->isEmpty() || $studentsAll->isEmpty()) return;

        // KELOMPOKKAN BERDASARKAN JAM PULANG (Time Windows)
        // Misal: Group 13:00, Group 14:30, dst.
        $groupedBySession = $studentsAll->groupBy('session_out');

        foreach ($groupedBySession as $timeSession => $studentsInSession) {
            
            // MAGIC TRICK: Setiap ganti jam sesi, KAPASITAS MOBIL DI-RESET!
            // Karena mobil yang dipakai jam 13:00 bisa kembali dipakai jam 15:30.
            $fleetCapacities = [];
            foreach ($fleets as $fleet) {
                $fleetCapacities[$fleet->id] = $fleet->capacity;
            }

            foreach ($studentsInSession as $student) {
                $bestFleetId = null;
                $minDistance = PHP_INT_MAX;

                foreach ($fleets as $fleet) {
                    if ($fleetCapacities[$fleet->id] > 0) {
                        // Karena ini pulangan, kita hitung jarak kedekatan antar rumah anak ke sekolah, 
                        // agar anak yang searah masuk mobil yang sama.
                        $distance = $this->calculateDistance($student->latitude, $student->longitude, self::SCHOOL_LAT, self::SCHOOL_LNG);
                        
                        if ($distance < $minDistance) {
                            $minDistance = $distance;
                            $bestFleetId = $fleet->id;
                        }
                    }
                }

                if ($bestFleetId) {
                    // Hitung urutan di dalam armada untuk sesi ini
                    $order = Student::where('afternoon_fleet_id', $bestFleetId)
                                    ->where('session_out', $timeSession)
                                    ->count() + 1;

                    $student->update([
                        'afternoon_fleet_id' => $bestFleetId,
                        'afternoon_route_order' => $order
                    ]);

                    $fleetCapacities[$bestFleetId]--;
                }
            }
        }
    }

    /**
     * Rumus Haversine (Jarak GPS)
     */
    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371; // KM
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}