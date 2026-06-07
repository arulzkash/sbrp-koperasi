<?php

namespace App\Services;

use App\Models\FleetTrip;
use App\Models\Student;
use Illuminate\Support\Facades\Log;

class RouteOptimizerService
{
    const SCHOOL_LAT = -6.826864390637824;

    const SCHOOL_LNG = 107.63886429303408;

    const MORNING_IMPROVEMENT_EPSILON = 0.000001;

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
        $morningStart = microtime(true);

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

        Log::info('[RouteOptimizer] Morning optimizer started', [
            'total_trips' => $trips->count(),
            'total_students_loaded' => $students->count(),
        ]);

        if ($trips->isEmpty() || $students->isEmpty()) {
            $this->logOptimizerTime('Morning optimizer finished', $morningStart, [
                'total_assigned_students' => 0,
            ]);

            return;
        }

        $clusterStart = microtime(true);
        $tripStudents = $this->clusterMorningByInsertionCost($students, $trips);
        $assignedStudentsCount = array_sum($this->summarizeTripLoads($tripStudents));
        $this->logOptimizerTime('Morning clustering finished', $clusterStart, [
            'assigned_students_count' => $assignedStudentsCount,
            'unassigned_or_invalid_count' => max($students->count() - $assignedStudentsCount, 0),
            'trip_loads' => $this->summarizeTripLoads($tripStudents),
        ]);

        $tripStudents = $this->improveMorningAssignmentByMove($tripStudents, $trips);
        $tripStudents = $this->rebalanceMorningUnderfilledTrips($tripStudents, $trips);

        // $tripStudents = $this->improveMorningAssignmentBySwap($tripStudents, $trips);
        $this->logOptimizerTime('Morning swap improvement finished', microtime(true), [
            'skipped' => true,
            'trip_loads' => $this->summarizeTripLoads($tripStudents),
        ]);

        foreach ($tripStudents as $tripId => $studentsForTrip) {
            if (empty($studentsForTrip)) {
                continue;
            }

            $trip = $trips->firstWhere('id', $tripId);
            $fleet = $trip->fleet;

            // Build the pickup sequence from driver base to students, ending at school.
            $orderingStart = microtime(true);
            $route = $this->buildAndOptimizeMorningRoute($studentsForTrip, $fleet);
            $this->logOptimizerTime('Morning final route ordering finished', $orderingStart, [
                'trip_id' => $trip->id,
                'fleet_id' => $fleet->id,
                'student_count' => count($route),
            ]);

            $savingStart = microtime(true);
            foreach ($route as $order => $student) {
                unset($student->trip_distances);

                $student->update([
                    'morning_fleet_id' => $fleet->id,
                    'morning_fleet_trip_id' => $trip->id,
                    'morning_route_order' => $order + 1,
                ]);
            }
            $this->logOptimizerTime('Morning trip assignment saving finished', $savingStart, [
                'trip_id' => $trip->id,
                'fleet_id' => $fleet->id,
                'student_count' => count($route),
            ]);
        }

        $this->logOptimizerTime('Morning optimizer finished', $morningStart, [
            'total_assigned_students' => array_sum($this->summarizeTripLoads($tripStudents)),
        ]);
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
                if ($byDistance !== 0) {
                    return $byDistance;
                }

                $byFleet = $a['fleet_id'] <=> $b['fleet_id'];
                if ($byFleet !== 0) {
                    return $byFleet;
                }

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

