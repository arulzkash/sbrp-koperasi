<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\RouteOptimizerService;
use App\Models\Fleet;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RouteController extends Controller
{
    protected $optimizer;

    public function __construct(RouteOptimizerService $optimizer)
    {
        $this->optimizer = $optimizer;
    }

    public function index(Request $request)
    {
        $fleets = Fleet::with(['students' => function($query) {
            $query->orderBy('route_order', 'asc');
        }])->get();

        $routesData = $fleets->map(function($fleet) {
            return [
                'fleet_id' => $fleet->id,
                'fleet_name' => $fleet->name,
                'capacity' => $fleet->capacity,
                'base_lat' => $fleet->base_latitude,
                'base_lng' => $fleet->base_longitude,
                'current_load' => $fleet->students->count(),
                'students' => $fleet->students->map(function($student) use ($fleet) {
                    
                    // Hitung jarak asli dari Base ke Siswa (Haversine)
                    $earthRadius = 6371;
                    $dLat = deg2rad($student->latitude - $fleet->base_latitude);
                    $dLon = deg2rad($student->longitude - $fleet->base_longitude);
                    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($fleet->base_latitude)) * cos(deg2rad($student->latitude)) * sin($dLon/2) * sin($dLon/2);
                    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
                    $distanceKm = round($earthRadius * $c, 2);

                    return [
                        'id' => $student->id,
                        'name' => $student->name,
                        'lat' => $student->latitude,
                        'lng' => $student->longitude,
                        'distance_from_base' => $distanceKm . ' KM',
                        'route_order' => $student->route_order
                    ];
                })
            ];
        });

        return Inertia::render('Admin/Dashboard', [
            'routesData' => $routesData,
        ]);
    }

    // FUNGSI TULIS (Dijalankan saat klik tombol)
    public function generate(Request $request)
    {
        // Jalankan Algoritma
        $includeUnpaid = $request->input('include_unpaid', false);
        $this->optimizer->optimize($includeUnpaid);

        // Redirect/Kembali ke halaman map
        return redirect()->back();
    }
}