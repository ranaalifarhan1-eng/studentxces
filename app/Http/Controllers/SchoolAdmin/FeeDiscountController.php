<?php

namespace App\Http\Controllers\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentFeeDiscount;
use App\Rules\SchoolExists;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FeeDiscountController extends Controller
{
    public function index(Request $request)
    {
        $sid = $this->getSchoolId();

        $discounts = StudentFeeDiscount::with([
            'student:id,first_name,last_name,admission_no,class_id',
            'student.schoolClass:id,name',
            'feeCategory:id,name',
            'academicYear:id,name',
        ])
            ->where('school_id', $sid)
            ->when($request->class_id, fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('class_id', $request->class_id)))
            ->when($request->status !== null, fn ($q) => $q->where('is_active', $request->status === 'active'))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('SchoolAdmin/Fees/Discounts', [
            'discounts'     => $discounts,
            'classes'       => SchoolClass::where('school_id', $sid)->orderBy('numeric_name')->get(['id', 'name']),
            'categories'    => FeeCategory::where('school_id', $sid)->where('is_active', true)->get(['id', 'name']),
            'academicYears' => AcademicYear::where('school_id', $sid)->orderByDesc('start_date')->get(['id', 'name', 'is_current']),
            'filters'       => $request->only('class_id', 'status'),
        ]);
    }

    public function store(Request $request)
    {
        $sid = $this->getSchoolId();

        $data = $request->validate([
            'student_id'       => ['required', SchoolExists::make('students', 'id', $sid)],
            'fee_category_id'  => ['nullable', SchoolExists::make('fee_categories', 'id', $sid)],
            'academic_year_id' => ['nullable', SchoolExists::make('academic_years', 'id', $sid)],
            'title'            => 'required|string|max:100',
            'type'             => 'required|in:percentage,fixed',
            'value'            => 'required|numeric|min:0.01',
            'is_active'        => 'boolean',
        ]);

        StudentFeeDiscount::create(array_merge($data, [
            'school_id' => $sid,
            'is_active' => $data['is_active'] ?? true,
        ]));

        return back()->with('success', 'Student fee discount registered successfully.');
    }

    public function update(Request $request, StudentFeeDiscount $studentFeeDiscount)
    {
        $sid = $this->getSchoolId();
        if ($studentFeeDiscount->school_id !== $sid) {
            abort(403);
        }

        $data = $request->validate([
            'fee_category_id'  => ['nullable', SchoolExists::make('fee_categories', 'id', $sid)],
            'academic_year_id' => ['nullable', SchoolExists::make('academic_years', 'id', $sid)],
            'title'            => 'required|string|max:100',
            'type'             => 'required|in:percentage,fixed',
            'value'            => 'required|numeric|min:0.01',
            'is_active'        => 'boolean',
        ]);

        $studentFeeDiscount->update($data);

        return back()->with('success', 'Fee discount updated successfully.');
    }

    public function destroy(StudentFeeDiscount $studentFeeDiscount)
    {
        $sid = $this->getSchoolId();
        if ($studentFeeDiscount->school_id !== $sid) {
            abort(403);
        }

        $studentFeeDiscount->delete();

        return back()->with('success', 'Fee discount removed successfully.');
    }
}