    private function clusterMorningByInsertionCost($students, $trips)
    {
        $tripStudents = [];
        $tripCapacities = [];
        $validStudents = [];

        foreach ($trips as $trip) {
            $tripStudents[$trip->id] = [];
            $tripCapacities[$trip->id] = $trip->fleet->capacity;
        }

        foreach ($students as $student) {
            if (! $this->hasValidCoordinates($student)) {
                continue;
            }

            $student->school_distance = $this->calculateDistance(
                self::SCHOOL_LAT,
                self::SCHOOL_LNG,
                $student->latitude,
                $student->longitude,
            );
            $validStudents[] = $student;
        }

        usort($validStudents, function ($a, $b) {
            return $b->school_distance <=> $a->school_distance;
        });

        foreach ($validStudents as $student) {
            $bestTripId = null;
            $bestScore = INF;

            foreach ($trips as $trip) {
                $tripId = $trip->id;

                if (count($tripStudents[$tripId]) >= $tripCapacities[$tripId]) {
                    continue;
                }

                $currentStudents = $tripStudents[$tripId];
                // Insertion cost scores the route impact, not just distance to the fleet base.
                $currentDistance = $this->estimateMorningRouteDistance($currentStudents, $trip->fleet);
                $newDistance = $this->estimateMorningRouteDistance([...$currentStudents, $student], $trip->fleet);
                $insertionCost = $newDistance - $currentDistance;

                // Capacity balance is only a soft penalty; it nudges distribution without forcing equal loads.
                $loadRatio = $tripCapacities[$tripId] > 0
                    ? count($currentStudents) / $tripCapacities[$tripId]
                    : 1;
                $capacityPenalty = $loadRatio * 0.3;

                // Centroid penalty avoids pulling a far outlier into an already coherent cluster.
                $outlierPenalty = $this->calculateDistanceToTripCentroid($student, $currentStudents) * 0.15;
                $score = $insertionCost + $capacityPenalty + $outlierPenalty;

                if ($score < $bestScore) {
                    $bestScore = $score;
                    $bestTripId = $tripId;
                }
            }

            if ($bestTripId !== null) {
                $tripStudents[$bestTripId][] = $student;
            }
        }

        foreach ($validStudents as $student) {
            unset($student->school_distance);
        }

        return $tripStudents;
    }

    private function improveMorningAssignmentByMove(array $tripStudents, $trips): array
    {
        $start = microtime(true);
        $maxIterations = 5;
        $iteration = 0;
        $improved = true;
        $movesApplied = 0;

        Log::info('[RouteOptimizer] Morning move improvement started', [
            'trip_loads' => $this->summarizeTripLoads($tripStudents),
        ]);

        while ($improved && $iteration < $maxIterations) {
            $improved = false;
            $iteration++;

            foreach ($trips as $fromTrip) {
                $fromTripId = $fromTrip->id;

                foreach ($tripStudents[$fromTripId] as $studentIndex => $student) {
                    foreach ($trips as $toTrip) {
                        $toTripId = $toTrip->id;

                        if ($fromTripId === $toTripId || count($tripStudents[$toTripId]) >= $toTrip->fleet->capacity) {
                            continue;
                        }

                        $fromStudentsAfterMove = $tripStudents[$fromTripId];
                        array_splice($fromStudentsAfterMove, $studentIndex, 1);
                        $toStudentsAfterMove = [...$tripStudents[$toTripId], $student];

                        $before = $this->estimateMorningRouteDistance($tripStudents[$fromTripId], $fromTrip->fleet)
                            + $this->estimateMorningRouteDistance($tripStudents[$toTripId], $toTrip->fleet);
                        $after = $this->estimateMorningRouteDistance($fromStudentsAfterMove, $fromTrip->fleet)
                            + $this->estimateMorningRouteDistance($toStudentsAfterMove, $toTrip->fleet);

                        if ($after + self::MORNING_IMPROVEMENT_EPSILON < $before) {
                            // Local moves catch students that fit better after the initial greedy assignment.
                            $tripStudents[$fromTripId] = $fromStudentsAfterMove;
                            $tripStudents[$toTripId] = $toStudentsAfterMove;
                            $improved = true;
                            $movesApplied++;

                            continue 4;
                        }
                    }
                }
            }
        }

        $this->logOptimizerTime('Morning move improvement finished', $start, [
            'iterations' => $iteration,
            'moves_applied' => $movesApplied,
            'trip_loads' => $this->summarizeTripLoads($tripStudents),
        ]);

        return $tripStudents;
    }

