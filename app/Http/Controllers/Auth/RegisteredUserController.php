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
use Illuminate\Validation\Rule;
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
        return Inertia::render('Auth/Register', [
            'classOptions' => config('student_schedule.levels'),
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $classOptions = collect(config('student_schedule.levels'));
        $validLevels = $classOptions->keys()->all();
        $validClasses = $classOptions
            ->flatMap(fn (array $options) => collect($options)->pluck('value'))
            ->unique()
            ->values()
            ->all();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:' . User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'student_name' => 'nullable|string|max:255',
            'latitude' => 'nullable',
            'longitude' => 'nullable',
            'school_level' => ['nullable', Rule::in($validLevels), 'required_with:student_name,latitude,longitude'],
            'class_room' => ['nullable', Rule::in($validClasses), 'required_with:student_name,latitude,longitude'],
            'class_room_note' => 'nullable|string|max:50',
            'service_type' => 'nullable|in:full,pickup_only,dropoff_only',
            'session_in' => 'nullable|date_format:H:i',
            'session_out' => 'nullable|date_format:H:i',
        ]);

        $selectedClass = collect($classOptions->get($request->school_level, []))
            ->firstWhere('value', $request->class_room);

        if ($request->filled('student_name') && !$selectedClass) {
            return back()->withErrors([
                'class_room' => 'Kelas tidak cocok dengan jenjang yang dipilih.',
            ])->withInput();
        }

        $resolvedSessionOut = $selectedClass['session_out'] ?? null;

        DB::transaction(function () use ($request, $resolvedSessionOut) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'parent',
            ]);

            if ($request->latitude && $request->student_name) {
                Student::create([
                    'user_id' => $user->id,
                    'name' => $request->student_name,
                    'school_level' => $request->school_level ?? 'SD',
                    'class_room' => $request->class_room,
                    'class_room_note' => $request->class_room_note,
                    'service_type' => $request->service_type ?? 'full',
                    'session_in' => $request->session_in,
                    'session_out' => $resolvedSessionOut,
                    'address_text' => 'Alamat dari Pin Map',
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'distance_to_school_meters' => ($request->distance * 1000),
                    'price_per_month' => $request->price_estimasi,
                    'status' => 'registered',
                    'payment_status' => 'unpaid',
                ]);
            }

            Auth::login($user);
        });

        return redirect(route('dashboard', absolute: false));
    }
}
