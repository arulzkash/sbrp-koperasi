<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FinanceController extends Controller
{
    public function index()
    {
        $students = Student::with('user')->orderBy('created_at', 'desc')->get();

        return Inertia::render('Finance/StudentList', [
            'students' => $students
        ]);
    }

    public function markAsPaid(Request $request, $id)
    {
        $student = Student::findOrFail($id);
        
        $student->update([
            'payment_status' => 'paid'
        ]);

        return redirect()->back()->with('success', 'Status bayaran sudah di update menjadi lunas');
    }
}