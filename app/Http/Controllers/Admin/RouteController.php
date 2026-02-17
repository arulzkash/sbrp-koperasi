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

    // FUNGSI BACA (Sangat Ringan)
    public function index(Request $request)
    {
        // Ambil data Armada beserta Siswanya langsung dari Database
        $fleets = Fleet::with(['students' => function($query) {
            $query->orderBy('route_order', 'asc');
        }])->get();

        // Format ulang datanya sedikit agar cocok dengan format Vue sebelumnya
        $routesData = $fleets->map(function($fleet) {
            return [
                'fleet_id' => $fleet->id,
                'fleet_name' => $fleet->name,
                'capacity' => $fleet->capacity,
                'current_load' => $fleet->students->count(),
                'students' => $fleet->students->map(function($student) {
                    return [
                        'id' => $student->id,
                        'name' => $student->name,
                        'lat' => $student->latitude,
                        'lng' => $student->longitude,
                        'distance_from_base' => '...', // Bisa dihitung manual atau hide dulu
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