<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

use App\Models\Student;
use Illuminate\Support\Facades\DB;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:' . User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            // Validasi data siswa (optional/nullable kalau dia daftar tanpa lewat peta)
            'student_name' => 'nullable|string|max:255',
            'latitude' => 'nullable',
            'longitude' => 'nullable',
        ]);

        // Pakai Database Transaction biar aman (Kalau gagal satu, gagal semua)
        DB::transaction(function () use ($request) {

            // 1. Buat User (Orang Tua)
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'parent', // Pastikan role-nya Parent
            ]);

            // 2. Jika ada data lokasi, langsung buatkan Data Siswa
            if ($request->latitude && $request->student_name) {
                Student::create([
                    'user_id' => $user->id, // Link ke ortu yang barusan dibuat
                    'name' => $request->student_name,
                    'school_level' => $request->school_level ?? 'SD',
                    'address_text' => 'Alamat dari Pin Map', // Nanti bisa diupdate
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'distance_to_school_meters' => ($request->distance * 1000), // Simpan dalam meter
                    'price_per_month' => $request->price_estimasi,
                    'status' => 'registered', // Status awal: Registered
                    'payment_status' => 'unpaid', // Belum bayar
                ]);
            }

            // Login otomatis setelah daftar
            Auth::login($user);
        });

        // Redirect ke Dashboard (Nanti kita buat Dashboard Ortu)
        return redirect(route('dashboard', absolute: false));
    }
}
