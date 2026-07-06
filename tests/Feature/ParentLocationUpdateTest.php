<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParentLocationUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_update_recalculates_distance_and_price_server_side(): void
    {
        $osrmDistanceMeters = 6214.8;
        $osrmDurationSeconds = 402.04;
        $this->fakeOsrmRoute($osrmDistanceMeters, $osrmDurationSeconds);

        $user = User::factory()->create(['role' => 'parent']);
        $student = Student::create([
            'user_id' => $user->id,
            'name' => 'Budi',
            'school_level' => 'SD',
            'class_room' => '4',
            'class_room_note' => 'B',
            'service_type' => 'pickup_only',
            'session_in' => '06:30:00',
            'session_out' => '13:00:00',
            'address_text' => 'Alamat lama',
            'latitude' => -6.81,
            'longitude' => 107.62,
            'distance_to_school_meters' => 1000,
            'price_per_month' => 100000,
            'status' => 'registered',
            'payment_status' => 'unpaid',
        ]);

        $pricingService = app(PricingService::class);
        $pricing = $pricingService->calculatePricing(
            $osrmDistanceMeters,
            $osrmDurationSeconds / 60,
            0,
        );
        $expectedPrice = $pricingService->calculateServicePrice($pricing['monthly_pp'], 'pickup_only');

        $response = $this->actingAs($user)->put(route('location.update', $student), [
            'address_text' => 'Alamat baru dari pin',
            'latitude' => '-6.82',
            'longitude' => '107.63',
            'distance' => '0',
            'price' => '0',
        ]);

        $response->assertRedirect('/dashboard');

        $student->refresh();

        $this->assertSame('Alamat baru dari pin', $student->address_text);
        $this->assertSame((int) round($osrmDistanceMeters), $student->distance_to_school_meters);
        $this->assertSame($expectedPrice, (int) $student->price_per_month);
        $this->assertNotSame(0, (int) $student->price_per_month);
    }

    private function fakeOsrmRoute(float $distanceMeters, float $durationSeconds): void
    {
        Http::fake([
            'router.project-osrm.org/*' => Http::response([
                'routes' => [
                    [
                        'distance' => $distanceMeters,
                        'duration' => $durationSeconds,
                    ],
                ],
            ], 200),
        ]);
    }
}