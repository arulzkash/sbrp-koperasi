<?php

namespace App\Services;

use App\Models\Fleet;
use App\Models\Student;

class RouteOptimizerService
{
    const SCHOOL_LAT = -6.815348;
    const SCHOOL_LNG = 107.616659;

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

    /**
     * MORNING ROUTE
     */
    private function optimizeMorningRoutes()
    {
        $fleets = Fleet::where('is_active', true)->get();

        $students = Student::where('payment_status', 'paid')
            ->whereIn('service_type', ['full', 'pickup_only'])
            ->get();

        if ($fleets->isEmpty() || $students->isEmpty()) return;

        $fleetCapacities = [];
        foreach ($fleets as $fleet) {
            $fleetCapacities[$fleet->id] = $fleet->capacity;
        }

        foreach ($students as $student) {

            $bestFleetId = null;
            $minDistance = PHP_INT_MAX;

            foreach ($fleets as $fleet) {

                if ($fleetCapacities[$fleet->id] <= 0) continue;

                $distance = $this->calculateDistance(
                    $student->latitude,
                    $student->longitude,
                    $fleet->base_latitude,
                    $fleet->base_longitude
                );

                if ($distance < $minDistance) {
                    $minDistance = $distance;
                    $bestFleetId = $fleet->id;
                }
            }

            if ($bestFleetId) {

                $order = Student::where('morning_fleet_id', $bestFleetId)->count() + 1;

                $student->update([
                    'morning_fleet_id' => $bestFleetId,
                    'morning_route_order' => $order
                ]);

                $fleetCapacities[$bestFleetId]--;
            }
        }
    }

    /**
     * AFTERNOON ROUTE (Improved VRP-like)
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

            $clusters = $this->clusterByDirection($students->values()->all(), $fleets->count());

            foreach ($clusters as $clusterIndex => $clusterStudents) {

                if (empty($clusterStudents)) continue;

                $fleet = $fleets[$clusterIndex % $fleets->count()];

                if (count($clusterStudents) > $fleet->capacity) {
                    $clusterStudents = array_slice($clusterStudents, 0, $fleet->capacity);
                }

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

    /**
     * Directional clustering based on angle from school
     */
    private function clusterByDirection($students, $clusterCount)
    {
        $clusters = array_fill(0, $clusterCount, []);

        foreach ($students as $student) {

            $angle = atan2(
                $student->latitude - self::SCHOOL_LAT,
                $student->longitude - self::SCHOOL_LNG
            );

            $index = intval(($angle + M_PI) / (2 * M_PI) * $clusterCount);

            if ($index >= $clusterCount) $index = $clusterCount - 1;

            $clusters[$index][] = $student;
        }

        return $clusters;
    }

    /**
     * Nearest Neighbor route construction
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

    /**
     * 2-opt improvement
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

    /**
     * Haversine formula
     */
    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}