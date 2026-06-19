<?php

namespace Tests\Feature;

use App\Models\Fleet;
use App\Models\FleetTrip;
use App\Models\Student;
use App\Models\User;
use App\Services\RouteOptimizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class RouteOptimizerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_morning_optimizer_assigns_valid_pickup_students_and_skips_invalid_or_dropoff_only_students(): void
    {
        $fleet = $this->createFleet(capacity: 2);
        $trip = $this->createTrip($fleet, 'morning', '06:00:00');

        $validFull = $this->createStudent([
            'name' => 'Valid Full',
            'service_type' => 'full',
            'latitude' => -6.82000000,
            'longitude' => 107.63000000,
        ]);
        $validPickupOnly = $this->createStudent([
            'name' => 'Valid Pickup',
            'service_type' => 'pickup_only',
            'latitude' => -6.82100000,
            'longitude' => 107.63100000,
        ]);
        $dropoffOnly = $this->createStudent([
            'name' => 'Dropoff Only',
            'service_type' => 'dropoff_only',
            'latitude' => -6.82200000,
            'longitude' => 107.63200000,
        ]);
        $invalidCoordinate = $this->createStudent([
            'name' => 'Invalid Coordinate',
            'service_type' => 'full',
            'latitude' => 120,
            'longitude' => 107.63300000,
        ]);

        app(RouteOptimizerService::class)->optimize();

        $validFull->refresh();
        $validPickupOnly->refresh();
        $dropoffOnly->refresh();
        $invalidCoordinate->refresh();

        $this->assertSame($fleet->id, $validFull->morning_fleet_id);
        $this->assertSame($trip->id, $validFull->morning_fleet_trip_id);
        $this->assertNotNull($validFull->morning_route_order);

        $this->assertSame($fleet->id, $validPickupOnly->morning_fleet_id);
        $this->assertSame($trip->id, $validPickupOnly->morning_fleet_trip_id);
        $this->assertNotNull($validPickupOnly->morning_route_order);

        $this->assertNull($dropoffOnly->morning_fleet_id);
        $this->assertNull($dropoffOnly->morning_fleet_trip_id);
        $this->assertNull($invalidCoordinate->morning_fleet_id);
        $this->assertNull($invalidCoordinate->morning_fleet_trip_id);

        $this->assertSame(2, Student::where('morning_fleet_trip_id', $trip->id)->count());
    }

    public function test_morning_optimizer_does_not_force_equal_capacity_balance(): void
    {
        $nearFleet = $this->createFleet(capacity: 4, latitude: -6.82000000, longitude: 107.63000000);
        $farFleet = $this->createFleet(capacity: 4, latitude: -6.90000000, longitude: 107.70000000);
        $nearTrip = $this->createTrip($nearFleet, 'morning', '06:00:00');
        $farTrip = $this->createTrip($farFleet, 'morning', '06:00:00');

        foreach (range(1, 3) as $index) {
            $this->createStudent([
                'name' => "Near Student {$index}",
                'latitude' => -6.82000000 - ($index * 0.0001),
                'longitude' => 107.63000000 + ($index * 0.0001),
            ]);
        }

        app(RouteOptimizerService::class)->optimize();

        $this->assertSame(3, Student::where('morning_fleet_trip_id', $nearTrip->id)->count());
        $this->assertSame(0, Student::where('morning_fleet_trip_id', $farTrip->id)->count());
    }

    public function test_morning_optimizer_leaves_students_unassigned_when_capacity_is_insufficient(): void
    {
        $fleet = $this->createFleet(capacity: 1);
        $trip = $this->createTrip($fleet, 'morning', '06:00:00');

        $this->createStudent(['name' => 'First', 'latitude' => -6.82000000, 'longitude' => 107.63000000]);
        $this->createStudent(['name' => 'Second', 'latitude' => -6.82100000, 'longitude' => 107.63100000]);

        app(RouteOptimizerService::class)->optimize();

        $this->assertSame(1, Student::where('morning_fleet_trip_id', $trip->id)->count());
        $this->assertSame(1, Student::whereNull('morning_fleet_trip_id')->count());
    }

    public function test_afternoon_assignment_still_uses_existing_session_trip_logic(): void
    {
        $fleet = $this->createFleet(capacity: 2);
        $trip = $this->createTrip($fleet, 'afternoon', '13:00:00');
        $student = $this->createStudent([
            'service_type' => 'dropoff_only',
            'session_out' => '13:00:00',
            'latitude' => -6.82000000,
            'longitude' => 107.63000000,
        ]);

        app(RouteOptimizerService::class)->optimize();

        $student->refresh();

        $this->assertNull($student->morning_fleet_trip_id);
        $this->assertSame($trip->id, $student->afternoon_fleet_trip_id);
        $this->assertSame($fleet->id, $student->afternoon_fleet_id);
        $this->assertSame(1, $student->afternoon_route_order);
    }

    public function test_afternoon_optimizer_assigns_full_and_dropoff_only_students_to_matching_session_trips(): void
    {
        $fleet = $this->createFleet(capacity: 2);
        $trip = $this->createTrip($fleet, 'afternoon', '13:30:00');
        $fullStudent = $this->createStudent([
            'service_type' => 'full',
            'session_out' => '13:30:00',
            'latitude' => -6.82000000,
            'longitude' => 107.63000000,
        ]);
        $dropoffOnlyStudent = $this->createStudent([
            'service_type' => 'dropoff_only',
            'session_out' => '13:30:00',
            'latitude' => -6.82100000,
            'longitude' => 107.63100000,
        ]);

        app(RouteOptimizerService::class)->optimize();

        $fullStudent->refresh();
        $dropoffOnlyStudent->refresh();

        $this->assertSame($trip->id, $fullStudent->afternoon_fleet_trip_id);
        $this->assertSame($trip->id, $dropoffOnlyStudent->afternoon_fleet_trip_id);
    }

    public function test_afternoon_optimizer_does_not_assign_pickup_only_students(): void
    {
        $fleet = $this->createFleet(capacity: 2);
        $this->createTrip($fleet, 'afternoon', '13:00:00');
        $student = $this->createStudent([
            'service_type' => 'pickup_only',
            'session_out' => '13:00:00',
            'latitude' => -6.82000000,
            'longitude' => 107.63000000,
        ]);

        app(RouteOptimizerService::class)->optimize();

        $student->refresh();

        $this->assertNull($student->afternoon_fleet_id);
        $this->assertNull($student->afternoon_fleet_trip_id);
        $this->assertNull($student->afternoon_route_order);
    }

    public function test_afternoon_optimizer_does_not_assign_students_to_different_departure_time(): void
    {
        $fleet = $this->createFleet(capacity: 2);
        $this->createTrip($fleet, 'afternoon', '14:30:00');
        $student = $this->createStudent([
            'service_type' => 'dropoff_only',
            'session_out' => '13:00:00',
            'latitude' => -6.82000000,
            'longitude' => 107.63000000,
        ]);

        app(RouteOptimizerService::class)->optimize();

        $student->refresh();

        $this->assertNull($student->afternoon_fleet_id);
        $this->assertNull($student->afternoon_fleet_trip_id);
    }

    public function test_afternoon_optimizer_respects_capacity_and_leaves_overflow_unassigned(): void
    {
        $fleet = $this->createFleet(capacity: 1);
        $trip = $this->createTrip($fleet, 'afternoon', '13:00:00');

        $this->createStudent(['service_type' => 'dropoff_only', 'session_out' => '13:00:00', 'latitude' => -6.82000000, 'longitude' => 107.63000000]);
        $this->createStudent(['service_type' => 'dropoff_only', 'session_out' => '13:00:00', 'latitude' => -6.82100000, 'longitude' => 107.63100000]);
        $this->createStudent(['service_type' => 'dropoff_only', 'session_out' => '13:00:00', 'latitude' => -6.82200000, 'longitude' => 107.63200000]);

        app(RouteOptimizerService::class)->optimize();

        $this->assertSame(1, Student::where('afternoon_fleet_trip_id', $trip->id)->count());
        $this->assertSame(2, Student::whereNull('afternoon_fleet_trip_id')->count());
    }

    public function test_afternoon_optimizer_leaves_invalid_coordinate_students_unassigned(): void
    {
        $fleet = $this->createFleet(capacity: 2);
        $this->createTrip($fleet, 'afternoon', '13:00:00');
        $student = $this->createStudent([
            'service_type' => 'dropoff_only',
            'session_out' => '13:00:00',
            'latitude' => 120,
            'longitude' => 107.63000000,
        ]);

        app(RouteOptimizerService::class)->optimize();

        $student->refresh();

        $this->assertNull($student->afternoon_fleet_id);
        $this->assertNull($student->afternoon_fleet_trip_id);
    }

    public function test_afternoon_insertion_cost_keeps_far_outlier_separate_from_coherent_route(): void
    {
        $firstFleet = $this->createFleet(capacity: 3);
        $secondFleet = $this->createFleet(capacity: 3);
        $firstTrip = $this->createTrip($firstFleet, 'afternoon', '13:00:00');
        $secondTrip = $this->createTrip($secondFleet, 'afternoon', '13:00:00');

        $westOutlier = $this->createStudent([
            'service_type' => 'dropoff_only',
            'session_out' => '13:00:00',
            'latitude' => -6.82680000,
            'longitude' => 107.56000000,
        ]);
        $eastStudentOne = $this->createStudent([
            'service_type' => 'dropoff_only',
            'session_out' => '13:00:00',
            'latitude' => -6.82690000,
            'longitude' => 107.68000000,
        ]);
        $eastStudentTwo = $this->createStudent([
            'service_type' => 'dropoff_only',
            'session_out' => '13:00:00',
            'latitude' => -6.82710000,
            'longitude' => 107.68100000,
        ]);

        app(RouteOptimizerService::class)->optimize();

        $westOutlier->refresh();
        $eastStudentOne->refresh();
        $eastStudentTwo->refresh();

        $this->assertNotNull($westOutlier->afternoon_fleet_trip_id);
        $this->assertContains($westOutlier->afternoon_fleet_trip_id, [$firstTrip->id, $secondTrip->id]);
        $this->assertSame($eastStudentOne->afternoon_fleet_trip_id, $eastStudentTwo->afternoon_fleet_trip_id);
        $this->assertNotSame($westOutlier->afternoon_fleet_trip_id, $eastStudentOne->afternoon_fleet_trip_id);
    }

    public function test_generate_morning_preserves_existing_afternoon_assignment(): void
    {
        $fleet = $this->createFleet(capacity: 2);
        $morningTrip = $this->createTrip($fleet, 'morning', '06:00:00');
        $afternoonTrip = $this->createTrip($fleet, 'afternoon', '13:00:00');
        $student = $this->createStudent();

        $service = app(RouteOptimizerService::class);
        $service->optimize();

        $afternoonTrip->update(['is_active' => false]);
        $morningTrip->update(['is_active' => false]);
        $service->optimizeMorning();

        $student->refresh();

        $this->assertNull($student->morning_fleet_trip_id);
        $this->assertSame($afternoonTrip->id, $student->afternoon_fleet_trip_id);
        $this->assertSame('active', $student->status);
    }

    public function test_generate_afternoon_preserves_existing_morning_assignment(): void
    {
        $fleet = $this->createFleet(capacity: 2);
        $morningTrip = $this->createTrip($fleet, 'morning', '06:00:00');
        $afternoonTrip = $this->createTrip($fleet, 'afternoon', '13:00:00');
        $student = $this->createStudent();

        $service = app(RouteOptimizerService::class);
        $service->optimize();

        $morningTrip->update(['is_active' => false]);
        $afternoonTrip->update(['is_active' => false]);
        $service->optimizeAfternoon();

        $student->refresh();

        $this->assertSame($morningTrip->id, $student->morning_fleet_trip_id);
        $this->assertNull($student->afternoon_fleet_trip_id);
        $this->assertSame('active', $student->status);
    }
    public function test_morning_distance_estimation_includes_final_leg_to_school(): void
    {
        $service = app(RouteOptimizerService::class);
        $fleet = new Fleet([
            'base_latitude' => -6.82000000,
            'base_longitude' => 107.63000000,
        ]);

        $reflection = new ReflectionClass($service);
        $estimate = $reflection->getMethod('estimateMorningRouteDistance');
        $calculateDistance = $reflection->getMethod('calculateDistance');

        $estimatedDistance = $estimate->invoke($service, [], $fleet);
        $baseToSchoolDistance = $calculateDistance->invoke(
            $service,
            $fleet->base_latitude,
            $fleet->base_longitude,
            RouteOptimizerService::SCHOOL_LAT,
            RouteOptimizerService::SCHOOL_LNG,
        );

        $this->assertEqualsWithDelta($baseToSchoolDistance, $estimatedDistance, 0.000001);
    }

    public function test_morning_rebalance_moves_nearby_student_from_overfilled_to_underfilled_trip(): void
    {
        $sourceFleet = $this->createFleet(capacity: 4, latitude: -6.82000000, longitude: 107.63000000);
        $destinationFleet = $this->createFleet(capacity: 4, latitude: -6.82050000, longitude: 107.63050000);
        $sourceTrip = $this->createTrip($sourceFleet, 'morning', '06:00:00');
        $destinationTrip = $this->createTrip($destinationFleet, 'morning', '06:00:00');
        $sourceStudents = [
            $this->createStudent(['latitude' => -6.82000000, 'longitude' => 107.63000000]),
            $this->createStudent(['latitude' => -6.82010000, 'longitude' => 107.63010000]),
            $this->createStudent(['latitude' => -6.82020000, 'longitude' => 107.63020000]),
            $this->createStudent(['latitude' => -6.82030000, 'longitude' => 107.63030000]),
        ];
        $destinationStudents = [
            $this->createStudent(['latitude' => -6.82060000, 'longitude' => 107.63060000]),
        ];

        $result = $this->rebalanceMorningTrips([
            $sourceTrip->id => $sourceStudents,
            $destinationTrip->id => $destinationStudents,
        ], collect([$sourceTrip, $destinationTrip]));

        $this->assertCount(3, $result[$sourceTrip->id]);
        $this->assertCount(2, $result[$destinationTrip->id]);
    }

    public function test_morning_rebalance_does_not_move_when_extra_distance_is_too_large(): void
    {
        $sourceFleet = $this->createFleet(capacity: 4, latitude: -6.82000000, longitude: 107.63000000);
        $destinationFleet = $this->createFleet(capacity: 4, latitude: -6.90000000, longitude: 107.70000000);
        $sourceTrip = $this->createTrip($sourceFleet, 'morning', '06:00:00');
        $destinationTrip = $this->createTrip($destinationFleet, 'morning', '06:00:00');
        $sourceStudents = [
            $this->createStudent(['latitude' => -6.82000000, 'longitude' => 107.63000000]),
            $this->createStudent(['latitude' => -6.82010000, 'longitude' => 107.63010000]),
            $this->createStudent(['latitude' => -6.82020000, 'longitude' => 107.63020000]),
            $this->createStudent(['latitude' => -6.82030000, 'longitude' => 107.63030000]),
        ];
        $destinationStudents = [
            $this->createStudent(['latitude' => -6.90000000, 'longitude' => 107.70000000]),
        ];

        $result = $this->rebalanceMorningTrips([
            $sourceTrip->id => $sourceStudents,
            $destinationTrip->id => $destinationStudents,
        ], collect([$sourceTrip, $destinationTrip]));

        $this->assertCount(4, $result[$sourceTrip->id]);
        $this->assertCount(1, $result[$destinationTrip->id]);
    }

    public function test_morning_rebalance_does_not_exceed_destination_capacity(): void
    {
        $sourceFleet = $this->createFleet(capacity: 4, latitude: -6.82000000, longitude: 107.63000000);
        $destinationFleet = $this->createFleet(capacity: 1, latitude: -6.82050000, longitude: 107.63050000);
        $sourceTrip = $this->createTrip($sourceFleet, 'morning', '06:00:00');
        $destinationTrip = $this->createTrip($destinationFleet, 'morning', '06:00:00');
        $sourceStudents = [
            $this->createStudent(['latitude' => -6.82000000, 'longitude' => 107.63000000]),
            $this->createStudent(['latitude' => -6.82010000, 'longitude' => 107.63010000]),
            $this->createStudent(['latitude' => -6.82020000, 'longitude' => 107.63020000]),
            $this->createStudent(['latitude' => -6.82030000, 'longitude' => 107.63030000]),
        ];
        $destinationStudents = [
            $this->createStudent(['latitude' => -6.82060000, 'longitude' => 107.63060000]),
        ];

        $result = $this->rebalanceMorningTrips([
            $sourceTrip->id => $sourceStudents,
            $destinationTrip->id => $destinationStudents,
        ], collect([$sourceTrip, $destinationTrip]));

        $this->assertCount(4, $result[$sourceTrip->id]);
        $this->assertCount(1, $result[$destinationTrip->id]);
    }

    public function test_morning_rebalance_does_not_make_source_trip_underfilled(): void
    {
        $sourceFleet = $this->createFleet(capacity: 2, latitude: -6.82000000, longitude: 107.63000000);
        $destinationFleet = $this->createFleet(capacity: 4, latitude: -6.82050000, longitude: 107.63050000);
        $sourceTrip = $this->createTrip($sourceFleet, 'morning', '06:00:00');
        $destinationTrip = $this->createTrip($destinationFleet, 'morning', '06:00:00');
        $sourceStudents = [
            $this->createStudent(['latitude' => -6.82000000, 'longitude' => 107.63000000]),
            $this->createStudent(['latitude' => -6.82010000, 'longitude' => 107.63010000]),
        ];
        $destinationStudents = [
            $this->createStudent(['latitude' => -6.82060000, 'longitude' => 107.63060000]),
        ];

        $result = $this->rebalanceMorningTrips([
            $sourceTrip->id => $sourceStudents,
            $destinationTrip->id => $destinationStudents,
        ], collect([$sourceTrip, $destinationTrip]));

        $this->assertCount(2, $result[$sourceTrip->id]);
        $this->assertCount(1, $result[$destinationTrip->id]);
    }

    private function rebalanceMorningTrips(array $tripStudents, $trips): array
    {
        $service = app(RouteOptimizerService::class);
        $reflection = new ReflectionClass($service);
        $rebalance = $reflection->getMethod('rebalanceMorningUnderfilledTrips');

        return $rebalance->invoke($service, $tripStudents, $trips);
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
