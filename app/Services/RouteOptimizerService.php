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

    public function optimizeMorning(): void
    {
        Student::query()->update([
            'morning_fleet_id' => null,
            'morning_fleet_trip_id' => null,
            'morning_route_order' => null,
        ]);

        $this->optimizeMorningRoutes();
        $this->refreshPaidStudentStatuses();
    }

    public function optimizeAfternoon(): void
    {
        Student::query()->update([
            'afternoon_fleet_id' => null,
            'afternoon_fleet_trip_id' => null,
            'afternoon_route_order' => null,
        ]);

        $this->optimizeAfternoonRoutes();
        $this->refreshPaidStudentStatuses();
    }

    public function buildVisualizationTrace(string $direction = 'morning', ?string $session = null): array
    {
        if ($direction === 'afternoon') {
            return $this->buildAfternoonVisualizationTrace($session);
        }

        return $this->buildMorningVisualizationTrace();
    }

    private function buildMorningVisualizationTrace(): array
    {
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

        return $this->buildDirectionalVisualizationTrace(
            direction: 'morning',
            trips: $trips,
            students: $students,
            session: null,
            availableSessions: [],
        );
    }

    private function buildAfternoonVisualizationTrace(?string $requestedSession = null): array
    {
        $studentsAll = Student::where('payment_status', 'paid')
            ->whereIn('service_type', ['full', 'dropoff_only'])
            ->get();

        $availableSessions = $studentsAll
            ->map(fn (Student $student) => $this->normalizeSessionTime($student->session_out))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $session = $this->normalizeSessionTime($requestedSession) ?: ($availableSessions[0] ?? null);
        $students = $session
            ? $studentsAll->filter(fn (Student $student) => $this->normalizeSessionTime($student->session_out) === $session)->values()
            : collect();

        $trips = $session
            ? FleetTrip::with('fleet')
                ->where('direction', 'afternoon')
                ->where('departure_time', $session)
                ->where('is_active', true)
                ->whereHas('fleet', fn ($query) => $query->where('is_active', true))
                ->orderBy('fleet_id')
                ->orderBy('trip_order')
                ->get()
            : collect();

        return $this->buildDirectionalVisualizationTrace(
            direction: 'afternoon',
            trips: $trips,
            students: $students,
            session: $session,
            availableSessions: $availableSessions,
        );
    }

    private function buildDirectionalVisualizationTrace(string $direction, $trips, $students, ?string $session, array $availableSessions): array
    {
        $tripStudents = [];
        $tripCapacities = [];
        $validStudents = [];
        $invalidStudents = 0;
        $events = [];

        foreach ($trips as $trip) {
            $tripStudents[$trip->id] = [];
            $tripCapacities[$trip->id] = $trip->fleet->capacity;
        }

        foreach ($students as $student) {
            if (! $this->hasValidCoordinates($student)) {
                $invalidStudents++;
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

        $events[] = $this->makeTraceEvent(
            'filter',
            'Filter siswa dan rit aktif',
            $direction === 'morning'
                ? 'Siswa lunas dengan layanan full atau pickup_only dipasangkan ke rit pagi aktif.'
                : 'Siswa lunas dengan layanan full atau dropoff_only difilter lagi berdasarkan sesi jam pulang.',
            $tripStudents,
            [
                'eligible_students_count' => count($validStudents),
                'invalid_students_count' => $invalidStudents,
                'available_trips_count' => $trips->count(),
            ],
        );

        usort($validStudents, function ($a, $b) {
            return $b->school_distance <=> $a->school_distance;
        });

        $events[] = $this->makeTraceEvent(
            'sort',
            'Urutkan siswa terjauh lebih dulu',
            'Heuristik memproses siswa dari jarak Haversine terjauh terhadap sekolah agar titik yang sulit ditempatkan diprioritaskan.',
            $tripStudents,
            [
                'sorted_student_ids' => array_map(fn (Student $student) => $student->id, $validStudents),
            ],
        );

        foreach ($validStudents as $student) {
            $bestTripId = null;
            $bestScore = INF;
            $scoreRows = [];

            foreach ($trips as $trip) {
                $tripId = $trip->id;
                $currentStudents = $tripStudents[$tripId];
                $capacity = $tripCapacities[$tripId];

                if (count($currentStudents) >= $capacity) {
                    $scoreRows[] = $this->makeScoreRow($trip, count($currentStudents), $capacity, null, null, null, null, false);
                    continue;
                }

                if ($direction === 'morning') {
                    $currentDistance = $this->estimateMorningRouteDistance($currentStudents, $trip->fleet);
                    $newDistance = $this->estimateMorningRouteDistance([...$currentStudents, $student], $trip->fleet);
                } else {
                    $currentDistance = $this->estimateAfternoonRouteDistance($currentStudents);
                    $newDistance = $this->estimateAfternoonRouteDistance([...$currentStudents, $student]);
                }

                $insertionCost = $newDistance - $currentDistance;
                $loadRatio = $capacity > 0 ? count($currentStudents) / $capacity : 1;
                $capacityPenalty = $loadRatio * 0.3;
                $outlierPenalty = $this->calculateDistanceToTripCentroid($student, $currentStudents) * 0.15;
                $score = $insertionCost + $capacityPenalty + $outlierPenalty;

                $scoreRows[] = $this->makeScoreRow(
                    $trip,
                    count($currentStudents),
                    $capacity,
                    $insertionCost,
                    $capacityPenalty,
                    $outlierPenalty,
                    $score,
                    true,
                );

                if ($score < $bestScore) {
                    $bestScore = $score;
                    $bestTripId = $tripId;
                }
            }

            if ($bestTripId !== null) {
                $tripStudents[$bestTripId][] = $student;
            }

            $events[] = $this->makeTraceEvent(
                'insertion',
                'Hitung skor penyisipan: '.$student->name,
                $bestTripId === null
                    ? 'Tidak ada rit yang masih memiliki kapasitas.'
                    : 'Rit dengan total skor terkecil dipilih untuk siswa ini.',
                $tripStudents,
                [
                    'active_student_id' => $student->id,
                    'selected_trip_id' => $bestTripId,
                    'score_rows' => array_map(function (array $row) use ($bestTripId) {
                        $row['selected'] = $row['trip_id'] === $bestTripId;

                        return $row;
                    }, $scoreRows),
                ],
            );
        }

        $tripStudents = $direction === 'morning'
            ? $this->improveMorningAssignmentByMove($tripStudents, $trips, $events)
            : $this->improveAfternoonAssignmentByMove($tripStudents, $trips, $session, $events);

        $tripStudents = $direction === 'morning'
            ? $this->rebalanceMorningUnderfilledTrips($tripStudents, $trips, $events)
            : $this->rebalanceAfternoonUnderfilledTrips($tripStudents, $trips, $session, $events);

        $finalRoutes = [];

        foreach ($tripStudents as $tripId => $studentsForTrip) {
            if (empty($studentsForTrip)) {
                continue;
            }

            $trip = $trips->firstWhere('id', $tripId);
            $nearestSteps = [];
            $twoOptMoves = [];
            $twoOptEvaluatedCandidates = 0;

            if ($direction === 'morning') {
                $nearestRoute = $this->buildNearestNeighborMorningRoute($studentsForTrip, $trip->fleet, $nearestSteps);
                $nearestDistance = $this->routeDistanceMorning($nearestRoute, $trip->fleet->base_latitude, $trip->fleet->base_longitude);
                $optimizedRoute = $this->twoOptMorning(
                    $nearestRoute,
                    $trip->fleet->base_latitude,
                    $trip->fleet->base_longitude,
                    $twoOptMoves,
                    $twoOptEvaluatedCandidates,
                );
                $optimizedDistance = $this->routeDistanceMorning($optimizedRoute, $trip->fleet->base_latitude, $trip->fleet->base_longitude);
            } else {
                $nearestRoute = $this->buildNearestNeighborAfternoonRoute($studentsForTrip, $nearestSteps);
                $nearestDistance = $this->routeDistanceAfternoon($nearestRoute);
                $optimizedRoute = $this->twoOptAfternoon($nearestRoute, $twoOptMoves, $twoOptEvaluatedCandidates);
                $optimizedDistance = $this->routeDistanceAfternoon($optimizedRoute);
            }

            $events[] = $this->makeTraceEvent(
                'nearest_neighbor',
                'Nearest neighbor: '.$this->formatTraceTripLabel($trip),
                $direction === 'morning'
                    ? 'Urutan awal dibangun dari lokasi pool armada menuju siswa terdekat berikutnya, lalu berakhir di sekolah.'
                    : 'Urutan awal dibangun dari sekolah menuju siswa terdekat berikutnya.',
                $tripStudents,
                [
                    'active_trip_id' => $trip->id,
                    'route_student_ids' => array_map(fn (Student $student) => $student->id, $nearestRoute),
                    'route_distance_km' => $this->roundTraceNumber($nearestDistance),
                    'steps' => $nearestSteps,
                ],
            );

            $finalRoutes[$tripId] = array_map(fn (Student $student) => $student->id, $optimizedRoute);

            $events[] = $this->makeTraceEvent(
                'two_opt',
                'Perbaikan 2-opt: '.$this->formatTraceTripLabel($trip),
                'Segmen rute dibalik hanya jika total jarak Haversine menjadi lebih pendek.',
                $tripStudents,
                [
                    'active_trip_id' => $trip->id,
                    'route_before_ids' => array_map(fn (Student $student) => $student->id, $nearestRoute),
                    'route_student_ids' => $finalRoutes[$tripId],
                    'distance_before_km' => $this->roundTraceNumber($nearestDistance),
                    'distance_after_km' => $this->roundTraceNumber($optimizedDistance),
                    'distance_delta_km' => $this->roundTraceNumber($optimizedDistance - $nearestDistance),
                    'accepted_moves' => $twoOptMoves,
                    'accepted_move_count' => count($twoOptMoves),
                    'evaluated_candidates' => $twoOptEvaluatedCandidates,
                ],
            );
        }

        $events[] = $this->makeTraceEvent(
            'road_geometry',
            'Finalisasi visual rute',
            'Urutan siswa hasil heuristik dikirim ke tampilan peta; geometri jalan OSRM hanya dipakai untuk menggambar polyline di dashboard.',
            $tripStudents,
            [
                'final_routes' => $finalRoutes,
            ],
        );

        foreach ($validStudents as $student) {
            unset($student->school_distance);
        }

        return [
            'direction' => $direction,
            'direction_label' => $direction === 'morning' ? 'Rute Pagi' : 'Rute Pulang',
            'session' => $session,
            'available_sessions' => $availableSessions,
            'school' => [
                'name' => 'Sekolah',
                'latitude' => self::SCHOOL_LAT,
                'longitude' => self::SCHOOL_LNG,
            ],
            'trips' => $this->mapTripsForTrace($trips),
            'students' => array_map(fn (Student $student) => $this->mapStudentForTrace($student), $validStudents),
            'summary' => [
                'eligible_students' => count($validStudents),
                'invalid_students' => $invalidStudents,
                'assigned_students' => array_sum($this->summarizeTripLoads($tripStudents)),
                'active_trips' => $trips->count(),
            ],
            'events' => $this->withTraceEventNumbers($events),
        ];
    }

    private function refreshPaidStudentStatuses(): void
    {
        Student::where('payment_status', 'paid')
            ->update(['status' => 'registered']);

        Student::where('payment_status', 'paid')
            ->where(function ($query) {
                $query->whereNotNull('morning_fleet_id')
                    ->orWhereNotNull('afternoon_fleet_id');
            })
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

    private function improveMorningAssignmentByMove(array $tripStudents, $trips, ?array &$traceEvents = null): array
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

                            if ($traceEvents !== null) {
                                $traceEvents[] = $this->makeTraceEvent(
                                    'local_move',
                                    'Local move: '.$student->name,
                                    'Siswa dipindahkan karena total estimasi jarak dua rit menjadi lebih pendek.',
                                    $tripStudents,
                                    [
                                        'active_student_id' => $student->id,
                                        'source_trip_id' => $fromTripId,
                                        'destination_trip_id' => $toTripId,
                                        'distance_before_km' => $this->roundTraceNumber($before),
                                        'distance_after_km' => $this->roundTraceNumber($after),
                                        'distance_delta_km' => $this->roundTraceNumber($after - $before),
                                    ],
                                );
                            }

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

        if ($traceEvents !== null && $movesApplied === 0) {
            $traceEvents[] = $this->makeTraceEvent(
                'local_move',
                'Local move selesai',
                'Tidak ada perpindahan siswa yang membuat total estimasi jarak lebih pendek.',
                $tripStudents,
                [
                    'moves_applied' => 0,
                    'iterations' => $iteration,
                ],
            );
        }

        return $tripStudents;
    }

    private function rebalanceMorningUnderfilledTrips(array $tripStudents, $trips, ?array &$traceEvents = null): array
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
                                'student_id' => $student->id,
                                'source_trip_id' => $sourceTripId,
                                'destination_trip_id' => $destinationTripId,
                                'source_students' => $sourceStudentsAfterMove,
                                'destination_students' => $destinationStudentsAfterMove,
                                'distance_before' => $before,
                                'distance_after' => $after,
                                'extra_distance' => $extraDistance,
                                'source_load_before' => count($sourceStudents),
                                'source_load_after' => count($sourceStudentsAfterMove),
                                'source_capacity' => $sourceTrip->fleet->capacity,
                                'destination_load_before' => count($destinationStudents),
                                'destination_load_after' => count($destinationStudentsAfterMove),
                                'destination_capacity' => $destinationTrip->fleet->capacity,
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

            if ($traceEvents !== null) {
                $traceEvents[] = $this->makeTraceEvent(
                    'rebalance',
                    'Soft rebalance rit',
                    'Satu siswa dipindahkan dari rit yang sangat penuh ke rit yang masih longgar dengan tambahan jarak kecil.',
                    $tripStudents,
                    [
                        'active_student_id' => $bestMove['student_id'],
                        'source_trip_id' => $bestMove['source_trip_id'],
                        'destination_trip_id' => $bestMove['destination_trip_id'],
                        'distance_before_km' => $this->roundTraceNumber($bestMove['distance_before']),
                        'distance_after_km' => $this->roundTraceNumber($bestMove['distance_after']),
                        'distance_delta_km' => $this->roundTraceNumber($bestMove['extra_distance']),
                        'extra_distance_km' => $this->roundTraceNumber($bestMove['extra_distance']),
                        'source_load_before' => $bestMove['source_load_before'],
                        'source_load_after' => $bestMove['source_load_after'],
                        'source_capacity' => $bestMove['source_capacity'],
                        'destination_load_before' => $bestMove['destination_load_before'],
                        'destination_load_after' => $bestMove['destination_load_after'],
                        'destination_capacity' => $bestMove['destination_capacity'],
                        'underfilled_ratio' => $underfilledRatio,
                        'overfilled_ratio' => $overfilledRatio,
                        'max_extra_distance_km' => $maxExtraDistanceKm,
                    ],
                );
            }
        }

        $this->logOptimizerTime('Morning rebalance finished', $start, [
            'iterations' => $iteration,
            'moves_applied' => $movesApplied,
            'trip_loads' => $this->summarizeTripLoads($tripStudents),
        ]);

        if ($traceEvents !== null && $movesApplied === 0) {
            $traceEvents[] = $this->makeTraceEvent(
                'rebalance',
                'Soft rebalance selesai',
                'Tidak ada rit yang memenuhi syarat pemindahan lunak: sumber harus sangat penuh, tujuan masih longgar, dan tambahan jarak dibatasi 1.5 km.',
                $tripStudents,
                [
                    'moves_applied' => 0,
                    'iterations' => $iteration,
                    'underfilled_ratio' => $underfilledRatio,
                    'overfilled_ratio' => $overfilledRatio,
                    'max_extra_distance_km' => $maxExtraDistanceKm,
                ],
            );
        }

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

    private function makeTraceEvent(string $phase, string $title, string $description, array $tripStudents, array $extra = []): array
    {
        return array_merge([
            'phase' => $phase,
            'title' => $title,
            'description' => $description,
            'trip_loads' => $this->snapshotTripLoads($tripStudents),
            'load_counts' => $this->summarizeTripLoads($tripStudents),
        ], $extra);
    }

    private function snapshotTripLoads(array $tripStudents): array
    {
        $snapshot = [];

        foreach ($tripStudents as $tripId => $students) {
            $snapshot[$tripId] = array_map(fn (Student $student) => $student->id, array_values($students));
        }

        return $snapshot;
    }

    private function makeScoreRow($trip, int $currentLoad, int $capacity, ?float $insertionCost, ?float $capacityPenalty, ?float $outlierPenalty, ?float $score, bool $available): array
    {
        return [
            'trip_id' => $trip->id,
            'trip_label' => $this->formatTraceTripLabel($trip),
            'current_load' => $currentLoad,
            'capacity' => $capacity,
            'available' => $available,
            'insertion_cost_km' => $this->roundTraceNumber($insertionCost),
            'capacity_penalty' => $this->roundTraceNumber($capacityPenalty),
            'outlier_penalty' => $this->roundTraceNumber($outlierPenalty),
            'score' => $this->roundTraceNumber($score),
        ];
    }

    private function mapTripsForTrace($trips): array
    {
        return $trips->map(function ($trip) {
            return [
                'id' => $trip->id,
                'label' => $this->formatTraceTripLabel($trip),
                'direction' => $trip->direction,
                'departure_time' => $this->normalizeSessionTime($trip->departure_time),
                'departure_label' => substr($this->normalizeSessionTime($trip->departure_time), 0, 5),
                'trip_order' => $trip->trip_order,
                'capacity' => (int) $trip->fleet->capacity,
                'fleet' => [
                    'id' => $trip->fleet->id,
                    'name' => $trip->fleet->name,
                    'driver_name' => $trip->fleet->driver_name,
                    'license_plate' => $trip->fleet->license_plate,
                    'base_latitude' => (float) $trip->fleet->base_latitude,
                    'base_longitude' => (float) $trip->fleet->base_longitude,
                    'base_address' => $trip->fleet->base_address,
                ],
            ];
        })->values()->all();
    }

    private function mapStudentForTrace(Student $student): array
    {
        $schoolDistance = $student->school_distance ?? $this->calculateDistance(
            self::SCHOOL_LAT,
            self::SCHOOL_LNG,
            $student->latitude,
            $student->longitude,
        );

        return [
            'id' => $student->id,
            'name' => $student->name,
            'school_level' => $student->school_level,
            'class_room' => $student->class_room,
            'class_room_note' => $student->class_room_note,
            'service_type' => $student->service_type,
            'session_in' => $this->normalizeSessionTime($student->session_in),
            'session_out' => $this->normalizeSessionTime($student->session_out),
            'latitude' => (float) $student->latitude,
            'longitude' => (float) $student->longitude,
            'school_distance_km' => $this->roundTraceNumber($schoolDistance),
        ];
    }

    private function formatTraceTripLabel($trip): string
    {
        $time = substr($this->normalizeSessionTime($trip->departure_time), 0, 5);

        return $trip->fleet->name.' - Rit '.$trip->trip_order.' ('.$time.')';
    }

    private function roundTraceNumber(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return round($value, 3);
    }

    private function makeTracePoint(
        ?Student $student = null,
        string $type = 'student',
        ?string $label = null,
        ?float $latitude = null,
        ?float $longitude = null,
    ): array {
        if ($student) {
            return [
                'type' => 'student',
                'student_id' => $student->id,
                'label' => $student->name,
                'latitude' => (float) $student->latitude,
                'longitude' => (float) $student->longitude,
            ];
        }

        return [
            'type' => $type,
            'student_id' => null,
            'label' => $label ?? ucfirst(str_replace('_', ' ', $type)),
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    private function makeTraceEdge(array $from, array $to): array
    {
        return [
            'from' => $from,
            'to' => $to,
        ];
    }

    private function makeTwoOptTraceMove(
        int $move,
        int $pass,
        int $startIndex,
        int $endIndex,
        array $routeBefore,
        array $routeAfter,
        array $predecessor,
        array $first,
        array $last,
        ?array $successor,
        float $distanceBefore,
        float $distanceAfter,
    ): array {
        $removedEdges = [$this->makeTraceEdge($predecessor, $first)];
        $addedEdges = [$this->makeTraceEdge($predecessor, $last)];

        if ($successor) {
            $removedEdges[] = $this->makeTraceEdge($last, $successor);
            $addedEdges[] = $this->makeTraceEdge($first, $successor);
        }

        return [
            'move' => $move,
            'pass' => $pass,
            'start_position' => $startIndex + 1,
            'end_position' => $endIndex + 1,
            'route_before_ids' => array_map(fn (Student $student) => $student->id, $routeBefore),
            'route_after_ids' => array_map(fn (Student $student) => $student->id, $routeAfter),
            'segment_before_ids' => array_map(
                fn (Student $student) => $student->id,
                array_slice($routeBefore, $startIndex, $endIndex - $startIndex + 1),
            ),
            'segment_after_ids' => array_map(
                fn (Student $student) => $student->id,
                array_slice($routeAfter, $startIndex, $endIndex - $startIndex + 1),
            ),
            'removed_edges' => $removedEdges,
            'added_edges' => $addedEdges,
            'distance_before_km' => $this->roundTraceNumber($distanceBefore),
            'distance_after_km' => $this->roundTraceNumber($distanceAfter),
            'distance_delta_km' => $this->roundTraceNumber($distanceAfter - $distanceBefore),
        ];
    }

    private function withTraceEventNumbers(array $events): array
    {
        return array_map(function (array $event, int $index) {
            $event['id'] = $index + 1;

            return $event;
        }, array_values($events), array_keys(array_values($events)));
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

    private function buildNearestNeighborMorningRoute(array $students, $fleet, ?array &$traceSteps = null): array
    {
        $unvisited = array_values($students);
        $route = [];
        $currentLat = $fleet->base_latitude;
        $currentLng = $fleet->base_longitude;
        $currentPoint = $this->makeTracePoint(
            null,
            'fleet_base',
            'Pool armada',
            (float) $fleet->base_latitude,
            (float) $fleet->base_longitude,
        );

        while (! empty($unvisited)) {
            $nearestIndex = -1;
            $minDist = INF;
            $candidateRows = [];

            foreach ($unvisited as $index => $student) {
                $dist = $this->calculateDistance($currentLat, $currentLng, $student->latitude, $student->longitude);
                $candidateRows[] = [
                    'student_id' => $student->id,
                    'student_name' => $student->name,
                    'distance_km' => $this->roundTraceNumber($dist),
                ];

                if ($dist < $minDist) {
                    $minDist = $dist;
                    $nearestIndex = $index;
                }
            }

            $nearestStudent = $unvisited[$nearestIndex];
            $routeBeforeIds = array_map(fn (Student $student) => $student->id, $route);
            $route[] = $nearestStudent;

            if ($traceSteps !== null) {
                usort($candidateRows, fn (array $a, array $b) => $a['distance_km'] <=> $b['distance_km']);

                $traceSteps[] = [
                    'step' => count($traceSteps) + 1,
                    'current_point' => $currentPoint,
                    'candidate_rows' => array_map(function (array $row) use ($nearestStudent) {
                        $row['selected'] = (int) $row['student_id'] === (int) $nearestStudent->id;

                        return $row;
                    }, $candidateRows),
                    'selected_student_id' => $nearestStudent->id,
                    'selected_student_name' => $nearestStudent->name,
                    'selected_distance_km' => $this->roundTraceNumber($minDist),
                    'route_before_ids' => $routeBeforeIds,
                    'route_after_ids' => array_map(fn (Student $student) => $student->id, $route),
                    'unvisited_before' => count($unvisited),
                    'unvisited_after' => count($unvisited) - 1,
                ];
            }

            $currentLat = $nearestStudent->latitude;
            $currentLng = $nearestStudent->longitude;
            $currentPoint = $this->makeTracePoint($nearestStudent);

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

    private function twoOptMorning(
        $route,
        $baseLat,
        $baseLng,
        ?array &$traceMoves = null,
        ?int &$evaluatedCandidates = null,
    )
    {
        $improved = true;
        $pass = 0;

        while ($improved) {
            $improved = false;
            $pass++;

            for ($i = 0; $i < count($route) - 1; $i++) {
                for ($j = $i + 1; $j < count($route); $j++) {
                    if ($evaluatedCandidates !== null) {
                        $evaluatedCandidates++;
                    }

                    $newRoute = $route;

                    $segment = array_slice($newRoute, $i, $j - $i + 1);
                    $segment = array_reverse($segment);
                    array_splice($newRoute, $i, $j - $i + 1, $segment);

                    $distanceBefore = $this->routeDistanceMorning($route, $baseLat, $baseLng);
                    $distanceAfter = $this->routeDistanceMorning($newRoute, $baseLat, $baseLng);

                    if ($distanceAfter < $distanceBefore) {
                        if ($traceMoves !== null) {
                            $predecessor = $i === 0
                                ? $this->makeTracePoint(null, 'fleet_base', 'Pool armada', (float) $baseLat, (float) $baseLng)
                                : $this->makeTracePoint($route[$i - 1]);
                            $first = $this->makeTracePoint($route[$i]);
                            $last = $this->makeTracePoint($route[$j]);
                            $successor = $j === count($route) - 1
                                ? $this->makeTracePoint(null, 'school', 'Sekolah', self::SCHOOL_LAT, self::SCHOOL_LNG)
                                : $this->makeTracePoint($route[$j + 1]);

                            $traceMoves[] = $this->makeTwoOptTraceMove(
                                count($traceMoves) + 1,
                                $pass,
                                $i,
                                $j,
                                $route,
                                $newRoute,
                                $predecessor,
                                $first,
                                $last,
                                $successor,
                                $distanceBefore,
                                $distanceAfter,
                            );
                        }

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
        $afternoonStart = microtime(true);

        $studentsAll = Student::where('payment_status', 'paid')
            ->whereIn('service_type', ['full', 'dropoff_only'])
            ->get();

        Log::info('[RouteOptimizer] Afternoon optimizer started', [
            'total_students_loaded' => $studentsAll->count(),
        ]);

        if ($studentsAll->isEmpty()) {
            $this->logOptimizerTime('Afternoon optimizer finished', $afternoonStart, [
                'total_assigned_students' => 0,
            ]);

            return;
        }

        // Normalize values from every data source (web input may use H:i while
        // seeded/database TIME values commonly use H:i:s).
        $groupedBySession = $studentsAll->groupBy(
            fn (Student $student) => $this->normalizeSessionTime($student->session_out)
        );
        $totalAssigned = 0;

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

            $sessionAssigned = array_sum($this->summarizeTripLoads($tripStudents));
            $totalAssigned += $sessionAssigned;

            $this->logOptimizerTime('Afternoon session finished', $sessionStart, [
                'session' => $session,
                'total_assigned_students' => $sessionAssigned,
            ]);
        }

        $this->logOptimizerTime('Afternoon optimizer finished', $afternoonStart, [
            'total_assigned_students' => $totalAssigned,
        ]);
    }

    private function normalizeSessionTime(?string $time): string
    {
        if (! $time) {
            return '';
        }

        $timestamp = strtotime($time);

        return $timestamp === false ? $time : date('H:i:s', $timestamp);
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

    private function improveAfternoonAssignmentByMove(array $tripStudents, $trips, $session = null, ?array &$traceEvents = null): array
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

                            if ($traceEvents !== null) {
                                $traceEvents[] = $this->makeTraceEvent(
                                    'local_move',
                                    'Local move: '.$student->name,
                                    'Siswa dipindahkan karena total estimasi jarak dua rit menjadi lebih pendek.',
                                    $tripStudents,
                                    [
                                        'active_student_id' => $student->id,
                                        'source_trip_id' => $fromTripId,
                                        'destination_trip_id' => $toTripId,
                                        'distance_before_km' => $this->roundTraceNumber($before),
                                        'distance_after_km' => $this->roundTraceNumber($after),
                                        'distance_delta_km' => $this->roundTraceNumber($after - $before),
                                    ],
                                );
                            }

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

        if ($traceEvents !== null && $movesApplied === 0) {
            $traceEvents[] = $this->makeTraceEvent(
                'local_move',
                'Local move selesai',
                'Tidak ada perpindahan siswa yang membuat total estimasi jarak lebih pendek.',
                $tripStudents,
                [
                    'session' => $session,
                    'moves_applied' => 0,
                    'iterations' => $iteration,
                ],
            );
        }

        return $tripStudents;
    }

    private function rebalanceAfternoonUnderfilledTrips(array $tripStudents, $trips, $session = null, ?array &$traceEvents = null): array
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
                                'student_id' => $student->id,
                                'source_trip_id' => $sourceTripId,
                                'destination_trip_id' => $destinationTripId,
                                'source_students' => $sourceStudentsAfterMove,
                                'destination_students' => $destinationStudentsAfterMove,
                                'distance_before' => $before,
                                'distance_after' => $after,
                                'extra_distance' => $extraDistance,
                                'source_load_before' => count($sourceStudents),
                                'source_load_after' => count($sourceStudentsAfterMove),
                                'source_capacity' => $sourceTrip->fleet->capacity,
                                'destination_load_before' => count($destinationStudents),
                                'destination_load_after' => count($destinationStudentsAfterMove),
                                'destination_capacity' => $destinationTrip->fleet->capacity,
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

            if ($traceEvents !== null) {
                $traceEvents[] = $this->makeTraceEvent(
                    'rebalance',
                    'Soft rebalance rit',
                    'Satu siswa dipindahkan dari rit yang sangat penuh ke rit yang masih longgar dengan tambahan jarak kecil.',
                    $tripStudents,
                    [
                        'session' => $session,
                        'active_student_id' => $bestMove['student_id'],
                        'source_trip_id' => $bestMove['source_trip_id'],
                        'destination_trip_id' => $bestMove['destination_trip_id'],
                        'distance_before_km' => $this->roundTraceNumber($bestMove['distance_before']),
                        'distance_after_km' => $this->roundTraceNumber($bestMove['distance_after']),
                        'distance_delta_km' => $this->roundTraceNumber($bestMove['extra_distance']),
                        'extra_distance_km' => $this->roundTraceNumber($bestMove['extra_distance']),
                        'source_load_before' => $bestMove['source_load_before'],
                        'source_load_after' => $bestMove['source_load_after'],
                        'source_capacity' => $bestMove['source_capacity'],
                        'destination_load_before' => $bestMove['destination_load_before'],
                        'destination_load_after' => $bestMove['destination_load_after'],
                        'destination_capacity' => $bestMove['destination_capacity'],
                        'underfilled_ratio' => $underfilledRatio,
                        'overfilled_ratio' => $overfilledRatio,
                        'max_extra_distance_km' => $maxExtraDistanceKm,
                    ],
                );
            }
        }

        $this->logOptimizerTime('Afternoon rebalance finished', $start, [
            'session' => $session,
            'iterations' => $iteration,
            'moves_applied' => $movesApplied,
            'trip_loads' => $this->summarizeTripLoads($tripStudents),
        ]);

        if ($traceEvents !== null && $movesApplied === 0) {
            $traceEvents[] = $this->makeTraceEvent(
                'rebalance',
                'Soft rebalance selesai',
                'Tidak ada rit yang memenuhi syarat pemindahan lunak: sumber harus sangat penuh, tujuan masih longgar, dan tambahan jarak dibatasi 2.0 km.',
                $tripStudents,
                [
                    'session' => $session,
                    'moves_applied' => 0,
                    'iterations' => $iteration,
                    'underfilled_ratio' => $underfilledRatio,
                    'overfilled_ratio' => $overfilledRatio,
                    'max_extra_distance_km' => $maxExtraDistanceKm,
                ],
            );
        }

        return $tripStudents;
    }

    private function estimateAfternoonRouteDistance(array $students): float
    {
        $route = $this->buildNearestNeighborAfternoonRoute($students);

        return $this->routeDistanceAfternoon($route);
    }

    private function buildNearestNeighborAfternoonRoute(array $students, ?array &$traceSteps = null): array
    {
        $unvisited = array_values($students);
        $route = [];
        $currentLat = self::SCHOOL_LAT;
        $currentLng = self::SCHOOL_LNG;
        $currentPoint = $this->makeTracePoint(null, 'school', 'Sekolah', self::SCHOOL_LAT, self::SCHOOL_LNG);

        while (! empty($unvisited)) {
            $nearestIndex = -1;
            $minDist = INF;
            $candidateRows = [];

            foreach ($unvisited as $index => $student) {
                $dist = $this->calculateDistance($currentLat, $currentLng, $student->latitude, $student->longitude);
                $candidateRows[] = [
                    'student_id' => $student->id,
                    'student_name' => $student->name,
                    'distance_km' => $this->roundTraceNumber($dist),
                ];

                if ($dist < $minDist) {
                    $minDist = $dist;
                    $nearestIndex = $index;
                }
            }

            $nearestStudent = $unvisited[$nearestIndex];
            $routeBeforeIds = array_map(fn (Student $student) => $student->id, $route);
            $route[] = $nearestStudent;

            if ($traceSteps !== null) {
                usort($candidateRows, fn (array $a, array $b) => $a['distance_km'] <=> $b['distance_km']);

                $traceSteps[] = [
                    'step' => count($traceSteps) + 1,
                    'current_point' => $currentPoint,
                    'candidate_rows' => array_map(function (array $row) use ($nearestStudent) {
                        $row['selected'] = (int) $row['student_id'] === (int) $nearestStudent->id;

                        return $row;
                    }, $candidateRows),
                    'selected_student_id' => $nearestStudent->id,
                    'selected_student_name' => $nearestStudent->name,
                    'selected_distance_km' => $this->roundTraceNumber($minDist),
                    'route_before_ids' => $routeBeforeIds,
                    'route_after_ids' => array_map(fn (Student $student) => $student->id, $route),
                    'unvisited_before' => count($unvisited),
                    'unvisited_after' => count($unvisited) - 1,
                ];
            }

            $currentLat = $nearestStudent->latitude;
            $currentLng = $nearestStudent->longitude;
            $currentPoint = $this->makeTracePoint($nearestStudent);

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

    private function twoOptAfternoon(
        array $route,
        ?array &$traceMoves = null,
        ?int &$evaluatedCandidates = null,
    ): array
    {
        $improved = true;
        $pass = 0;

        while ($improved) {
            $improved = false;
            $pass++;

            for ($i = 0; $i < count($route) - 1; $i++) {
                for ($j = $i + 1; $j < count($route); $j++) {
                    if ($evaluatedCandidates !== null) {
                        $evaluatedCandidates++;
                    }

                    $newRoute = $route;
                    $segment = array_slice($newRoute, $i, $j - $i + 1);
                    $segment = array_reverse($segment);
                    array_splice($newRoute, $i, $j - $i + 1, $segment);

                    $distanceBefore = $this->routeDistanceAfternoon($route);
                    $distanceAfter = $this->routeDistanceAfternoon($newRoute);

                    if ($distanceAfter < $distanceBefore) {
                        if ($traceMoves !== null) {
                            $predecessor = $i === 0
                                ? $this->makeTracePoint(null, 'school', 'Sekolah', self::SCHOOL_LAT, self::SCHOOL_LNG)
                                : $this->makeTracePoint($route[$i - 1]);
                            $first = $this->makeTracePoint($route[$i]);
                            $last = $this->makeTracePoint($route[$j]);
                            $successor = $j === count($route) - 1
                                ? null
                                : $this->makeTracePoint($route[$j + 1]);

                            $traceMoves[] = $this->makeTwoOptTraceMove(
                                count($traceMoves) + 1,
                                $pass,
                                $i,
                                $j,
                                $route,
                                $newRoute,
                                $predecessor,
                                $first,
                                $last,
                                $successor,
                                $distanceBefore,
                                $distanceAfter,
                            );
                        }

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