    private function rebalanceMorningUnderfilledTrips(array $tripStudents, $trips): array
    {
        $start = microtime(true);
        $underfilledRatio = 0.65;
        $overfilledRatio = 0.90;
        $maxExtraDistanceKm = 1.5;
        $maxIterations = 20;
        $iteration = 0;
        $movesApplied = 0;

        Log::info('[RouteOptimizer] Morning rebalance started', [
            'trip_loads' => $this->summarizeTripLoads($tripStudents),
        ]);

        while ($iteration < $maxIterations) {
            $iteration++;
            $bestMove = null;

            foreach ($trips as $sourceTrip) {
                $sourceTripId = $sourceTrip->id;
                $sourceStudents = $tripStudents[$sourceTripId];

                if ($this->tripLoadRatio($sourceStudents, $sourceTrip->fleet->capacity) <= $overfilledRatio) {
                    continue;
                }

                foreach ($trips as $destinationTrip) {
                    $destinationTripId = $destinationTrip->id;
                    $destinationStudents = $tripStudents[$destinationTripId];

                    if ($sourceTripId === $destinationTripId
                        || $this->tripLoadRatio($destinationStudents, $destinationTrip->fleet->capacity) >= $underfilledRatio
                        || count($destinationStudents) >= $destinationTrip->fleet->capacity
                    ) {
                        continue;
                    }

                    foreach ($sourceStudents as $studentIndex => $student) {
                        $sourceStudentsAfterMove = $sourceStudents;
                        array_splice($sourceStudentsAfterMove, $studentIndex, 1);

                        if ($this->tripLoadRatio($sourceStudentsAfterMove, $sourceTrip->fleet->capacity) < $underfilledRatio) {
                            continue;
                        }

                        $destinationStudentsAfterMove = [...$destinationStudents, $student];
                        $before = $this->estimateMorningRouteDistance($sourceStudents, $sourceTrip->fleet)
                            + $this->estimateMorningRouteDistance($destinationStudents, $destinationTrip->fleet);
                        $after = $this->estimateMorningRouteDistance($sourceStudentsAfterMove, $sourceTrip->fleet)
                            + $this->estimateMorningRouteDistance($destinationStudentsAfterMove, $destinationTrip->fleet);
                        $extraDistance = $after - $before;

                        if ($extraDistance > $maxExtraDistanceKm + self::MORNING_IMPROVEMENT_EPSILON) {
                            continue;
                        }

                        if ($bestMove === null || $extraDistance < $bestMove['extra_distance']) {
                            $bestMove = [
                                'source_trip_id' => $sourceTripId,
                                'destination_trip_id' => $destinationTripId,
                                'source_students' => $sourceStudentsAfterMove,
                                'destination_students' => $destinationStudentsAfterMove,
                                'extra_distance' => $extraDistance,
                            ];
                        }
                    }
                }
            }

            if ($bestMove === null) {
                break;
            }

            // This is a soft business fairness step: accept only small detours, never force equal loads.
            $tripStudents[$bestMove['source_trip_id']] = $bestMove['source_students'];
            $tripStudents[$bestMove['destination_trip_id']] = $bestMove['destination_students'];
            $movesApplied++;
        }

        $this->logOptimizerTime('Morning rebalance finished', $start, [
            'iterations' => $iteration,
            'moves_applied' => $movesApplied,
            'trip_loads' => $this->summarizeTripLoads($tripStudents),
        ]);

        return $tripStudents;
    }

