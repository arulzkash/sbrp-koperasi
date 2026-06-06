<?php

namespace App\Services;

use App\Models\FleetTrip;
use App\Models\Student;

class RouteOptimizerService
{
    const SCHOOL_LAT = -6.826864390637824;
    const SCHOOL_LNG = 107.63886429303408;

    public function optimize()
    {
        // Clear previous route assignments before calculating a fresh routing result.
        Student::query()->update([
            'morning_fleet_id' => null,
            'morning_fleet_trip_id' => null,
            'morning_route_order' => null,
            'afternoon_fleet_id' => null,
            'afternoon_fleet_trip_id' => null,
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
        // Morning routing uses fleet trips as capacity buckets, not just fleets.
        $trips = FleetTrip::with('fleet')
            ->where('direction', 'morning')
            ->where('is_active', true)
            ->whereHas('fleet', fn ($query) => $query->where('is_active', true))
            ->orderBy('departure_time')
            ->orderBy('fleet_id')
            ->orderBy('trip_order')
            ->get();

        $students = Student::where('payment_status', 'paid')
            ->whereIn('service_type', ['full', 'pickup_only'])
            ->get();

        if ($trips->isEmpty() || $students->isEmpty()) return;

        // Assign students to the nearest active morning trip base while respecting trip capacity.
        $tripStudents = $this->clusterByNearestTripBase($students, $trips);

        foreach ($tripStudents as $tripId => $studentsForTrip) {
            if (empty($studentsForTrip)) continue;

            $trip = $trips->firstWhere('id', $tripId);
            $fleet = $trip->fleet;

            // Build the pickup sequence from driver base to students, ending at school.
            $route = $this->buildAndOptimizeMorningRoute($studentsForTrip, $fleet);

            foreach ($route as $order => $student) {
                unset($student->trip_distances);

                $student->update([
                    'morning_fleet_id' => $fleet->id,
                    'morning_fleet_trip_id' => $trip->id,
                    'morning_route_order' => $order + 1,
                ]);
            }
        }
    }

    private function clusterByNearestTripBase($students, $trips)
    {
        $tripStudents = [];
        $tripCapacities = [];

        // Each trip has its own copy of the fleet capacity, so a fleet can run multiple batches.
        foreach ($trips as $trip) {
            $tripStudents[$trip->id] = [];
            $tripCapacities[$trip->id] = $trip->fleet->capacity;
        }

        foreach ($students as $student) {
            $distances = [];

            // Score every student against every trip by the trip fleet's driver/base location.
            foreach ($trips as $trip) {
                $fleet = $trip->fleet;
                $distances[] = [
                    'trip_id' => $trip->id,
                    'fleet_id' => $fleet->id,
                    'trip_order' => $trip->trip_order,
                    'distance' => $this->calculateDistance(
                        $student->latitude,
                        $student->longitude,
                        $fleet->base_latitude,
                        $fleet->base_longitude,
                    ),
                ];
            }

            usort($distances, function ($a, $b) {
                $byDistance = $a['distance'] <=> $b['distance'];
                if ($byDistance !== 0) return $byDistance;

                $byFleet = $a['fleet_id'] <=> $b['fleet_id'];
                if ($byFleet !== 0) return $byFleet;

                return $a['trip_order'] <=> $b['trip_order'];
            });

            $student->trip_distances = $distances;
        }

        foreach ($students as $student) {
            foreach ($student->trip_distances as $tripDistance) {
                $tripId = $tripDistance['trip_id'];

                if (count($tripStudents[$tripId]) < $tripCapacities[$tripId]) {
                    $tripStudents[$tripId][] = $student;
                    break;
                }
            }
        }

        return $tripStudents;
    }

    private function buildAndOptimizeMorningRoute($studentsForTrip, $fleet)
    {
        // Start with a nearest-neighbor route from the fleet base.
        $unvisited = $studentsForTrip;
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

        // Improve local route order while keeping base as start and school as finish.
        return $this->twoOptMorning($route, $fleet->base_latitude, $fleet->base_longitude);
    }

    private function twoOptMorning($route, $baseLat, $baseLng)
    {
        $improved = true;

        while ($improved) {
            $improved = false;

            for ($i = 0; $i < count($route) - 1; $i++) {
                for ($j = $i + 1; $j < count($route); $j++) {
                    $newRoute = $route;

                    $segment = array_slice($newRoute, $i, $j - $i + 1);
                    $segment = array_reverse($segment);
                    array_splice($newRoute, $i, $j - $i + 1, $segment);

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
        $prevLat = $baseLat;
        $prevLng = $baseLng;

        foreach ($route as $student) {
            $distance += $this->calculateDistance(
                $prevLat,
                $prevLng,
                $student->latitude,
                $student->longitude,
            );
            $prevLat = $student->latitude;
            $prevLng = $student->longitude;
        }

        $distance += $this->calculateDistance(
            $prevLat,
            $prevLng,
            self::SCHOOL_LAT,
            self::SCHOOL_LNG,
        );

        return $distance;
    }

    /*
    |--------------------------------------------------------------------------
    | AFTERNOON ROUTE
    |--------------------------------------------------------------------------
    */

    private function optimizeAfternoonRoutes()
    {
        $studentsAll = Student::where('payment_status', 'paid')
            ->whereIn('service_type', ['full', 'dropoff_only'])
            ->get();

        if ($studentsAll->isEmpty()) return;

        $groupedBySession = $studentsAll->groupBy('session_out');

        foreach ($groupedBySession as $session => $students) {
            if (!$session) continue;

            // Afternoon students can only use trips that depart at their exact class dismissal time.
            $trips = FleetTrip::with('fleet')
                ->where('direction', 'afternoon')
                ->where('departure_time', $session)
                ->where('is_active', true)
                ->whereHas('fleet', fn ($query) => $query->where('is_active', true))
                ->orderBy('fleet_id')
                ->orderBy('trip_order')
                ->get();

            if ($trips->isEmpty()) continue;

            // Sweep clustering groups nearby drop-off points into the available trips for this time.
            $tripStudents = $this->clusterBySweepAndTripCapacity($students, $trips);

            foreach ($tripStudents as $tripId => $studentsForTrip) {
                if (empty($studentsForTrip)) continue;

                $trip = $trips->firstWhere('id', $tripId);
                $fleet = $trip->fleet;

                $route = $this->sortRouteByDistance($studentsForTrip, 'asc');
                $route = $this->twoOptImprove($route);

                foreach ($route as $order => $student) {
                    unset($student->sweep_angle);
                    unset($student->school_distance);

                    $student->update([
                        'afternoon_fleet_id' => $fleet->id,
                        'afternoon_fleet_trip_id' => $trip->id,
                        'afternoon_route_order' => $order + 1,
                    ]);
                }
            }
        }
    }

    private function clusterBySweepAndTripCapacity($students, $trips)
    {
        $studentsWithAngles = [];

        // Convert each student location into an angle around the school for sweep clustering.
        foreach ($students as $student) {
            $dy = $student->latitude - self::SCHOOL_LAT;
            $dx = $student->longitude - self::SCHOOL_LNG;
            $angle = atan2($dy, $dx) * 180 / M_PI;

            if ($angle < 0) {
                $angle += 360;
            }

            $student->sweep_angle = $angle;
            $studentsWithAngles[] = $student;
        }

        usort($studentsWithAngles, function ($a, $b) {
            return $a->sweep_angle <=> $b->sweep_angle;
        });

        $totalStudents = count($studentsWithAngles);
        $totalTrips = count($trips);
        $baseShare = floor($totalStudents / $totalTrips);
        $remainder = $totalStudents % $totalTrips;

        $tripStudents = [];
        $studentIndex = 0;

        // Distribute the angular sweep fairly across trips, capped by each trip's fleet capacity.
        foreach ($trips as $index => $trip) {
            $tripStudents[$trip->id] = [];

            $quota = $baseShare + ($index < $remainder ? 1 : 0);
            $quota = min($quota, $trip->fleet->capacity);

            for ($i = 0; $i < $quota && $studentIndex < $totalStudents; $i++) {
                $tripStudents[$trip->id][] = $studentsWithAngles[$studentIndex];
                $studentIndex++;
            }
        }

        // If fair-share distribution leaves students behind, fill any remaining trip capacity.
        while ($studentIndex < $totalStudents) {
            $assigned = false;

            foreach ($trips as $trip) {
                if (count($tripStudents[$trip->id]) < $trip->fleet->capacity) {
                    $tripStudents[$trip->id][] = $studentsWithAngles[$studentIndex];
                    $studentIndex++;
                    $assigned = true;
                    break;
                }
            }

            if (!$assigned) {
                break;
            }
        }

        return $tripStudents;
    }

    private function sortRouteByDistance($students, $direction = 'asc')
    {
        $studentsWithDistance = [];

        foreach ($students as $student) {
            $student->school_distance = $this->calculateDistance(
                self::SCHOOL_LAT,
                self::SCHOOL_LNG,
                $student->latitude,
                $student->longitude,
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
                $student->longitude,
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
