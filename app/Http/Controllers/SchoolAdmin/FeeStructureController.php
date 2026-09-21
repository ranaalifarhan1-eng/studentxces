<?php

namespace App\Http\Controllers\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\SchoolClass;
use App\Rules\SchoolExists;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FeeStructureController extends Controller
{
    public function index(Request $request)
    {
        $sid = $this->getSchoolId();

        $structures = FeeStructure::with(['schoolClass:id,name', 'feeCategory:id,name,type'])
            ->when($request->class_id,      fn ($q) => $q->where('class_id', $request->class_id))
            ->when($request->category_id,   fn ($q) => $q->where('fee_category_id', $request->category_id))
            ->when($request->academic_year, fn ($q) => $q->where('academic_year', $request->academic_year))
            ->orderBy('academic_year', 'desc')
            ->orderByRaw('(SELECT numeric_name FROM classes WHERE classes.id = fee_structures.class_id) ASC')
            ->paginate(25)
            ->withQueryString();

        $currentYear = \App\Models\AcademicYear::where('school_id', $sid)->where('is_current', true)->value('name')
            ?? \App\Models\AcademicYear::where('school_id', $sid)->orderByDesc('start_date')->value('name')
            ?? '2025-2026';

        return Inertia::render('SchoolAdmin/Fees/Structures', [
            'structures'   => $structures,
            'classes'      => SchoolClass::where('school_id', $sid)->orderBy('numeric_name')->get(['id', 'name']),
            'categories'   => FeeCategory::where('school_id', $sid)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'filters'      => $request->only('class_id', 'category_id', 'academic_year'),
            'currentYear'  => $currentYear,
        ]);
    }

    public function store(Request $request)
    {
        $sid = $this->getSchoolId();

        $data = $request->validate([
            'class_id'                 => ['required', SchoolExists::make('classes', 'id', $sid)],
            'fee_category_id'          => ['required', SchoolExists::make('fee_categories', 'id', $sid)],
            'academic_year'            => 'required|string|max:20',
            'amount'                   => 'required|numeric|min:0',
            'due_date'                 => 'nullable|date',
            'frequency'                => 'required|in:monthly,quarterly,annual,one_time',
            'description'              => 'nullable|string|max:255',
            'is_active'                => 'boolean',
            'is_optional'              => 'boolean',
            'admission_voucher_policy' => 'nullable|in:required,optional,excluded',
        ]);

        FeeStructure::create(array_merge($data, [
            'school_id'                => $sid,
            'is_active'                => $data['is_active'] ?? true,
            'is_optional'              => $data['is_optional'] ?? false,
            'admission_voucher_policy' => $data['admission_voucher_policy'] ?? 'optional',
        ]));

        return back()->with('success', 'Fee structure created.');
    }

    public function update(Request $request, FeeStructure $feeStructure)
    {
        $sid = $this->getSchoolId();

        $data = $request->validate([
            'class_id'                 => ['required', SchoolExists::make('classes', 'id', $sid)],
            'fee_category_id'          => ['required', SchoolExists::make('fee_categories', 'id', $sid)],
            'academic_year'            => 'required|string|max:20',
            'amount'                   => 'required|numeric|min:0',
            'due_date'                 => 'nullable|date',
            'frequency'                => 'required|in:monthly,quarterly,annual,one_time',
            'description'              => 'nullable|string|max:255',
            'is_active'                => 'boolean',
            'is_optional'              => 'boolean',
            'admission_voucher_policy' => 'nullable|in:required,optional,excluded',
        ]);

        $feeStructure->update($data);

        return back()->with('success', 'Fee structure updated.');
    }

    public function destroy(FeeStructure $feeStructure)
    {
        $feeStructure->delete();
        return back()->with('success', 'Fee structure deleted.');
    }

    public function copy(Request $request, FeeStructure $feeStructure)
    {
        $sid = $this->getSchoolId();
        if ($feeStructure->school_id !== $sid) {
            abort(403);
        }

        $data = $request->validate([
            'target_class_ids'   => 'required|array|min:1',
            'target_class_ids.*' => ['required', SchoolExists::make('classes', 'id', $sid)],
        ]);

        $copiedCount = 0;
        foreach ($data['target_class_ids'] as $targetClassId) {
            if ((int) $targetClassId === (int) $feeStructure->class_id) {
                continue;
            }

            FeeStructure::firstOrCreate(
                [
                    'school_id'       => $sid,
                    'class_id'        => (int) $targetClassId,
                    'fee_category_id' => $feeStructure->fee_category_id,
                    'academic_year'   => $feeStructure->academic_year,
                ],
                [
                    'amount'                   => $feeStructure->amount,
                    'due_date'                 => $feeStructure->due_date,
                    'frequency'                => $feeStructure->frequency,
                    'description'              => $feeStructure->description,
                    'is_active'                => $feeStructure->is_active,
                    'is_optional'              => $feeStructure->is_optional,
                    'admission_voucher_policy' => $feeStructure->admission_voucher_policy ?? 'optional',
                ]
            );
            $copiedCount++;
        }

        return back()->with('success', "Fee structure copied to {$copiedCount} class(es).");
    }
}