    private function improveMorningAssignmentBySwap(array $tripStudents, $trips): array
    {
        $start = microtime(true);
        $maxIterations = 15;
        $iteration = 0;
        $improved = true;
        $swapsApplied = 0;

        Log::info('[RouteOptimizer] Morning swap improvement started', [
            'trip_loads' => $this->summarizeTripLoads($tripStudents),
        ]);

        while ($improved && $iteration < $maxIterations) {
            $improved = false;
            $iteration++;

            foreach ($trips as $fromIndex => $firstTrip) {
                $firstTripId = $firstTrip->id;

                for ($toIndex = $fromIndex + 1; $toIndex < count($trips); $toIndex++) {
                    $secondTrip = $trips[$toIndex];
                    $secondTripId = $secondTrip->id;

                    foreach ($tripStudents[$firstTripId] as $firstStudentIndex => $firstStudent) {
                        foreach ($tripStudents[$secondTripId] as $secondStudentIndex => $secondStudent) {
                            $firstStudentsAfterSwap = $tripStudents[$firstTripId];
                            $secondStudentsAfterSwap = $tripStudents[$secondTripId];

                            $firstStudentsAfterSwap[$firstStudentIndex] = $secondStudent;
                            $secondStudentsAfterSwap[$secondStudentIndex] = $firstStudent;

                            $before = $this->estimateMorningRouteDistance($tripStudents[$firstTripId], $firstTrip->fleet)
                                + $this->estimateMorningRouteDistance($tripStudents[$secondTripId], $secondTrip->fleet);
                            $after = $this->estimateMorningRouteDistance($firstStudentsAfterSwap, $firstTrip->fleet)
                                + $this->estimateMorningRouteDistance($secondStudentsAfterSwap, $secondTrip->fleet);

                            if ($after + self::MORNING_IMPROVEMENT_EPSILON < $before) {
                                $tripStudents[$firstTripId] = array_values($firstStudentsAfterSwap);
                                $tripStudents[$secondTripId] = array_values($secondStudentsAfterSwap);
                                $improved = true;
                                $swapsApplied++;

                                continue 5;
                            }
                        }
                    }
                }
            }
        }

        $this->logOptimizerTime('Morning swap improvement finished', $start, [
            'iterations' => $iteration,
            'swaps_applied' => $swapsApplied,
            'trip_loads' => $this->summarizeTripLoads($tripStudents),
        ]);

        return $tripStudents;
    }

    private function logOptimizerTime(string $label, float $start, array $context = []): void
    {
        Log::info('[RouteOptimizer] '.$label, array_merge($context, [
            'seconds' => round(microtime(true) - $start, 4),
        ]));
    }

    private function summarizeTripLoads(array $tripStudents): array
    {
        $summary = [];

        foreach ($tripStudents as $tripId => $students) {
            $summary[$tripId] = count($students);
        }

        return $summary;
    }

    private function tripLoadRatio(array $students, int $capacity): float
    {
        if ($capacity <= 0) {
            return 1;
        }

        return count($students) / $capacity;
    }

    private function estimateMorningRouteDistance(array $students, $fleet): float
    {
        $route = $this->buildNearestNeighborMorningRoute($students, $fleet);

        return $this->routeDistanceMorning($route, $fleet->base_latitude, $fleet->base_longitude);
    }

    private function buildNearestNeighborMorningRoute(array $students, $fleet): array
    {
        $unvisited = array_values($students);
        $route = [];
        $currentLat = $fleet->base_latitude;
        $currentLng = $fleet->base_longitude;

        while (! empty($unvisited)) {
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

            array_splice($unvisited, $nearestIndex, 1);
        }

        return $route;
    }

    private function calculateTripCentroid(array $students): ?array
    {
        if (empty($students)) {
            return null;
        }

        $latTotal = 0;
        $lngTotal = 0;

        foreach ($students as $student) {
            $latTotal += $student->latitude;
            $lngTotal += $student->longitude;
        }

        return [
            'latitude' => $latTotal / count($students),
            'longitude' => $lngTotal / count($students),
        ];
    }

    private function calculateDistanceToTripCentroid(Student $student, array $studentsForTrip): float
    {
        $centroid = $this->calculateTripCentroid($studentsForTrip);

        if ($centroid === null) {
            return 0;
        }

        return $this->calculateDistance(
            $student->latitude,
            $student->longitude,
            $centroid['latitude'],
            $centroid['longitude'],
        );
    }

    private function hasValidCoordinates($student): bool
    {
        return is_numeric($student->latitude)
            && is_numeric($student->longitude)
            && $student->latitude >= -90
            && $student->latitude <= 90
            && $student->longitude >= -180
            && $student->longitude <= 180;
    }

