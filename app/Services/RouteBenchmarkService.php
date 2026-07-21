<?php

namespace App\Services;

use App\Models\Fleet;
use App\Models\FleetTrip;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use ReflectionClass;

class RouteBenchmarkService
{
    public const METHODS = ['sweep_nn', 'final'];

    private ReflectionClass $optimizerReflection;

    public function __construct(private RouteOptimizerService $optimizer)
    {
        $this->optimizerReflection = new ReflectionClass($optimizer);
    }

    /**
     * @param  array<int, string>  $methods
     */
    public function run(array $methods, int $runs, string $direction): array
    {
        $this->validateMethods($methods);

        if ($runs < 1) {
            throw new InvalidArgumentException('Runs must be at least 1.');
        }

        if (! in_array($direction, ['morning', 'afternoon', 'all'], true)) {
            throw new InvalidArgumentException('Direction must be morning, afternoon, or all.');
        }

        $contexts = $this->loadContexts($direction);
        $timings = [];
        $solutions = [];

        for ($run = 0; $run < $runs; $run++) {
            $orderedMethods = $run % 2 === 0 ? $methods : array_reverse($methods);

            foreach ($orderedMethods as $method) {
                foreach ($contexts as $context) {
                    $key = $this->resultKey($method, $context['direction'], $context['session']);
                    $startedAt = hrtime(true);
                    $solution = $this->withoutOptimizerLogs(fn () => $this->solveContext($context, $method));
                    $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

                    $timings[$key][] = $elapsedMs;
                    $solutions[$key] ??= $solution;
                }
            }
        }

        $rows = [];

        foreach ($solutions as $key => $solution) {
            $row = $this->metricsForSolution($solution);
            $row['algorithm_time_ms_mean'] = $this->mean($timings[$key]);
            $row['algorithm_time_ms_sample_stddev'] = $this->sampleStddev($timings[$key]);
            $row['algorithm_time_ms_min'] = min($timings[$key]);
            $row['algorithm_time_ms_max'] = max($timings[$key]);
            $row['algorithm_time_ms_median'] = $this->median($timings[$key]);
            $rows[] = $row;
        }

        usort($rows, fn (array $a, array $b) => [$a['direction'], $a['session'] ?? '', $a['method']] <=> [$b['direction'], $b['session'] ?? '', $b['method']]);

        return [
            'metadata' => $this->metadata($methods, $runs, $direction),
            'rows' => $this->addComparisons($rows),
            'solutions' => $this->exportableSolutions($solutions),
        ];
    }

