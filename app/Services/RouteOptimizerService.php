<?php

namespace App\Services;

use App\Models\Fleet;
use App\Models\Student;

class RouteOptimizerService
{
    const SCHOOL_LAT = -6.826864390637824;
    const SCHOOL_LNG = 107.63886429303408;

    public function optimize()
    {
        Student::query()->update([
            'morning_fleet_id' => null,
            'morning_route_order' => null,
            'afternoon_fleet_id' => null,
            'afternoon_route_order' => null,
        ]);

        Student::where('payment_status', 'paid')
            ->update(['status' => 'registered']);

        $this->optimizeMorningRoutes();
        $this->optimizeAfternoonRoutes();

        Student::whereNotNull('morning_fleet_id')
            ->orWhereNotNull('afternoon_fleet_id')
            ->update(['status' => 'active']);
    }

    /*
    |--------------------------------------------------------------------------
    | MORNING ROUTE (NEW: DRIVER-CENTRIC BASE)
    |--------------------------------------------------------------------------
    */

    private function optimizeMorningRoutes()
    {
        $fleets = Fleet::where('is_active', true)->get();

        $students = Student::where('payment_status', 'paid')
            ->whereIn('service_type', ['full', 'pickup_only'])
            ->get();

        if ($fleets->isEmpty() || $students->isEmpty()) return;

        // 1. CLUSTER: Berdasarkan jarak terdekat ke rumah supir (Base)
        $fleetStudents = $this->clusterByNearestBase($students, $fleets);

        foreach ($fleetStudents as $fleetId => $studentsForFleet) {

            if (empty($studentsForFleet)) continue;

            $fleet = $fleets->firstWhere('id', $fleetId);

            // 2. BUILD ROUTE & OPTIMIZE: Base Supir -> Murid Terdekat -> ... -> Sekolah
            $route = $this->buildAndOptimizeMorningRoute($studentsForFleet, $fleet);

            foreach ($route as $order => $student) {
                // Bersihkan properti dinamis
                unset($student->fleet_distances);

                $student->update([
                    'morning_fleet_id' => $fleet->id,
                    'morning_route_order' => $order + 1
                ]);
            }
        }
    }

    private function clusterByNearestBase($students, $fleets)
    {
        $fleetStudents = [];
        $fleetCapacities = [];
        
        foreach ($fleets as $fleet) {
            $fleetStudents[$fleet->id] = [];
            $fleetCapacities[$fleet->id] = $fleet->capacity;
        }

        // Hitung jarak setiap murid ke SEMUA base armada
        foreach ($students as $student) {
            $distances = [];
            foreach ($fleets as $fleet) {
                $dist = $this->calculateDistance(
                    $student->latitude, $student->longitude,
                    $fleet->base_latitude, $fleet->base_longitude
                );
                $distances[$fleet->id] = $dist;
            }
            // Urutkan fleet dari yang terdekat ke murid tersebut
            asort($distances);
            $student->fleet_distances = $distances;
        }

        // Assign murid ke armada terdekat yang masih punya kapasitas
        foreach ($students as $student) {
            foreach ($student->fleet_distances as $fleetId => $dist) {
                if (count($fleetStudents[$fleetId]) < $fleetCapacities[$fleetId]) {
                    $fleetStudents[$fleetId][] = $student;
                    break; // Pindah ke murid selanjutnya
                }
            }
        }

        return $fleetStudents;
    }

    private function buildAndOptimizeMorningRoute($studentsForFleet, $fleet)
    {
        // A. NEAREST NEIGHBOR (Membangun Rute Awal dari Base)
        $unvisited = $studentsForFleet;
        $route = [];
        $currentLat = $fleet->base_latitude;
        $currentLng = $fleet->base_longitude;

        while (!empty($unvisited)) {
            $nearestIndex = -1;
            $minDist = INF;

            foreach ($unvisited as $index => $student) {
                $dist = $this->calculateDistance($currentLat, $currentLng, $student->latitude, $student->longitude);
                if ($dist < $minDist) {
                    $minDist = $dist;
                    $nearestIndex = $index;
                }
            }

            $nearestStudent = $unvisited[$nearestIndex];
            $route[] = $nearestStudent;
            
            $currentLat = $nearestStudent->latitude;
            $currentLng = $nearestStudent->longitude;

            unset($unvisited[$nearestIndex]);
        }

        // B. 2-OPT OPTIMIZATION (Mengunci Titik Start & Finish)
        $route = $this->twoOptMorning($route, $fleet->base_latitude, $fleet->base_longitude);

        return $route;
    }

