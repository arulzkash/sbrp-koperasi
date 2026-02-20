<?php

use App\Http\Controllers\Admin\RouteController;
use App\Http\Controllers\ProfileController;
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

    // Kalau yang login adalah ADMIN/MANAGER, lempar ke dashboard admin yang peta kemarin
    if ($user->role === 'manager') {
        return redirect('/admin/test-route');
    }

    // Kalau yang login ORANG TUA, ambil data anak-anaknya beserta info armada (jika ada)
    $children = Student::with('fleet')
        ->where('user_id', $user->id)
        ->get();

    return Inertia::render('Dashboard', [
        'children' => $children
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

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
    Route::put('/update-location/{id}', function (Request $request, $id) {
        $student = Student::where('user_id', Auth::id())->findOrFail($id);
        
        if ($student->status === 'active') {
            return back()->with('error', 'Rute sudah terkunci.');
        }

        $request->validate([
            'address_text' => 'required|string',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'distance' => 'required|numeric',
            'price' => 'required|numeric',
        ]);

        $student->update([
            'address_text' => $request->address_text,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'distance_to_school_meters' => $request->distance * 1000,
            'price_per_month' => $request->price,
        ]);

        return redirect('/dashboard')->with('success', 'Lokasi jemputan berhasil diupdate.');
    })->name('location.update');
});

Route::get('/admin/dashboard', [RouteController::class, 'dashboard'])->name('admin.dashboard');

Route::get('/admin/test-route', [RouteController::class, 'index']);
Route::post('/admin/test-route/generate', [RouteController::class, 'generate']);

require __DIR__ . '/auth.php';
