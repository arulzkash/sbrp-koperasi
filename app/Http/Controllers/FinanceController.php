<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class FinanceController extends Controller
{
    public function index(Request $request)
    {
        if (Auth::user()?->role !== 'finance') {
            abort(403);
        }

        $search = $request->string('search')->toString();
        $paymentStatus = $request->string('payment_status')->toString();
        $serviceStatus = $request->string('service_status')->toString();

        $students = Student::with('user')
            ->when($search, function ($query, $search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('name', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($paymentStatus, function ($query, $paymentStatus) {
                $query->where('payment_status', $paymentStatus);
            })
            ->when($serviceStatus, function ($query, $serviceStatus) {
                if ($serviceStatus === 'pending_payment') {
                    $query->where('payment_status', 'unpaid');
                }

                if ($serviceStatus === 'waiting_route') {
                    $query->where('payment_status', 'paid')
                        ->where('status', 'registered');
                }

                if ($serviceStatus === 'active') {
                    $query->where('status', 'active');
                }
            })
            ->orderBy('created_at', 'desc')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Finance/StudentList', [
            'students' => $students,
            'filters' => [
                'search' => $search,
                'payment_status' => $paymentStatus,
                'service_status' => $serviceStatus,
            ],
            'stats' => [
                'unpaid' => Student::where('payment_status', 'unpaid')->count(),
                'paid' => Student::where('payment_status', 'paid')->count(),
                'active' => Student::where('status', 'active')->count(),
            ],
        ]);
    }


    public function markAsPaid(Request $request, $id)
    {
        if (Auth::user()?->role !== 'finance') {
            abort(403);
        }

        $student = Student::findOrFail($id);
        
        $student->update([
            'payment_status' => 'paid'
        ]);

        return redirect()->back()->with('success', 'Status bayaran sudah di update menjadi lunas');
    }
}