    /**
     * @return array{json: string, csv: string, markdown: string}
     */
    public function export(array $result, string $outputPath): array
    {
        $directory = $this->resolveOutputPath($outputPath);
        File::ensureDirectoryExists($directory);

        $timestamp = now()->format('Ymd-His');
        $base = $directory.DIRECTORY_SEPARATOR."route-benchmark-{$timestamp}";
        $jsonPath = "{$base}.json";
        $csvPath = "{$base}.csv";
        $markdownPath = "{$base}.md";

        File::put($jsonPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        $this->writeCsv($csvPath, $result['rows']);
        File::put($markdownPath, $this->markdownSummary($result));

        return [
            'json' => $jsonPath,
            'csv' => $csvPath,
            'markdown' => $markdownPath,
        ];
    }

    /**
     * @param  array<int, string>  $methods
     */
    private function validateMethods(array $methods): void
    {
        foreach ($methods as $method) {
            if (! in_array($method, self::METHODS, true)) {
                throw new InvalidArgumentException("Unsupported method: {$method}");
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadContexts(string $direction): array
    {
        $contexts = [];

        if (in_array($direction, ['morning', 'all'], true)) {
            $contexts[] = [
                'direction' => 'morning',
                'session' => null,
                'students' => Student::query()
                    ->where('payment_status', 'paid')
                    ->whereIn('service_type', ['full', 'pickup_only'])
                    ->orderBy('id')
                    ->get(),
                'trips' => FleetTrip::with('fleet')
                    ->where('direction', 'morning')
                    ->where('is_active', true)
                    ->whereHas('fleet', fn ($query) => $query->where('is_active', true))
                    ->orderBy('departure_time')
                    ->orderBy('fleet_id')
                    ->orderBy('trip_order')
                    ->get(),
            ];
        }

        if (in_array($direction, ['afternoon', 'all'], true)) {
            $studentsAll = Student::query()
                ->where('payment_status', 'paid')
                ->whereIn('service_type', ['full', 'dropoff_only'])
                ->orderBy('id')
                ->get()
                ->groupBy(fn (Student $student) => $this->normalizeSessionTime($student->session_out));

            foreach ($studentsAll as $session => $students) {
                if (! $session) {
                    continue;
                }

                $contexts[] = [
                    'direction' => 'afternoon',
                    'session' => $session,
                    'students' => $students->values(),
                    'trips' => FleetTrip::with('fleet')
                        ->where('direction', 'afternoon')
                        ->where('departure_time', $session)
                        ->where('is_active', true)
                        ->whereHas('fleet', fn ($query) => $query->where('is_active', true))
                        ->orderBy('fleet_id')
                        ->orderBy('trip_order')
                        ->get(),
                ];
            }
        }

        return $contexts;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function solveContext(array $context, string $method): array
    {
        $eligibleStudents = $this->cloneStudents($context['students']);
        $students = $this->validStudents($eligibleStudents);
        $trips = $context['trips'];
        $tripStudents = $this->initializeTripStudents($trips);

        if ($students->isNotEmpty() && $trips->isNotEmpty()) {
            if ($method === 'sweep_nn') {
                $tripStudents = $this->invoke('clusterBySweepAndTripCapacity', $students, $trips);
            } elseif ($context['direction'] === 'morning') {
                $tripStudents = $this->invoke('clusterMorningByInsertionCost', $students, $trips);
                $tripStudents = $this->invoke('improveMorningAssignmentByMove', $tripStudents, $trips);
                $tripStudents = $this->invoke('rebalanceMorningUnderfilledTrips', $tripStudents, $trips);
            } else {
                $tripStudents = $this->invoke('clusterAfternoonByInsertionCost', $students, $trips);
                $tripStudents = $this->invoke('improveAfternoonAssignmentByMove', $tripStudents, $trips, $context['session']);
                $tripStudents = $this->invoke('rebalanceAfternoonUnderfilledTrips', $tripStudents, $trips, $context['session']);
            }
        }

        $tripSolutions = [];

        foreach ($trips as $trip) {
            $studentsForTrip = $tripStudents[$trip->id] ?? [];
            $route = [];
            $distance = 0.0;

            if (! empty($studentsForTrip)) {
                if ($context['direction'] === 'morning') {
                    $route = $method === 'sweep_nn'
                        ? $this->invoke('buildNearestNeighborMorningRoute', $studentsForTrip, $trip->fleet)
                        : $this->invoke('buildAndOptimizeMorningRoute', $studentsForTrip, $trip->fleet);
                    $distance = $this->invoke('routeDistanceMorning', $route, $trip->fleet->base_latitude, $trip->fleet->base_longitude);
                } else {
                    $route = $method === 'sweep_nn'
                        ? $this->invoke('buildNearestNeighborAfternoonRoute', $studentsForTrip)
                        : $this->invoke('buildAndOptimizeAfternoonRoute', $studentsForTrip);
                    $distance = $this->invoke('routeDistanceAfternoon', $route);
                }
            }

            $tripSolutions[] = [
                'trip_id' => $trip->id,
                'fleet_id' => $trip->fleet_id,
                'capacity' => (int) $trip->fleet->capacity,
                'direction' => $context['direction'],
                'session' => $context['session'],
                'departure_time' => $this->normalizeSessionTime($trip->departure_time),
                'trip_is_active' => (bool) $trip->is_active,
                'fleet_is_active' => (bool) $trip->fleet->is_active,
                'student_ids' => collect($studentsForTrip)->pluck('id')->values()->all(),
                'route_student_ids' => collect($route)->pluck('id')->values()->all(),
                'distance_km' => $distance,
                'route' => $route,
            ];
        }

        return [
            'method' => $method,
            'direction' => $context['direction'],
            'session' => $context['session'],
            'processed_student_ids' => $eligibleStudents->pluck('id')->values()->all(),
            'trips' => $tripSolutions,
        ];
    }

    private function metricsForSolution(array $solution): array
    {
        $trips = $solution['trips'];
        $routeStudentIds = [];
        $wrongService = 0;
        $wrongAfternoonSession = 0;
        $inactiveTripOrFleet = 0;
        $capacityViolations = 0;
        $totalCapacity = 0;
        $usedCapacity = 0;
        $totalDistance = 0.0;
        $utilizations = [];

        foreach ($trips as $trip) {
            $load = count($trip['route_student_ids']);
            $capacity = $trip['capacity'];
            $totalCapacity += $capacity;
            $utilizations[] = $capacity > 0 ? $load / $capacity : 1.0;
            $totalDistance += $trip['distance_km'];

            if ($load > 0) {
                $usedCapacity += $capacity;
            }

            if ($load > $capacity) {
                $capacityViolations++;
            }

            if (($load > 0) && (! $trip['trip_is_active'] || ! $trip['fleet_is_active'])) {
                $inactiveTripOrFleet += $load;
            }

            foreach ($trip['route'] as $student) {
                $routeStudentIds[] = $student->id;

                if ($solution['direction'] === 'morning' && ! in_array($student->service_type, ['full', 'pickup_only'], true)) {
                    $wrongService++;
                }

                if ($solution['direction'] === 'afternoon') {
                    if (! in_array($student->service_type, ['full', 'dropoff_only'], true)) {
                        $wrongService++;
                    }

                    if ($this->normalizeSessionTime($student->session_out) !== $solution['session']) {
                        $wrongAfternoonSession++;
                    }
                }
            }
        }

        $allocated = count($routeStudentIds);
        $uniqueAllocated = count(array_unique($routeStudentIds));
        $usedTrips = collect($trips)->filter(fn (array $trip) => count($trip['route_student_ids']) > 0)->count();
        $averageUtilization = $this->mean($utilizations);
        $utilizationStddev = $this->sampleStddev($utilizations);

        return [
            'method' => $solution['method'],
            'direction' => $solution['direction'],
            'session' => $solution['session'],
            'students_processed' => count($solution['processed_student_ids']),
            'students_allocated' => $uniqueAllocated,
            'students_unallocated' => max(count($solution['processed_student_ids']) - $uniqueAllocated, 0),
            'active_trips' => count($trips),
            'used_trips' => $usedTrips,
            'empty_trips' => count($trips) - $usedTrips,
            'total_active_capacity' => $totalCapacity,
            'total_used_trip_capacity' => $usedCapacity,
            'active_trip_utilization_percent' => $totalCapacity > 0 ? ($uniqueAllocated / $totalCapacity) * 100 : 0.0,
            'used_trip_utilization_percent' => $usedCapacity > 0 ? ($uniqueAllocated / $usedCapacity) * 100 : 0.0,
            'average_trip_utilization_percent' => $averageUtilization * 100,
            'trip_utilization_sample_stddev_percent' => $utilizationStddev * 100,
            'trip_load_coefficient_of_variation' => $averageUtilization > 0 ? $utilizationStddev / $averageUtilization : 0.0,
            'total_haversine_distance_km' => $totalDistance,
            'average_distance_per_used_trip_km' => $usedTrips > 0 ? $totalDistance / $usedTrips : 0.0,
            'capacity_violations' => $capacityViolations,
            'duplicate_students' => $allocated - $uniqueAllocated,
            'wrong_service_students' => $wrongService,
            'wrong_afternoon_session_students' => $wrongAfternoonSession,
            'inactive_trip_or_fleet_students' => $inactiveTripOrFleet,
        ];
    }

    private function addComparisons(array $rows): array
    {
        $baselineByContext = [];

        foreach ($rows as $row) {
            if ($row['method'] === 'sweep_nn') {
                $baselineByContext[$this->contextKey($row['direction'], $row['session'])] = $row;
            }
        }

        foreach ($rows as $index => $row) {
            $baseline = $baselineByContext[$this->contextKey($row['direction'], $row['session'])] ?? null;
            $rows[$index]['distance_improvement_vs_sweep_nn_percent'] = null;
            $rows[$index]['active_utilization_change_vs_sweep_nn_percent_points'] = null;
            $rows[$index]['load_cv_change_vs_sweep_nn'] = null;

            if ($baseline && $row['method'] === 'final') {
                $baselineDistance = $baseline['total_haversine_distance_km'];
                $rows[$index]['distance_improvement_vs_sweep_nn_percent'] = $baselineDistance > 0
                    ? (($baselineDistance - $row['total_haversine_distance_km']) / $baselineDistance) * 100
                    : null;
                $rows[$index]['active_utilization_change_vs_sweep_nn_percent_points'] = $row['active_trip_utilization_percent'] - $baseline['active_trip_utilization_percent'];
                $rows[$index]['load_cv_change_vs_sweep_nn'] = $row['trip_load_coefficient_of_variation'] - $baseline['trip_load_coefficient_of_variation'];
            }
        }

        return $rows;
    }

    private function exportableSolutions(array $solutions): array
    {
        $exportable = [];

        foreach ($solutions as $key => $solution) {
            $solution['trips'] = array_map(function (array $trip) {
                unset($trip['route']);

                return $trip;
            }, $solution['trips']);
            $exportable[$key] = $solution;
        }

        return $exportable;
    }

    private function metadata(array $methods, int $runs, string $direction): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'methods' => $methods,
            'runs' => $runs,
            'direction' => $direction,
            'git_commit' => trim((string) @shell_exec('git rev-parse HEAD')) ?: 'unknown',
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'database_driver' => DB::connection()->getDriverName(),
            'database_version' => $this->databaseVersion(),
            'operating_system' => php_uname(),
            'cpu' => getenv('PROCESSOR_IDENTIFIER') ?: null,
            'ram_bytes' => $this->detectRamBytes(),
            'student_count' => Student::count(),
            'fleet_count' => Fleet::count(),
            'morning_trip_count' => FleetTrip::where('direction', 'morning')->count(),
            'afternoon_trip_count' => FleetTrip::where('direction', 'afternoon')->count(),
            'session_count' => Student::query()->whereNotNull('session_out')->distinct()->count('session_out'),
            'timing_note' => 'Data snapshot is loaded before timing. Method execution order alternates by run parity to reduce method-order cache bias.',
            'algorithm_parameters' => [
                'capacity_penalty_weight' => 0.3,
                'outlier_penalty_weight' => 0.15,
                'improvement_epsilon' => RouteOptimizerService::MORNING_IMPROVEMENT_EPSILON,
                'local_move_max_iterations' => 5,
                'rebalance_underfilled_ratio' => 0.65,
                'rebalance_overfilled_ratio' => 0.90,
                'morning_rebalance_max_extra_distance_km' => 1.5,
                'afternoon_rebalance_max_extra_distance_km' => 2.0,
                'morning_rebalance_max_iterations' => 20,
                'afternoon_rebalance_max_iterations' => 10,
                'two_opt_stopping_criterion' => 'Repeat until a complete pass finds no strictly shorter Haversine route.',
                'haversine_earth_radius_km' => 6371,
            ],
        ];
    }

    private function markdownSummary(array $result): string
    {
        $lines = [
            '# Route Benchmark Summary',
            '',
            '- Generated at: '.$result['metadata']['generated_at'],
            '- Git commit: '.$result['metadata']['git_commit'],
            '- Runs: '.$result['metadata']['runs'],
            '- Timing: '.$result['metadata']['timing_note'],
            '',
            '| Method | Direction | Session | Students | Used trips | Distance km | Active util % | Load CV | Time mean ms | Distance improvement vs sweep_nn % |',
            '| --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |',
        ];

        foreach ($result['rows'] as $row) {
            $lines[] = sprintf(
                '| %s | %s | %s | %d/%d | %d | %.4f | %.2f | %.4f | %.4f | %s |',
                $row['method'],
                $row['direction'],
                $row['session'] ?? '-',
                $row['students_allocated'],
                $row['students_processed'],
                $row['used_trips'],
                $row['total_haversine_distance_km'],
                $row['active_trip_utilization_percent'],
                $row['trip_load_coefficient_of_variation'],
                $row['algorithm_time_ms_mean'],
                $row['distance_improvement_vs_sweep_nn_percent'] === null ? '-' : sprintf('%.2f', $row['distance_improvement_vs_sweep_nn_percent']),
            );
        }

        $lines[] = '';
        $lines[] = 'Distance improvement is calculated as ((baseline_distance - final_distance) / baseline_distance) * 100. Utilization and load balance are reported separately so the distance/load trade-off remains visible.';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new InvalidArgumentException("Cannot write CSV: {$path}");
        }

        if ($rows === []) {
            fclose($handle);

            return;
        }

        fputcsv($handle, array_keys($rows[0]));

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
    }

    private function resolveOutputPath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 || str_starts_with($path, '/') || str_starts_with($path, '\\\\')) {
            return $path;
        }

        return base_path($path);
    }

