<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use App\Services\PricingService;
use App\Services\RouteDistanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

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
    public function store(
        Request $request,
        PricingService $pricingService,
        RouteDistanceService $routeDistanceService,
    ): RedirectResponse {
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
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'school_level' => ['nullable', Rule::in($validLevels), 'required_with:student_name,latitude,longitude'],
            'class_room' => ['nullable', Rule::in($validClasses), 'required_with:student_name,latitude,longitude'],
            'class_room_note' => 'nullable|string|max:50',
            'service_type' => 'nullable|in:full,pickup_only,dropoff_only',
            'session_in' => 'nullable|date_format:H:i',
            'session_out' => 'nullable|date_format:H:i',
        ]);

        $selectedClass = collect($classOptions->get($request->school_level, []))
            ->firstWhere('value', $request->class_room);

        if ($request->filled('student_name') && ! $selectedClass) {
            return back()->withErrors([
                'class_room' => 'Kelas tidak cocok dengan jenjang yang dipilih.',
            ])->withInput();
        }

        $resolvedSessionOut = isset($selectedClass['session_out'])
            ? date('H:i:s', strtotime($selectedClass['session_out']))
            : null;

        DB::transaction(function () use ($request, $resolvedSessionOut, $pricingService, $routeDistanceService) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'parent',
            ]);

            if ($request->latitude && $request->student_name) {
                $latitude = (float) $request->latitude;
                $longitude = (float) $request->longitude;
                $serviceType = $request->service_type ?? 'full';
                $routeEstimate = $routeDistanceService->estimateToSchool($latitude, $longitude);
                $pricing = $pricingService->calculatePricing(
                    $routeEstimate['distance_meters'],
                    $routeEstimate['duration_min'],
                    0,
                );

                Student::create([
                    'user_id' => $user->id,
                    'name' => $request->student_name,
                    'school_level' => $request->school_level ?? 'SD',
                    'class_room' => $request->class_room,
                    'class_room_note' => $request->class_room_note,
                    'service_type' => $serviceType,
                    'session_in' => $request->session_in,
                    'session_out' => $resolvedSessionOut,
                    'address_text' => 'Alamat dari Pin Map',
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'distance_to_school_meters' => (int) round($routeEstimate['distance_meters']),
                    'price_per_month' => $pricingService->calculateServicePrice($pricing['monthly_pp'], $serviceType),
                    'status' => 'registered',
                    'payment_status' => 'unpaid',
                ]);
            }

            Auth::login($user);
        });

        return redirect(route('dashboard', absolute: false));
    }
}
