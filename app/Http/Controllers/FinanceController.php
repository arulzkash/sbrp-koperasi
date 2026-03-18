<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class FinanceController extends Controller
{
    public function index()
    {
        if (Auth::user()?->role !== 'finance') {
            abort(403);
        }
        
        $students = Student::with('user')->orderBy('created_at', 'desc')->get();

        return Inertia::render('Finance/StudentList', [
            'students' => $students
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