    private function buildAndOptimizeMorningRoute($studentsForTrip, $fleet)
    {
        // Start with a nearest-neighbor route from the fleet base.
        $route = $this->buildNearestNeighborMorningRoute($studentsForTrip, $fleet);

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

        if ($studentsAll->isEmpty()) {
            return;
        }

        $groupedBySession = $studentsAll->groupBy('session_out');

        foreach ($groupedBySession as $session => $students) {
            if (! $session) {
                continue;
            }

            $sessionStart = microtime(true);

            // Afternoon students can only use trips that depart at their exact class dismissal time.
            $trips = FleetTrip::with('fleet')
                ->where('direction', 'afternoon')
                ->where('departure_time', $session)
                ->where('is_active', true)
                ->whereHas('fleet', fn ($query) => $query->where('is_active', true))
                ->orderBy('fleet_id')
                ->orderBy('trip_order')
                ->get();

            Log::info('[RouteOptimizer] Afternoon optimizer session started', [
                'session' => $session,
                'total_trips' => $trips->count(),
                'total_students' => $students->count(),
            ]);

            if ($trips->isEmpty()) {
                $this->logOptimizerTime('Afternoon session finished', $sessionStart, [
                    'session' => $session,
                    'total_assigned_students' => 0,
                ]);

                continue;
            }

            $clusterStart = microtime(true);
            $tripStudents = $this->clusterAfternoonByInsertionCost($students, $trips);
            $assignedStudentsCount = array_sum($this->summarizeTripLoads($tripStudents));
            $this->logOptimizerTime('Afternoon clustering finished', $clusterStart, [
                'session' => $session,
                'assigned_students_count' => $assignedStudentsCount,
                'unassigned_count' => max($students->count() - $assignedStudentsCount, 0),
                'trip_loads' => $this->summarizeTripLoads($tripStudents),
            ]);

            $tripStudents = $this->improveAfternoonAssignmentByMove($tripStudents, $trips, $session);
            $tripStudents = $this->rebalanceAfternoonUnderfilledTrips($tripStudents, $trips, $session);

            foreach ($tripStudents as $tripId => $studentsForTrip) {
                if (empty($studentsForTrip)) {
                    continue;
                }

                $trip = $trips->firstWhere('id', $tripId);
                $fleet = $trip->fleet;

                $orderingStart = microtime(true);
                $route = $this->buildAndOptimizeAfternoonRoute($studentsForTrip);
                $this->logOptimizerTime('Afternoon final route ordering finished', $orderingStart, [
                    'session' => $session,
                    'trip_id' => $trip->id,
                    'fleet_id' => $fleet->id,
                    'student_count' => count($route),
                ]);

                $savingStart = microtime(true);
                foreach ($route as $order => $student) {
                    unset($student->sweep_angle);
                    unset($student->school_distance);

                    $student->update([
                        'afternoon_fleet_id' => $fleet->id,
                        'afternoon_fleet_trip_id' => $trip->id,
                        'afternoon_route_order' => $order + 1,
                    ]);
                }
                $this->logOptimizerTime('Afternoon trip assignment saving finished', $savingStart, [
                    'session' => $session,
                    'trip_id' => $trip->id,
                    'fleet_id' => $fleet->id,
                    'student_count' => count($route),
                ]);
            }

            $this->logOptimizerTime('Afternoon session finished', $sessionStart, [
                'session' => $session,
                'total_assigned_students' => array_sum($this->summarizeTripLoads($tripStudents)),
            ]);
        }
    }

