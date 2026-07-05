<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class RouteDistanceService
{
    private const SCHOOL_LAT = -6.826864390637824;
    private const SCHOOL_LNG = 107.63886429303408;

    /**
     * @return array{distance_meters: float, duration_min: float, source: string}
     */
    public function estimateToSchool(float $latitude, float $longitude): array
    {
        return $this->estimateRoute($latitude, $longitude, self::SCHOOL_LAT, self::SCHOOL_LNG);
    }

    /**
     * @return array{distance_meters: float, duration_min: float, source: string}
     */
    private function estimateRoute(float $originLat, float $originLng, float $destinationLat, float $destinationLng): array
    {
        $route = $this->estimateWithOsrm($originLat, $originLng, $destinationLat, $destinationLng);

        if ($route !== null) {
            return $route;
        }

        $distanceMeters = $this->haversine($originLat, $originLng, $destinationLat, $destinationLng) * 1000;

        return [
            'distance_meters' => $distanceMeters,
            'duration_min' => $this->estimateDurationMin($distanceMeters),
            'source' => 'fallback',
        ];
    }

    /**
     * @return array{distance_meters: float, duration_min: float, source: string}|null
     */
    private function estimateWithOsrm(float $originLat, float $originLng, float $destinationLat, float $destinationLng): ?array
    {
        $baseUrl = rtrim((string) config('services.osrm.base_url', 'https://router.project-osrm.org'), '/');
        $coordinates = implode(';', [
            $originLng . ',' . $originLat,
            $destinationLng . ',' . $destinationLat,
        ]);

        try {
            $response = Http::timeout((int) config('services.osrm.timeout', 5))
                ->get("{$baseUrl}/route/v1/driving/{$coordinates}", [
                    'overview' => 'false',
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $route = $response->json('routes.0');

        if (! is_array($route) || ! isset($route['distance'], $route['duration'])) {
            return null;
        }

        return [
            'distance_meters' => (float) $route['distance'],
            'duration_min' => ((float) $route['duration']) / 60,
            'source' => 'osrm',
        ];
    }

    private function estimateDurationMin(float $distanceMeters): float
    {
        $speedKmh = config('pricing.fallback_speed_kmh', 18);

        return (($distanceMeters / 1000) / $speedKmh) * 60;
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
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