    private function twoOptMorning($route, $baseLat, $baseLng)
    {
        $improved = true;

        while ($improved) {
            $improved = false;

            for ($i = 0; $i < count($route) - 1; $i++) {
                for ($j = $i + 1; $j < count($route); $j++) {
                    
                    $newRoute = $route;
                    
                    // Balik urutan segmen di antara i dan j
                    $segment = array_slice($newRoute, $i, $j - $i + 1);
                    $segment = array_reverse($segment);
                    array_splice($newRoute, $i, $j - $i + 1, $segment);

                    // Cek jika jarak rute baru lebih pendek
                    if ($this->routeDistanceMorning($newRoute, $baseLat, $baseLng) < $this->routeDistanceMorning($route, $baseLat, $baseLng)) {
                        $route = $newRoute;
                        $improved = true;
                    }
                }
            }
        }

        return $route;
    }

    private function routeDistanceMorning($route, $baseLat, $baseLng)
    {
        $distance = 0;
        
        // Mulai dari Base Armada
        $prevLat = $baseLat;
        $prevLng = $baseLng;

        // Keliling ke murid-murid
        foreach ($route as $student) {
            $distance += $this->calculateDistance(
                $prevLat, $prevLng,
                $student->latitude, $student->longitude
            );
            $prevLat = $student->latitude;
            $prevLng = $student->longitude;
        }

        // Titik akhir harus Sekolah
        $distance += $this->calculateDistance(
            $prevLat, $prevLng,
            self::SCHOOL_LAT, self::SCHOOL_LNG
        );

        return $distance;
    }


    /*
    |--------------------------------------------------------------------------
    | AFTERNOON ROUTE (LAMA - TIDAK DIUBAH)
    |--------------------------------------------------------------------------
    */