    private function clusterAfternoonByInsertionCost($students, $trips): array
    {
        $tripStudents = [];
        $tripCapacities = [];
        $validStudents = [];

        foreach ($trips as $trip) {
            $tripStudents[$trip->id] = [];
            $tripCapacities[$trip->id] = $trip->fleet->capacity;
        }

        foreach ($students as $student) {
            if (! $this->hasValidCoordinates($student)) {
                continue;
            }

            $student->school_distance = $this->calculateDistance(
                self::SCHOOL_LAT,
                self::SCHOOL_LNG,
                $student->latitude,
                $student->longitude,
            );
            $validStudents[] = $student;
        }

        usort($validStudents, function ($a, $b) {
            return $b->school_distance <=> $a->school_distance;
        });

        foreach ($validStudents as $student) {
            $bestTripId = null;
            $bestScore = INF;

            foreach ($trips as $trip) {
                $tripId = $trip->id;

                if (count($tripStudents[$tripId]) >= $tripCapacities[$tripId]) {
                    continue;
                }

                $currentStudents = $tripStudents[$tripId];
                $currentDistance = $this->estimateAfternoonRouteDistance($currentStudents);
                $newDistance = $this->estimateAfternoonRouteDistance([...$currentStudents, $student]);
                $insertionCost = $newDistance - $currentDistance;

                // Capacity balance is a soft nudge only; route geography remains the primary score.
                $loadRatio = $tripCapacities[$tripId] > 0
                    ? count($currentStudents) / $tripCapacities[$tripId]
                    : 1;
                $capacityPenalty = $loadRatio * 0.3;
                $outlierPenalty = $this->calculateDistanceToTripCentroid($student, $currentStudents) * 0.15;
                $score = $insertionCost + $capacityPenalty + $outlierPenalty;

                if ($score < $bestScore) {
                    $bestScore = $score;
                    $bestTripId = $tripId;
                }
            }

            if ($bestTripId !== null) {
                $tripStudents[$bestTripId][] = $student;
            }
        }

        foreach ($validStudents as $student) {
            unset($student->school_distance);
        }

        return $tripStudents;
    }

    private function improveAfternoonAssignmentByMove(array $tripStudents, $trips, $session = null): array
    {
        $start = microtime(true);
        $maxIterations = 5;
        $iteration = 0;
        $improved = true;
        $movesApplied = 0;

        while ($improved && $iteration < $maxIterations) {
            $improved = false;
            $iteration++;

            foreach ($trips as $fromTrip) {
                $fromTripId = $fromTrip->id;

                foreach ($tripStudents[$fromTripId] as $studentIndex => $student) {
                    foreach ($trips as $toTrip) {
                        $toTripId = $toTrip->id;

                        if ($fromTripId === $toTripId || count($tripStudents[$toTripId]) >= $toTrip->fleet->capacity) {
                            continue;
                        }

                        $fromStudentsAfterMove = $tripStudents[$fromTripId];
                        array_splice($fromStudentsAfterMove, $studentIndex, 1);
                        $toStudentsAfterMove = [...$tripStudents[$toTripId], $student];

                        $before = $this->estimateAfternoonRouteDistance($tripStudents[$fromTripId])
                            + $this->estimateAfternoonRouteDistance($tripStudents[$toTripId]);
                        $after = $this->estimateAfternoonRouteDistance($fromStudentsAfterMove)
                            + $this->estimateAfternoonRouteDistance($toStudentsAfterMove);

                        if ($after + self::MORNING_IMPROVEMENT_EPSILON < $before) {
                            $tripStudents[$fromTripId] = $fromStudentsAfterMove;
                            $tripStudents[$toTripId] = $toStudentsAfterMove;
                            $improved = true;
                            $movesApplied++;

                            continue 4;
                        }
                    }
                }
            }
        }

        $this->logOptimizerTime('Afternoon move improvement finished', $start, [
            'session' => $session,
            'iterations' => $iteration,
            'moves_applied' => $movesApplied,
            'trip_loads' => $this->summarizeTripLoads($tripStudents),
        ]);

        return $tripStudents;
    }

