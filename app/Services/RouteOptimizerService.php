<?php

namespace App\Services;

use App\Models\Student;
use App\Models\Fleet;
use App\Models\DistanceMatrix;

class RouteOptimizerService
{
    /**
     * Menjalankan algoritma pengelompokan rute otomatis.
     */
    public function optimize($includeUnpaid = false)
    {
        // 0. RESET DATA LAMA
        Student::whereNotNull('fleet_id')->update([
            'fleet_id' => null,
            'route_order' => null
        ]);

        // 1. AMBIL DATA
        $query = Student::whereIn('status', ['registered', 'active']);
        if (!$includeUnpaid) {
            $query->where('payment_status', 'paid');
        }
        $students = $query->get();
        $fleets = Fleet::where('is_active', true)->get();

        // 2. FASE 1: CLUSTER-FIRST (Alokasi ke Armada)
        $allocations = [];
        foreach ($fleets as $f) {
            $allocations[$f->id] = [];
        }

        foreach ($students as $student) {
            $bestFleetId = null;
            $minDistance = PHP_INT_MAX;

            foreach ($fleets as $fleet) {
                // Pastikan mobil tidak kepenuhan
                if (count($allocations[$fleet->id]) < $fleet->capacity) {
                    $distance = $this->calculateDistance($fleet->base_latitude, $fleet->base_longitude, $student->latitude, $student->longitude);
                    if ($distance < $minDistance) {
                        $minDistance = $distance;
                        $bestFleetId = $fleet->id;
                    }
                }
            }

            if ($bestFleetId) {
                // Masukkan siswa ke array keranjang armada tersebut
                $allocations[$bestFleetId][] = $student;
            }
        }

        // 3. FASE 2: ROUTE-SECOND (Urutan Penjemputan / Nearest Neighbor)
        foreach ($fleets as $fleet) {
            // Ubah array jadi Laravel Collection biar gampang di-filter
            $unvisitedStudents = collect($allocations[$fleet->id]);

            if ($unvisitedStudents->isEmpty()) continue;

            // Titik awal supir = Garasi/Pool
            $currentLat = $fleet->base_latitude;
            $currentLng = $fleet->base_longitude;
            $order = 1;

            // Selama masih ada anak yang belum dijemput di mobil ini
            while ($unvisitedStudents->count() > 0) {
                $closestStudent = null;
                $closestKey = null;
                $minDist = PHP_INT_MAX;

                // Cari tetangga terdekat dari titik supir saat ini
                foreach ($unvisitedStudents as $key => $student) {
                    $dist = $this->calculateDistance($currentLat, $currentLng, $student->latitude, $student->longitude);
                    if ($dist < $minDist) {
                        $minDist = $dist;
                        $closestStudent = $student;
                        $closestKey = $key;
                    }
                }

                // 4. SIMPAN URUTAN KE DATABASE
                $closestStudent->update([
                    'fleet_id' => $fleet->id,
                    'route_order' => $order
                ]);

                // Supir pindah posisi ke rumah anak yang baru dijemput
                $currentLat = $closestStudent->latitude;
                $currentLng = $closestStudent->longitude;

                // Coret anak dari daftar tunggu (agar tidak dijemput 2x)
                $unvisitedStudents->forget($closestKey);
                $order++;
            }
        }

        return true;
    }

    /**
     * Fungsi pembantu hitung jarak (Haversine) - Gratisan [cite: 502, 552]
     */
    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}
