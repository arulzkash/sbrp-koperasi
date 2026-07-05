<?php

namespace Tests\Feature;

use App\Services\PricingService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PricingEstimateTest extends TestCase
{
    public function test_pricing_estimate_uses_osrm_distance_when_coordinates_are_provided(): void
    {
        $osrmDistanceMeters = 6214.8;
        $osrmDurationSeconds = 402.04;

        Http::fake([
            'router.project-osrm.org/*' => Http::response([
                'routes' => [
                    [
                        'distance' => $osrmDistanceMeters,
                        'duration' => $osrmDurationSeconds,
                    ],
                ],
            ], 200),
        ]);

        $pricingService = app(PricingService::class);
        $pricing = $pricingService->calculatePricing($osrmDistanceMeters, $osrmDurationSeconds / 60, 0);

        $response = $this->getJson(route('pricing.estimate', [
            'latitude' => -6.82,
            'longitude' => 107.63,
            'distance_meters' => 0,
            'duration_min' => 0,
            'service_type' => 'full',
        ]));

        $response->assertOk()
            ->assertJsonPath('distance_meters', $osrmDistanceMeters)
            ->assertJsonPath('duration_min', $osrmDurationSeconds / 60)
            ->assertJsonPath('route_source', 'osrm')
            ->assertJsonPath('monthly_pp', $pricing['monthly_pp']);
    }
}
