<?php

use App\Http\Controllers\Admin\RouteController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\ProfileController;
use App\Services\PricingService;
use App\Services\RouteDistanceService;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Models\Student;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

// Route::get('/', function () {
//     return Inertia::render('Welcome', [
//         'canLogin' => Route::has('login'),
//         'canRegister' => Route::has('register'),
//         'laravelVersion' => Application::VERSION,
//         'phpVersion' => PHP_VERSION,
//     ]);
// });

// Halaman Depan (Landing Page - Cek Harga)
Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ]);
});

Route::get('/dashboard', function () {
    $user = Auth::user();

    if ($user->role === 'manager') {
        return redirect()->route('admin.dashboard');
    }

    if ($user->role === 'finance') {
        return redirect()->route('finance.students');
    }

    $children = Student::with([
            'morningFleet',
            'afternoonFleet',
            'morningFleetTrip',
            'afternoonFleetTrip',
        ])
        ->where('user_id', $user->id)
        ->get();

    return Inertia::render('Dashboard', [
        'children' => $children
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::match(['get', 'post'], '/pricing/estimate', [PricingController::class, 'estimate'])->name('pricing.estimate');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // 1. Route untuk MENAMPILKAN halaman Peta Edit
    Route::get('/edit-location/{id}', function ($id) {
        // Cari data anak yang mau diedit, pastikan milik user yang login
        $student = Student::where('user_id', Auth::id())->findOrFail($id);

        // Proteksi: Kalau statusnya sudah active, tolak aksesnya
        if ($student->status === 'active') {
            return redirect('/dashboard')->with('error', 'Rute sudah terkunci.');
        }

        return Inertia::render('Parent/EditLocation', [
            'student' => $student
        ]);
    })->name('location.edit');

    // 2. Route untuk MENYIMPAN perubahan dari Peta
    Route::put('/update-location/{id}', function (
        Request $request,
        $id,
        PricingService $pricingService,
        RouteDistanceService $routeDistanceService,
    ) {
        $student = Student::where('user_id', Auth::id())->findOrFail($id);

        if ($student->status === 'active') {
            return back()->with('error', 'Rute sudah terkunci.');
        }

        $validated = $request->validate([
            'address_text' => 'required|string',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $latitude = (float) $validated['latitude'];
        $longitude = (float) $validated['longitude'];
        $serviceType = $student->service_type ?? 'full';
        $routeEstimate = $routeDistanceService->estimateToSchool($latitude, $longitude);
        $pricing = $pricingService->calculatePricing(
            $routeEstimate['distance_meters'],
            $routeEstimate['duration_min'],
            0,
        );

        $student->update([
            'address_text' => $validated['address_text'],
            'latitude' => $latitude,
            'longitude' => $longitude,
            'distance_to_school_meters' => (int) round($routeEstimate['distance_meters']),
            'price_per_month' => $pricingService->calculateServicePrice($pricing['monthly_pp'], $serviceType),
        ]);

        return redirect('/dashboard')->with('success', 'Lokasi jemputan berhasil diupdate.');
    })->name('location.update');

    Route::prefix('finance')->group(function () {
        Route::get('/students', [FinanceController::class, 'index'])->name('finance.students');
        Route::put('/students/{id}/pay', [FinanceController::class, 'markAsPaid'])->name('finance.students.pay');
    });
});

Route::get('/admin/dashboard', [RouteController::class, 'index'])->name('admin.dashboard');
Route::post('/admin/dashboard/generate/morning', [RouteController::class, 'generateMorning'])
    ->name('admin.routes.generate.morning');
Route::post('/admin/dashboard/generate/afternoon', [RouteController::class, 'generateAfternoon'])
    ->name('admin.routes.generate.afternoon');

require __DIR__ . '/auth.php';
