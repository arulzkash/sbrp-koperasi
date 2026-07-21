<?php

namespace Tests\Feature;

use App\Models\Fleet;
use App\Models\FleetTrip;
use App\Models\Student;
use App\Models\User;
use App\Services\RouteBenchmarkService;
use App\Services\RouteOptimizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

class RouteBenchmarkCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['logging.default' => 'null']);
    }

    public function test_route_benchmark_command_is_read_only_and_exports_files(): void
    {
        $this->seedBenchmarkFixture();
        $student = Student::firstOrFail();
        $morningTrip = FleetTrip::where('direction', 'morning')->firstOrFail();
        $afternoonTrip = FleetTrip::where('direction', 'afternoon')->firstOrFail();
        $student->update([
            'morning_fleet_id' => $morningTrip->fleet_id,
            'morning_fleet_trip_id' => $morningTrip->id,
            'morning_route_order' => 7,
            'afternoon_fleet_id' => $afternoonTrip->fleet_id,
            'afternoon_fleet_trip_id' => $afternoonTrip->id,
            'afternoon_route_order' => 8,
            'status' => 'active',
        ]);

        $beforeSignature = $this->assignmentSignature();
        $beforeCount = Student::count();
        $output = sys_get_temp_dir();
        $jsonBefore = count(File::glob($output.DIRECTORY_SEPARATOR.'route-benchmark-*.json'));
        $csvBefore = count(File::glob($output.DIRECTORY_SEPARATOR.'route-benchmark-*.csv'));
        $markdownBefore = count(File::glob($output.DIRECTORY_SEPARATOR.'route-benchmark-*.md'));

        $this->artisan('route:benchmark', [
            '--methods' => 'sweep_nn,final',
            '--runs' => 2,
            '--direction' => 'all',
            '--output' => $output,
        ])->assertExitCode(0);

        $this->assertSame($beforeCount, Student::count());
        $this->assertSame($beforeSignature, $this->assignmentSignature());
        $this->assertGreaterThan($jsonBefore, count(File::glob($output.DIRECTORY_SEPARATOR.'route-benchmark-*.json')));
        $this->assertGreaterThan($csvBefore, count(File::glob($output.DIRECTORY_SEPARATOR.'route-benchmark-*.csv')));
        $this->assertGreaterThan($markdownBefore, count(File::glob($output.DIRECTORY_SEPARATOR.'route-benchmark-*.md')));
    }

    public function test_route_benchmark_no_export_and_options_work(): void
    {
        $this->seedBenchmarkFixture();
        $output = sys_get_temp_dir();
        $jsonBefore = count(File::glob($output.DIRECTORY_SEPARATOR.'route-benchmark-*.json'));

        $this->artisan('route:benchmark', [
            '--methods' => 'sweep_nn',
            '--runs' => 1,
            '--direction' => 'morning',
            '--output' => $output,
            '--no-export' => true,
        ])->assertExitCode(0);

        $this->assertSame($jsonBefore, count(File::glob($output.DIRECTORY_SEPARATOR.'route-benchmark-*.json')));
    }

    public function test_benchmark_methods_obey_capacity_sessions_and_duplicate_constraints(): void
    {
        $this->seedBenchmarkFixture();

        $result = app(RouteBenchmarkService::class)->run(['sweep_nn', 'final'], 2, 'all');

        foreach ($result['rows'] as $row) {
            $this->assertSame(0, $row['capacity_violations']);
            $this->assertSame(0, $row['duplicate_students']);
            $this->assertSame(0, $row['wrong_service_students']);
            $this->assertSame(0, $row['inactive_trip_or_fleet_students']);

            if ($row['direction'] === 'afternoon') {
                $this->assertSame(0, $row['wrong_afternoon_session_students']);
            }
        }
    }

    public function test_distance_metrics_use_fleet_base_school_and_haversine_direction_rules(): void
    {
        $fleet = $this->createFleet(capacity: 2, latitude: -6.82000000, longitude: 107.63000000);
        $this->createTrip($fleet, 'morning', '06:00:00');
        $this->createTrip($fleet, 'afternoon', '13:00:00');
        $student = $this->createStudent([
            'latitude' => -6.82100000,
            'longitude' => 107.63100000,
        ]);

        $result = app(RouteBenchmarkService::class)->run(['sweep_nn'], 1, 'all');
        $morning = collect($result['rows'])->firstWhere('direction', 'morning');
        $afternoon = collect($result['rows'])->firstWhere('direction', 'afternoon');
        $distance = $this->distanceHelper();

        $expectedMorning = $distance($fleet->base_latitude, $fleet->base_longitude, $student->latitude, $student->longitude)
            + $distance($student->latitude, $student->longitude, RouteOptimizerService::SCHOOL_LAT, RouteOptimizerService::SCHOOL_LNG);
        $expectedAfternoon = $distance(RouteOptimizerService::SCHOOL_LAT, RouteOptimizerService::SCHOOL_LNG, $student->latitude, $student->longitude);

        $this->assertEqualsWithDelta($expectedMorning, $morning['total_haversine_distance_km'], 0.000001);
        $this->assertEqualsWithDelta($expectedAfternoon, $afternoon['total_haversine_distance_km'], 0.000001);
    }

    public function test_final_benchmark_solution_matches_production_optimizer_pipeline(): void
    {
        $this->seedBenchmarkFixture();

        $benchmarkResult = app(RouteBenchmarkService::class)->run(['final'], 1, 'all');
        app(RouteOptimizerService::class)->optimize();

        $expectedMorning = $this->solutionAssignments($benchmarkResult, 'final|morning|-');
        $actualMorning = Student::query()
            ->whereNotNull('morning_fleet_trip_id')
            ->orderBy('morning_fleet_trip_id')
            ->orderBy('morning_route_order')
            ->get()
            ->groupBy('morning_fleet_trip_id')
            ->map(fn ($students) => $students->pluck('id')->values()->all())
            ->all();

        $expectedAfternoon = $this->solutionAssignments($benchmarkResult, 'final|afternoon|13:00:00');
        $actualAfternoon = Student::query()
            ->whereNotNull('afternoon_fleet_trip_id')
            ->orderBy('afternoon_fleet_trip_id')
            ->orderBy('afternoon_route_order')
            ->get()
            ->groupBy('afternoon_fleet_trip_id')
            ->map(fn ($students) => $students->pluck('id')->values()->all())
            ->all();

        $this->assertSame($expectedMorning, $actualMorning);
        $this->assertSame($expectedAfternoon, $actualAfternoon);
    }

    public function test_negative_scenarios_are_reported_without_assignments_or_crashes(): void
    {
        $summaries = [
            $this->runInsufficientCapacityScenario(),
            $this->runInvalidCoordinatesScenario(),
            $this->runUnavailableTripsScenario(),
        ];

        $this->assertSame([
            [
                'scenario' => 'Kapasitas tidak cukup',
                'data_condition' => '3 siswa eligible, 1 rit pagi aktif kapasitas 2',
                'students_processed' => 3,
                'students_allocated' => 2,
                'students_unallocated' => 1,
                'capacity_violations' => 0,
                'status' => 'passed',
            ],
            [
                'scenario' => 'Koordinat tidak valid',
                'data_condition' => '2 siswa eligible dengan koordinat di luar rentang valid',
                'students_processed' => 2,
                'students_allocated' => 0,
                'students_unallocated' => 2,
                'capacity_violations' => 0,
                'status' => 'passed',
            ],
            [
                'scenario' => 'Rit tidak tersedia',
                'data_condition' => '2 siswa pulang eligible sesi 13:00 tanpa rit aktif sesi tersebut',
                'students_processed' => 2,
                'students_allocated' => 0,
                'students_unallocated' => 2,
                'capacity_violations' => 0,
                'status' => 'passed',
            ],
        ], $summaries);
    }

    private function runInsufficientCapacityScenario(): array
    {
        $this->resetBenchmarkFixture();
        config(['logging.default' => 'null']);

        $fleet = $this->createFleet(capacity: 2);
        $this->createTrip($fleet, 'morning', '06:00:00');
        $this->createStudent(['service_type' => 'pickup_only', 'latitude' => -6.82100000, 'longitude' => 107.63100000]);
        $this->createStudent(['service_type' => 'pickup_only', 'latitude' => -6.82200000, 'longitude' => 107.63200000]);
        $this->createStudent(['service_type' => 'pickup_only', 'latitude' => -6.82300000, 'longitude' => 107.63300000]);
        $beforeSignature = $this->assignmentSignature();

        $row = $this->benchmarkRow('morning');

        $this->assertSame($beforeSignature, $this->assignmentSignature());
        $this->assertSame(3, Student::whereNull('morning_fleet_trip_id')->count());
        $this->assertSame(3, Student::count());
        $this->assertSame(3, $row['students_processed']);
        $this->assertSame(2, $row['students_allocated']);
        $this->assertSame(1, $row['students_unallocated']);
        $this->assertSame(0, $row['capacity_violations']);

        return $this->scenarioSummary(
            'Kapasitas tidak cukup',
            '3 siswa eligible, 1 rit pagi aktif kapasitas 2',
            $row,
        );
    }

    private function runInvalidCoordinatesScenario(): array
    {
        $this->resetBenchmarkFixture();
        config(['logging.default' => 'null']);

        $fleet = $this->createFleet(capacity: 2);
        $this->createTrip($fleet, 'morning', '06:00:00');
        $this->createStudent(['service_type' => 'pickup_only', 'latitude' => 120, 'longitude' => 107.63100000]);
        $this->createStudent(['service_type' => 'pickup_only', 'latitude' => -6.82200000, 'longitude' => 200]);
        $beforeSignature = $this->assignmentSignature();

        $row = $this->benchmarkRow('morning');

        $this->assertSame($beforeSignature, $this->assignmentSignature());
        $this->assertSame(2, Student::whereNull('morning_fleet_trip_id')->count());
        $this->assertSame(2, $row['students_processed']);
        $this->assertSame(0, $row['students_allocated']);
        $this->assertSame(2, $row['students_unallocated']);
        $this->assertSame(0, $row['capacity_violations']);

        return $this->scenarioSummary(
            'Koordinat tidak valid',
            '2 siswa eligible dengan koordinat di luar rentang valid',
            $row,
        );
    }

    private function runUnavailableTripsScenario(): array
    {
        $this->resetBenchmarkFixture();
        config(['logging.default' => 'null']);

        $fleet = $this->createFleet(capacity: 2);
        $this->createTrip($fleet, 'afternoon', '14:00:00');
        $this->createStudent(['service_type' => 'dropoff_only', 'session_out' => '13:00:00', 'latitude' => -6.82100000, 'longitude' => 107.63100000]);
        $this->createStudent(['service_type' => 'dropoff_only', 'session_out' => '13:00:00', 'latitude' => -6.82200000, 'longitude' => 107.63200000]);
        $beforeSignature = $this->assignmentSignature();

        $row = $this->benchmarkRow('afternoon');

        $this->assertSame($beforeSignature, $this->assignmentSignature());
        $this->assertSame(2, Student::whereNull('afternoon_fleet_trip_id')->count());
        $this->assertSame(2, $row['students_processed']);
        $this->assertSame(0, $row['students_allocated']);
        $this->assertSame(2, $row['students_unallocated']);
        $this->assertSame(0, $row['capacity_violations']);

        return $this->scenarioSummary(
            'Rit tidak tersedia',
            '2 siswa pulang eligible sesi 13:00 tanpa rit aktif sesi tersebut',
            $row,
        );
    }

    private function resetBenchmarkFixture(): void
    {
        Student::query()->delete();
        FleetTrip::query()->delete();
        Fleet::query()->delete();
        User::query()->delete();
    }

    private function benchmarkRow(string $direction): array
    {
        $result = app(RouteBenchmarkService::class)->run(['final'], 1, $direction);

        return $result['rows'][0];
    }

    private function scenarioSummary(string $scenario, string $dataCondition, array $row): array
    {
        return [
            'scenario' => $scenario,
            'data_condition' => $dataCondition,
            'students_processed' => $row['students_processed'],
            'students_allocated' => $row['students_allocated'],
            'students_unallocated' => $row['students_unallocated'],
            'capacity_violations' => $row['capacity_violations'],
            'status' => 'passed',
        ];
    }

    private function seedBenchmarkFixture(): void
    {
        $firstFleet = $this->createFleet(capacity: 2, latitude: -6.82000000, longitude: 107.63000000);
        $secondFleet = $this->createFleet(capacity: 2, latitude: -6.83000000, longitude: 107.64000000);
        $this->createTrip($firstFleet, 'morning', '06:00:00');
        $this->createTrip($secondFleet, 'morning', '06:05:00');
        $this->createTrip($firstFleet, 'afternoon', '13:00:00');
        $this->createTrip($secondFleet, 'afternoon', '13:00:00');

        $this->createStudent(['service_type' => 'full', 'latitude' => -6.82100000, 'longitude' => 107.63100000]);
        $this->createStudent(['service_type' => 'pickup_only', 'latitude' => -6.82200000, 'longitude' => 107.63200000]);
        $this->createStudent(['service_type' => 'dropoff_only', 'latitude' => -6.82300000, 'longitude' => 107.63300000]);
        $this->createStudent(['service_type' => 'full', 'latitude' => -6.82400000, 'longitude' => 107.63400000]);
        $this->createStudent(['service_type' => 'full', 'latitude' => 120, 'longitude' => 107.63500000]);
    }

    private function solutionAssignments(array $benchmarkResult, string $key): array
    {
        return collect($benchmarkResult['solutions'][$key]['trips'])
            ->filter(fn (array $trip) => $trip['route_student_ids'] !== [])
            ->mapWithKeys(fn (array $trip) => [$trip['trip_id'] => $trip['route_student_ids']])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function assignmentSignature(): array
    {
        return Student::query()
            ->orderBy('id')
            ->get([
                'id',
                'morning_fleet_id',
                'morning_fleet_trip_id',
                'morning_route_order',
                'afternoon_fleet_id',
                'afternoon_fleet_trip_id',
                'afternoon_route_order',
                'status',
            ])
            ->map(fn (Student $student) => $student->getAttributes())
            ->all();
    }

    private function distanceHelper(): callable
    {
        $service = app(RouteOptimizerService::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('calculateDistance');

        return fn ($lat1, $lng1, $lat2, $lng2) => $method->invoke($service, $lat1, $lng1, $lat2, $lng2);
    }

    private function createFleet(
        int $capacity,
        float $latitude = -6.82000000,
        float $longitude = 107.63000000,
    ): Fleet {
        return Fleet::create([
            'name' => 'Fleet '.uniqid(),
            'driver_name' => 'Driver',
            'license_plate' => 'D 1234 XX',
            'vehicle_type' => 'Car',
            'capacity' => $capacity,
            'base_latitude' => $latitude,
            'base_longitude' => $longitude,
            'base_address' => 'Base',
            'is_active' => true,
        ]);
    }

    private function createTrip(Fleet $fleet, string $direction, string $departureTime): FleetTrip
    {
        return FleetTrip::create([
            'fleet_id' => $fleet->id,
            'direction' => $direction,
            'departure_time' => $departureTime,
            'trip_order' => 1,
            'is_active' => true,
        ]);
    }

    private function createStudent(array $attributes = []): Student
    {
        $user = User::factory()->create();

        return Student::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Student',
            'school_level' => 'SD',
            'class_room' => '1',
            'class_room_note' => 'A',
            'service_type' => 'full',
            'session_in' => '06:30:00',
            'session_out' => '13:00:00',
            'address_text' => 'Address',
            'latitude' => -6.82000000,
            'longitude' => 107.63000000,
            'distance_to_school_meters' => 1000,
            'price_per_month' => 100000,
            'status' => 'registered',
            'payment_status' => 'paid',
        ], $attributes));
    }
}
