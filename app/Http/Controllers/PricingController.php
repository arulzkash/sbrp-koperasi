<?php

namespace App\Http\Controllers;

use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PricingController extends Controller
{
    public function estimate(Request $request, PricingService $pricingService): JsonResponse
    {
        $validated = $request->validate([
            'distance_meters' => 'nullable|numeric|min:0',
            'duration_min' => 'nullable|numeric|min:0',
            'access_surcharge' => 'nullable|numeric|min:0',
            'base_monthly_price' => 'nullable|numeric|min:0',
            'service_type' => 'nullable|in:full,pickup_only,dropoff_only',
        ]);

        $serviceType = $validated['service_type'] ?? 'full';

        if (array_key_exists('base_monthly_price', $validated) && !empty($validated['base_monthly_price'])) {
            $monthlyPP = (int) $validated['base_monthly_price'];

            return response()->json([
                'monthly_pp' => $pricingService->calculateServicePrice($monthlyPP, 'full'),
                'monthly_one_way' => $pricingService->calculateServicePrice($monthlyPP, 'dropoff_only'),
                'service_price' => $pricingService->calculateServicePrice($monthlyPP, $serviceType),
                'service_type' => $serviceType,
            ]);
        }

        $pricing = $pricingService->calculatePricing(
            (float) ($validated['distance_meters'] ?? 0),
            (float) ($validated['duration_min'] ?? 0),
            (float) ($validated['access_surcharge'] ?? 0),
        );

        return response()->json([
            'monthly_pp' => $pricing['monthly_pp'],
            'monthly_one_way' => $pricing['monthly_one_way'],
            'service_price' => $pricingService->calculateServicePrice($pricing['monthly_pp'], $serviceType),
            'service_type' => $serviceType,
            'estimated_trip_fare' => $pricing['estimated_trip_fare'],
            'distance_charge' => $pricing['distance_charge'],
            'duration_charge' => $pricing['duration_charge'],
        ]);
    }
}