    private function databaseVersion(): ?string
    {
        try {
            return match (DB::connection()->getDriverName()) {
                'sqlite' => DB::selectOne('select sqlite_version() as version')->version ?? null,
                'mysql' => DB::selectOne('select version() as version')->version ?? null,
                'pgsql' => DB::selectOne('select version() as version')->version ?? null,
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    private function detectRamBytes(): ?int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = (string) @shell_exec('wmic computersystem get TotalPhysicalMemory /value');

            if (preg_match('/TotalPhysicalMemory=(\d+)/', $output, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        if (is_readable('/proc/meminfo')) {
            $meminfo = (string) file_get_contents('/proc/meminfo');

            if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $matches) === 1) {
                return (int) $matches[1] * 1024;
            }
        }

        $output = (string) @shell_exec('sysctl -n hw.memsize');
        $bytes = trim($output);

        return ctype_digit($bytes) ? (int) $bytes : null;
    }

    private function withoutOptimizerLogs(callable $callback): mixed
    {
        $logManager = app('log');
        $previousDefault = config('logging.default');

        config(['logging.default' => 'null']);

        if (method_exists($logManager, 'forgetChannel')) {
            $logManager->forgetChannel($previousDefault);
            $logManager->forgetChannel('null');
        }

        try {
            return $callback();
        } finally {
            config(['logging.default' => $previousDefault]);

            if (method_exists($logManager, 'forgetChannel')) {
                $logManager->forgetChannel($previousDefault);
                $logManager->forgetChannel('null');
            }
        }
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflectionMethod = $this->optimizerReflection->getMethod($method);

        return $reflectionMethod->invoke($this->optimizer, ...$arguments);
    }

    private function cloneStudents(Collection $students): Collection
    {
        return $students->map(fn (Student $student) => clone $student)->values();
    }

    private function validStudents(Collection $students): Collection
    {
        return $students->filter(fn (Student $student) => $this->hasValidCoordinates($student))->values();
    }

    private function hasValidCoordinates(Student $student): bool
    {
        return is_numeric($student->latitude)
            && is_numeric($student->longitude)
            && $student->latitude >= -90
            && $student->latitude <= 90
            && $student->longitude >= -180
            && $student->longitude <= 180;
    }

    private function initializeTripStudents(Collection $trips): array
    {
        $tripStudents = [];

        foreach ($trips as $trip) {
            $tripStudents[$trip->id] = [];
        }

        return $tripStudents;
    }

    private function normalizeSessionTime(?string $time): string
    {
        if (! $time) {
            return '';
        }

        $timestamp = strtotime($time);

        return $timestamp === false ? $time : date('H:i:s', $timestamp);
    }

    private function resultKey(string $method, string $direction, ?string $session): string
    {
        return $method.'|'.$this->contextKey($direction, $session);
    }

    private function contextKey(string $direction, ?string $session): string
    {
        return $direction.'|'.($session ?? '-');
    }

    private function mean(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    private function sampleStddev(array $values): float
    {
        $count = count($values);

        if ($count < 2) {
            return 0.0;
        }

        $mean = $this->mean($values);
        $sum = 0.0;

        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }

        return sqrt($sum / ($count - 1));
    }

    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