    private function optimizeAfternoonRoutes()
    {
        $fleets = Fleet::where('is_active', true)->get();

        $studentsAll = Student::where('payment_status', 'paid')
            ->whereIn('service_type', ['full', 'dropoff_only'])
            ->get();

        if ($fleets->isEmpty() || $studentsAll->isEmpty()) return;

        $groupedBySession = $studentsAll->groupBy('session_out');

        foreach ($groupedBySession as $session => $students) {

            // SWEEP ALGORITHM (per session)
            $fleetStudents = $this->clusterBySweepAndCapacity($students, $fleets);

            foreach ($fleetStudents as $fleetId => $studentsForFleet) {

                if (empty($studentsForFleet)) continue;

                $fleet = $fleets->firstWhere('id', $fleetId);

                // Afternoon: start from school, go to closest first
                $route = $this->sortRouteByDistance($studentsForFleet, 'asc');

                $route = $this->twoOptImprove($route);

                foreach ($route as $order => $student) {
                    unset($student->sweep_angle);
                    unset($student->school_distance);

                    $student->update([
                        'afternoon_fleet_id' => $fleet->id,
                        'afternoon_route_order' => $order + 1
                    ]);
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SWEEP CLUSTERING (ANGULAR SORT - LAMA)
    |--------------------------------------------------------------------------
    */

    private function clusterBySweepAndCapacity($students, $fleets)
    {
        // 1. Calculate angle from school for each student
        $studentsWithAngles = [];
        foreach ($students as $student) {
            $dy = $student->latitude - self::SCHOOL_LAT;
            $dx = $student->longitude - self::SCHOOL_LNG;
            // atan2 returns -PI to PI. Convert to 0 to 360 degrees.
            $angle = atan2($dy, $dx) * 180 / M_PI;
            if ($angle < 0) {
                $angle += 360;
            }
            $student->sweep_angle = $angle;
            $studentsWithAngles[] = $student;
        }

        // 2. Sort students by angle (Sweep)
        usort($studentsWithAngles, function ($a, $b) {
            return $a->sweep_angle <=> $b->sweep_angle;
        });

        // 3. Fairly distribute capacity
        // To prevent one fleet having 12 and another 1, we determine a "fair share".
        $totalStudents = count($studentsWithAngles);
        $totalFleets = count($fleets);
        $baseShare = floor($totalStudents / $totalFleets);
        $remainder = $totalStudents % $totalFleets;

        $fleetStudents = [];
        $studentIndex = 0;

        foreach ($fleets as $index => $fleet) {
            $fleetStudents[$fleet->id] = [];
            
            // This fleet's quota for this pass
            $quota = $baseShare + ($index < $remainder ? 1 : 0);
            
            // Respect max capacity
            $quota = min($quota, $fleet->capacity);

            // Assign students in the current sweep "slice"
            for ($i = 0; $i < $quota && $studentIndex < $totalStudents; $i++) {
                $fleetStudents[$fleet->id][] = $studentsWithAngles[$studentIndex];
                $studentIndex++;
            }
        }

        // If there are leftover students (because some fleets maxed out capacity but others were empty initially)
        // just greedily assign them to any fleet with remaining capacity.
        while ($studentIndex < $totalStudents) {
            $assigned = false;
            foreach ($fleets as $fleet) {
                if (count($fleetStudents[$fleet->id]) < $fleet->capacity) {
                    $fleetStudents[$fleet->id][] = $studentsWithAngles[$studentIndex];
                    $studentIndex++;
                    $assigned = true;
                    break;
                }
            }
            // If all fleets are 100% full, the remaining students simply cannot be routed.
            if (!$assigned) {
                break;
            }
        }

        return $fleetStudents;
    }

    /*
    |--------------------------------------------------------------------------
    | ROUTE CONSTRUCTION (LAMA)
    |--------------------------------------------------------------------------
    */

    private function sortRouteByDistance($students, $direction = 'asc')
    {
        $studentsWithDistance = [];
        foreach ($students as $student) {
            $student->school_distance = $this->calculateDistance(
                self::SCHOOL_LAT,
                self::SCHOOL_LNG,
                $student->latitude,
                $student->longitude
            );
            $studentsWithDistance[] = $student;
        }

        usort($studentsWithDistance, function ($a, $b) use ($direction) {
            if ($direction === 'asc') {
                return $a->school_distance <=> $b->school_distance;
            }
            return $b->school_distance <=> $a->school_distance;
        });

        return $studentsWithDistance;
    }

    /*
    |--------------------------------------------------------------------------
    | 2-OPT IMPROVEMENT (LAMA - UNTUK SORE)
    |--------------------------------------------------------------------------
    */

    private function twoOptImprove($route)
    {
        $improved = true;

        while ($improved) {

            $improved = false;

            for ($i = 1; $i < count($route) - 2; $i++) {

                for ($j = $i + 1; $j < count($route); $j++) {

                    $newRoute = $route;

                    $segment = array_slice($newRoute, $i, $j - $i);
                    $segment = array_reverse($segment);

                    array_splice($newRoute, $i, $j - $i, $segment);

                    if ($this->routeDistance($newRoute) < $this->routeDistance($route)) {

                        $route = $newRoute;
                        $improved = true;
                    }
                }
            }
        }

        return $route;
    }

    private function routeDistance($route)
    {
        $distance = 0;

        $prevLat = self::SCHOOL_LAT;
        $prevLng = self::SCHOOL_LNG;

        foreach ($route as $student) {

            $distance += $this->calculateDistance(
                $prevLat,
                $prevLng,
                $student->latitude,
                $student->longitude
            );

            $prevLat = $student->latitude;
            $prevLng = $student->longitude;
        }

        return $distance;
    }

    /*
    |--------------------------------------------------------------------------
    | HAVERSINE DISTANCE
    |--------------------------------------------------------------------------
    */

    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a =
            sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}