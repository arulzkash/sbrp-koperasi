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
    | MORNING ROUTE
    |--------------------------------------------------------------------------
    */

    private function optimizeMorningRoutes()
    {
        $fleets = Fleet::where('is_active', true)->get();

        $students = Student::where('payment_status', 'paid')
            ->whereIn('service_type', ['full', 'pickup_only'])
            ->get();

        if ($fleets->isEmpty() || $students->isEmpty()) return;

        $clusters = $this->clusterByKMeans(
            $students->values()->all(),
            $fleets->count()
        );

        // balancing
        $fleetStudents = $this->balanceClusterCapacity($clusters, $fleets);

        foreach ($fleetStudents as $fleetId => $studentsForFleet) {

            if (empty($studentsForFleet)) continue;

            $fleet = $fleets->firstWhere('id', $fleetId);

            $route = $this->nearestNeighborFromBase(
                $studentsForFleet,
                $fleet
            );

            $route = $this->twoOptImprove($route);

            foreach ($route as $order => $student) {

                $student->update([
                    'morning_fleet_id' => $fleet->id,
                    'morning_route_order' => $order + 1
                ]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | AFTERNOON ROUTE
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

            $clusters = $this->clusterByKMeans(
                $students->values()->all(),
                $fleets->count()
            );

            foreach ($clusters as $clusterIndex => $clusterStudents) {

                if (empty($clusterStudents)) continue;

                $fleet = $fleets[$clusterIndex % $fleets->count()];

                $clusterStudents = array_slice(
                    $clusterStudents,
                    0,
                    $fleet->capacity
                );

                $route = $this->nearestNeighborRoute($clusterStudents);

                $route = $this->twoOptImprove($route);

                foreach ($route as $order => $student) {

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
    | K-MEANS CLUSTERING
    |--------------------------------------------------------------------------
    */

    private function clusterByKMeans($students, $k)
    {
        $studentCount = count($students);

        if ($studentCount == 0) {
            return [];
        }

        // cluster tidak boleh lebih besar dari jumlah siswa
        $k = min($k, $studentCount);

        $centroids = [];

        shuffle($students);

        for ($i = 0; $i < $k; $i++) {
            $centroids[$i] = [
                'lat' => $students[$i]->latitude,
                'lng' => $students[$i]->longitude
            ];
        }

        for ($iteration = 0; $iteration < 10; $iteration++) {

            $clusters = array_fill(0, $k, []);

            foreach ($students as $student) {

                $bestCluster = 0;
                $minDistance = PHP_INT_MAX;

                foreach ($centroids as $index => $centroid) {

                    $distance = $this->calculateDistance(
                        $student->latitude,
                        $student->longitude,
                        $centroid['lat'],
                        $centroid['lng']
                    );

                    if ($distance < $minDistance) {

                        $minDistance = $distance;
                        $bestCluster = $index;
                    }
                }

                $clusters[$bestCluster][] = $student;
            }

            foreach ($clusters as $index => $cluster) {

                if (empty($cluster)) continue;

                $latSum = 0;
                $lngSum = 0;

                foreach ($cluster as $student) {

                    $latSum += $student->latitude;
                    $lngSum += $student->longitude;
                }

                $centroids[$index] = [
                    'lat' => $latSum / count($cluster),
                    'lng' => $lngSum / count($cluster)
                ];
            }
        }

        return $clusters;
    }

    private function balanceClusterCapacity($clusters, $fleets)
    {
        $fleetStudents = [];

        foreach ($fleets as $fleet) {
            $fleetStudents[$fleet->id] = [];
        }

        foreach ($clusters as $cluster) {

            foreach ($cluster as $student) {

                $bestFleet = null;
                $bestDistance = PHP_INT_MAX;

                foreach ($fleets as $fleet) {

                    if (count($fleetStudents[$fleet->id]) >= $fleet->capacity) {
                        continue;
                    }

                    $distance = $this->calculateDistance(
                        $student->latitude,
                        $student->longitude,
                        $fleet->base_latitude,
                        $fleet->base_longitude
                    );

                    if ($distance < $bestDistance) {
                        $bestDistance = $distance;
                        $bestFleet = $fleet;
                    }
                }

                if ($bestFleet) {
                    $fleetStudents[$bestFleet->id][] = $student;
                }
            }
        }

        return $fleetStudents;
    }

    /*
    |--------------------------------------------------------------------------
    | ROUTE CONSTRUCTION
    |--------------------------------------------------------------------------
    */

    private function nearestNeighborRoute($students)
    {
        $route = [];

        $currentLat = self::SCHOOL_LAT;
        $currentLng = self::SCHOOL_LNG;

        while (count($students) > 0) {

            $nearest = null;
            $nearestKey = null;
            $minDistance = PHP_INT_MAX;

            foreach ($students as $key => $student) {

                $distance = $this->calculateDistance(
                    $currentLat,
                    $currentLng,
                    $student->latitude,
                    $student->longitude
                );

                if ($distance < $minDistance) {

                    $minDistance = $distance;
                    $nearest = $student;
                    $nearestKey = $key;
                }
            }

            $route[] = $nearest;

            $currentLat = $nearest->latitude;
            $currentLng = $nearest->longitude;

            unset($students[$nearestKey]);
        }

        return $route;
    }

    private function nearestNeighborFromBase($students, $fleet)
    {
        $route = [];

        $currentLat = $fleet->base_latitude;
        $currentLng = $fleet->base_longitude;

        while (count($students) > 0) {

            $nearest = null;
            $nearestKey = null;
            $minDistance = PHP_INT_MAX;

            foreach ($students as $key => $student) {

                $distance = $this->calculateDistance(
                    $currentLat,
                    $currentLng,
                    $student->latitude,
                    $student->longitude
                );

                if ($distance < $minDistance) {

                    $minDistance = $distance;
                    $nearest = $student;
                    $nearestKey = $key;
                }
            }

            $route[] = $nearest;

            $currentLat = $nearest->latitude;
            $currentLng = $nearest->longitude;

            unset($students[$nearestKey]);
        }

        return $route;
    }

    /*
    |--------------------------------------------------------------------------
    | 2-OPT IMPROVEMENT
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
