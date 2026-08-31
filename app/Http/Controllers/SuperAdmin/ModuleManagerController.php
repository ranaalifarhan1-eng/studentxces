<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolModule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ModuleManagerController extends Controller
{
    const ALL_MODULES = [
        'students', 'staff', 'attendance', 'timetable', 'exams',
        'fees', 'library', 'transport', 'hostel', 'inventory',
        'homework', 'communication', 'reports', 'hr',
    ];

    public static function allModules(): array
    {
        return config('modules.canonical', self::ALL_MODULES);
    }

    public function index(Request $request): Response
    {
        $schools = School::select('id', 'name', 'status')->orderBy('name')->get();

        $selectedSchool = null;
        $modules = [];
        $allModules = self::allModules();

        if ($request->school_id) {
            $selectedSchool = School::findOrFail($request->school_id);
            $overrides = SchoolModule::where('school_id', $request->school_id)
                ->pluck('is_enabled', 'module_slug')
                ->toArray();

            foreach ($allModules as $slug) {
                $modules[] = [
                    'slug'       => $slug,
                    'label'      => ucwords(str_replace('_', ' ', $slug)),
                    'is_enabled' => $overrides[$slug] ?? true, // default enabled
                ];
            }
        }

        return Inertia::render('SuperAdmin/ModuleManager/Index', [
            'schools'        => $schools,
            'selectedSchool' => $selectedSchool,
            'modules'        => $modules,
            'allModules'     => $allModules,
            'filters'        => $request->only(['school_id']),
        ]);
    }

    public function toggle(Request $request): RedirectResponse
    {
        $allModules = self::allModules();
        $data = $request->validate([
            'school_id'   => 'required|integer|exists:schools,id',
            'module_slug' => 'required|string|in:' . implode(',', $allModules),
            'is_enabled'  => 'required|boolean',
        ]);

        SchoolModule::updateOrCreate(
            ['school_id' => $data['school_id'], 'module_slug' => $data['module_slug']],
            ['is_enabled' => $data['is_enabled']],
        );

        return back()->with('success', 'Module ' . ($data['is_enabled'] ? 'enabled' : 'disabled') . '.');
    }

    public function bulkSave(Request $request): RedirectResponse
    {
        $allModules = self::allModules();
        $data = $request->validate([
            'school_id' => 'required|integer|exists:schools,id',
            'modules'   => 'required|array',
            'modules.*' => 'boolean',
        ]);

        foreach ($data['modules'] as $slug => $enabled) {
            if (!in_array($slug, $allModules)) continue;
            SchoolModule::updateOrCreate(
                ['school_id' => $data['school_id'], 'module_slug' => $slug],
                ['is_enabled' => (bool) $enabled],
            );
        }

        return back()->with('success', 'Module settings saved.');
    }
}
