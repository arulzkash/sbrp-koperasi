<?php

namespace Tests\Feature\Auth;

use App\Models\Student;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $this->fakeOsrmRoute(6214.8, 402.04);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'student_name' => 'Budi',
            'school_level' => 'SD',
            'class_room' => '4',
            'class_room_note' => 'B',
            'service_type' => 'full',
            'session_in' => '06:30',
            'latitude' => '-6.82',
            'longitude' => '107.63',
            'distance' => '1.5',
            'price_estimasi' => '250000',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_registration_recalculates_student_price_from_osrm_server_side(): void
    {
        $osrmDistanceMeters = 6214.8;
        $osrmDurationSeconds = 402.04;
        $this->fakeOsrmRoute($osrmDistanceMeters, $osrmDurationSeconds);

        $pricingService = app(PricingService::class);
        $pricing = $pricingService->calculatePricing(
            $osrmDistanceMeters,
            $osrmDurationSeconds / 60,
            0,
        );
        $expectedPrice = $pricingService->calculateServicePrice($pricing['monthly_pp'], 'full');

        $response = $this->post('/register', [
            'name' => 'Manipulated User',
            'email' => 'manipulated@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'student_name' => 'Budi',
            'school_level' => 'SD',
            'class_room' => '4',
            'class_room_note' => 'B',
            'service_type' => 'full',
            'session_in' => '06:30',
            'latitude' => '-6.82',
            'longitude' => '107.63',
            'distance' => '0',
            'price_estimasi' => '0',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));

        $student = Student::firstOrFail();

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
