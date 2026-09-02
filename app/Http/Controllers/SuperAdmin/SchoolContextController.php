<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\ActiveSchoolContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SchoolContextController extends Controller
{
    public function select(Request $request, ActiveSchoolContext $context): RedirectResponse
    {
        $data = $request->validate([
            'school_id' => 'required|integer|exists:schools,id',
        ]);

        $school = School::findOrFail((int) $data['school_id']);
        $context->setActiveSchoolId($school->id);

        return redirect()
            ->route('school.reports.dashboard')
            ->with('success', "Active school context switched to \"{$school->name}\".");
    }

    public function clear(ActiveSchoolContext $context): RedirectResponse
    {
        $context->clearActiveSchoolId();

        return redirect()
            ->route('super-admin.dashboard')
            ->with('success', 'Exited school context.');
    }
}
