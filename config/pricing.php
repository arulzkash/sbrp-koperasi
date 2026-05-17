<?php

return [
    'base_monthly_pp' => 250000,
    'rate_monthly_per_minute_pp' => 1000,
    'one_way_ratio' => 0.52,
    'fallback_speed_kmh' => 18,
    'distance_bands' => [
        ['upto' => 1000, 'rate' => 15],
        ['upto' => 2000, 'rate' => 50],
        ['upto' => 4000, 'rate' => 55],
        ['upto' => 10000, 'rate' => 13],
        ['upto' => null, 'rate' => 8],
    ],
];
