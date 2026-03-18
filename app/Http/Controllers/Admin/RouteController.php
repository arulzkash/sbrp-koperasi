<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\RouteOptimizerService;
use App\Models\Fleet;
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

        // 2. Ambil SEMUA siswa yang Lunas (Algoritma Vue yang akan memfilternya per sesi)
        $students = Student::where('payment_status', 'paid')->get();

        return Inertia::render('Admin/Dashboard', [
            'fleets' => $fleets,
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
        return redirect()->back();
    }
}