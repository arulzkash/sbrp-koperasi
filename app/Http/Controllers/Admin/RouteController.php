<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\RouteOptimizerService;
use App\Models\Fleet;
use App\Models\FleetTrip;
use App\Models\Student;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class RouteController extends Controller
{
    protected $optimizer;

    public function __construct(RouteOptimizerService $optimizer)
    {
        $this->optimizer = $optimizer;
    }

    public function index(Request $request)
    {
        if (Auth::user()?->role !== 'manager') {
            abort(403);
        }

        // 1. Ambil semua Armada yang aktif
        $fleets = Fleet::where('is_active', true)->get();

        // 2. Ambil semua trip aktif agar dashboard bisa menampilkan rute per rit/trip
        $fleetTrips = FleetTrip::with('fleet')
            ->where('is_active', true)
            ->whereHas('fleet', fn ($query) => $query->where('is_active', true))
            ->orderBy('direction')
            ->orderBy('departure_time')
            ->orderBy('fleet_id')
            ->orderBy('trip_order')
            ->get();

        // 3. Ambil SEMUA siswa yang Lunas (Algoritma Vue yang akan memfilternya per sesi)
        $students = Student::with(['morningFleetTrip', 'afternoonFleetTrip'])
            ->where('payment_status', 'paid')
            ->get();

        return Inertia::render('Admin/Dashboard', [
            'fleets' => $fleets,
            'fleetTrips' => $fleetTrips,
            'students' => $students,
        ]);
    }

    // FUNGSI GENERATE
    public function generate(Request $request)
    {
        if (Auth::user()?->role !== 'manager') {
            abort(403);
        }
        
        // Jalankan Algoritma (Logika Include Unpaid sudah kita buang sesuai kesepakatan)
        $this->optimizer->optimize();

        // Redirect/Kembali ke halaman map
        return redirect()->back()->with('success', 'Generate rute berhasil dijalankan.');
    }
}
