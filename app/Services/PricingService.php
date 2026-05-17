<?php

namespace App\Services;

class PricingService
{
    public function config(): array
    {
        return config('pricing');
    }

    public function calculatePricing(float $distanceMeters, float $durationMin, float $accessSurcharge = 0): array
    {
        $config = $this->config();
        $distanceCharge = $this->calculateDistanceCharge($distanceMeters);
        $durationCharge = $durationMin * $config['rate_monthly_per_minute_pp'];

        $monthlyPPRaw = $config['base_monthly_pp'] + $distanceCharge + $durationCharge + $accessSurcharge;
        $monthlyPP = (int) ceil($monthlyPPRaw / 1000) * 1000;
        $monthlyOneWay = (int) ceil(($monthlyPP * $config['one_way_ratio']) / 1000) * 1000;

        return [
            'monthly_pp' => $monthlyPP,
            'monthly_one_way' => $monthlyOneWay,
            'distance_charge' => $distanceCharge,
            'duration_charge' => $durationCharge,
            'estimated_trip_fare' => (int) ceil($monthlyOneWay / 22 / 500) * 500,
        ];
    }

    public function calculateServicePrice(float $monthlyPrice, string $serviceType): int
    {
        if ($serviceType === 'full') {
            return (int) ceil($monthlyPrice / 1000) * 1000;
        }

        $config = $this->config();

        return (int) ceil(($monthlyPrice * $config['one_way_ratio']) / 1000) * 1000;
    }

    private function calculateDistanceCharge(float $meters): float
    {
        $config = $this->config();
        $total = 0;
        $previousLimit = 0;

        foreach ($config['distance_bands'] as $band) {
            $upperLimit = $band['upto'];

            $bandMeters = $upperLimit === null
                ? max(0, $meters - $previousLimit)
                : max(0, min($meters, $upperLimit) - $previousLimit);

            $total += $bandMeters * $band['rate'];
            $previousLimit = $upperLimit ?? $previousLimit;

            if ($upperLimit !== null && $meters <= $upperLimit) {
                break;
            }
        }

        return $total;
    }
}