    private function rebalanceAfternoonUnderfilledTrips(array $tripStudents, $trips, $session = null): array
    {
        $start = microtime(true);
        $underfilledRatio = 0.65;
        $overfilledRatio = 0.90;
        $maxExtraDistanceKm = 2.0;
        $maxIterations = 10;
        $iteration = 0;
        $movesApplied = 0;

        while ($iteration < $maxIterations) {
            $iteration++;
            $bestMove = null;

            foreach ($trips as $sourceTrip) {
                $sourceTripId = $sourceTrip->id;
                $sourceStudents = $tripStudents[$sourceTripId];

                if ($this->tripLoadRatio($sourceStudents, $sourceTrip->fleet->capacity) <= $overfilledRatio) {
                    continue;
                }

                foreach ($trips as $destinationTrip) {
                    $destinationTripId = $destinationTrip->id;
                    $destinationStudents = $tripStudents[$destinationTripId];

                    if ($sourceTripId === $destinationTripId
                        || $this->tripLoadRatio($destinationStudents, $destinationTrip->fleet->capacity) >= $underfilledRatio
                        || count($destinationStudents) >= $destinationTrip->fleet->capacity
                    ) {
                        continue;
                    }

                    foreach ($sourceStudents as $studentIndex => $student) {
                        $sourceStudentsAfterMove = $sourceStudents;
                        array_splice($sourceStudentsAfterMove, $studentIndex, 1);

                        if ($this->tripLoadRatio($sourceStudentsAfterMove, $sourceTrip->fleet->capacity) < $underfilledRatio) {
                            continue;
                        }

                        $destinationStudentsAfterMove = [...$destinationStudents, $student];
                        $before = $this->estimateAfternoonRouteDistance($sourceStudents)
                            + $this->estimateAfternoonRouteDistance($destinationStudents);
                        $after = $this->estimateAfternoonRouteDistance($sourceStudentsAfterMove)
                            + $this->estimateAfternoonRouteDistance($destinationStudentsAfterMove);
                        $extraDistance = $after - $before;

                        if ($extraDistance > $maxExtraDistanceKm + self::MORNING_IMPROVEMENT_EPSILON) {
                            continue;
                        }

                        if ($bestMove === null || $extraDistance < $bestMove['extra_distance']) {
                            $bestMove = [
                                'source_trip_id' => $sourceTripId,
                                'destination_trip_id' => $destinationTripId,
                                'source_students' => $sourceStudentsAfterMove,
                                'destination_students' => $destinationStudentsAfterMove,
                                'extra_distance' => $extraDistance,
                            ];
                        }
                    }
                }
            }

            if ($bestMove === null) {
                break;
            }

            // Soft business rebalance: accept small detours, but never force equal afternoon loads.
            $tripStudents[$bestMove['source_trip_id']] = $bestMove['source_students'];
            $tripStudents[$bestMove['destination_trip_id']] = $bestMove['destination_students'];
            $movesApplied++;
        }

        $this->logOptimizerTime('Afternoon rebalance finished', $start, [
            'session' => $session,
            'iterations' => $iteration,
            'moves_applied' => $movesApplied,
            'trip_loads' => $this->summarizeTripLoads($tripStudents),
        ]);

        return $tripStudents;
    }

    private function estimateAfternoonRouteDistance(array $students): float
    {
        $route = $this->buildNearestNeighborAfternoonRoute($students);

        return $this->routeDistanceAfternoon($route);
    }

    private function buildNearestNeighborAfternoonRoute(array $students): array
    {
        $unvisited = array_values($students);
        $route = [];
        $currentLat = self::SCHOOL_LAT;
        $currentLng = self::SCHOOL_LNG;

        while (! empty($unvisited)) {
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

            array_splice($unvisited, $nearestIndex, 1);
        }

        return $route;
    }

    private function routeDistanceAfternoon(array $route): float
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

    private function buildAndOptimizeAfternoonRoute($studentsForTrip): array
    {
        $route = $this->buildNearestNeighborAfternoonRoute($studentsForTrip);

        return $this->twoOptAfternoon($route);
    }

    private function twoOptAfternoon(array $route): array
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

                    if ($this->routeDistanceAfternoon($newRoute) < $this->routeDistanceAfternoon($route)) {
                        $route = $newRoute;
                        $improved = true;
                    }
                }
            }
        }

        return $route;
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

            if (! $assigned) {
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
