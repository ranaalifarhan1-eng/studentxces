<?php

namespace App\Http\Controllers\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Rules\SchoolExists;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SubjectController extends Controller
{
    public function index(Request $request): Response
    {
        $subjects = Subject::with('schoolClass')
            ->orderBy('name')
            ->get();

        $classes = SchoolClass::orderBy('numeric_name')->orderBy('name')->get(['id', 'name']);

        $user = $request->user();
        $canDelete = $user ? $user->can('subjects.delete') : false;

        return Inertia::render('SchoolAdmin/Subjects/Index', [
            'subjects'  => $subjects,
            'classes'   => $classes,
            'canDelete' => $canDelete,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $sid = $this->getSchoolId();

        $data = $request->validate([
            'class_id'   => ['required', SchoolExists::make('classes', 'id', $sid)],
            'name'       => 'required|string|max:150',
            'code'       => 'nullable|string|max:20',
            'type'       => 'required|in:theory,practical',
            'full_marks' => 'nullable|integer|min:1',
            'pass_marks' => 'nullable|integer|min:1',
        ]);

        Subject::create($data);

        return back()->with('success', 'Subject created.');
    }

    public function update(Request $request, Subject $subject): RedirectResponse
    {
        $sid = $this->getSchoolId();

        $data = $request->validate([
            'class_id'   => ['required', SchoolExists::make('classes', 'id', $sid)],
            'name'       => 'required|string|max:150',
            'code'       => 'nullable|string|max:20',
            'type'       => 'required|in:theory,practical',
            'full_marks' => 'nullable|integer|min:1',
            'pass_marks' => 'nullable|integer|min:1',
        ]);

        $subject->update($data);

        return back()->with('success', 'Subject updated.');
    }

    public function destroy(Subject $subject): RedirectResponse
    {
        $sid = $this->getSchoolId();
        if ((int) $subject->school_id !== (int) $sid) {
            abort(403, 'Unauthorized.');
        }

        $user = auth()->user();
        if (! $user || ! $user->can('subjects.delete')) {
            abort(403, 'You do not have permission to delete subjects.');
        }

        // Guard against deleting subjects with active/history-dependent records
        $hasTimetables = DB::table('timetables')
            ->where('school_id', $sid)
            ->where('subject_id', $subject->id)
            ->exists();

        $hasMarks = DB::table('marks')
            ->where('school_id', $sid)
            ->where('subject_id', $subject->id)
            ->exists();

        $hasHomework = DB::table('homework')
            ->where('school_id', $sid)
            ->where('subject_id', $subject->id)
            ->whereNull('deleted_at')
            ->exists();

        $hasLessonPlans = DB::table('lesson_plans')
            ->where('school_id', $sid)
            ->where('subject_id', $subject->id)
            ->whereNull('deleted_at')
            ->exists();

        $hasSyllabi = DB::table('syllabi')
            ->where('school_id', $sid)
            ->where('subject_id', $subject->id)
            ->whereNull('deleted_at')
            ->exists();

        $hasOnlineClasses = DB::table('online_classes')
            ->where('school_id', $sid)
            ->where('subject_id', $subject->id)
            ->whereNull('deleted_at')
            ->exists();

        if ($hasTimetables || $hasMarks || $hasHomework || $hasLessonPlans || $hasSyllabi || $hasOnlineClasses) {
            $msg = "Cannot delete subject \"{$subject->name}\" because academic records (timetable, marks, homework, lesson plans, or syllabus) are associated with it.";
            return back()->with('error', $msg)->withErrors(['error' => $msg]);
        }

        $subject->delete();

        return back()->with('success', 'Subject deleted.');
    }
}
