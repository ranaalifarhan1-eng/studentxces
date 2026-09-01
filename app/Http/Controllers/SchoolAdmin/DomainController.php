<?php

namespace App\Http\Controllers\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolDomain;
use App\Services\DomainService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class DomainController extends Controller
{
    public function __construct(
        protected DomainService $domainService
    ) {}

    public function index(): Response
    {
        $sid = $this->getSchoolId();

        $domains = SchoolDomain::where('school_id', $sid)
            ->orderByDesc('is_primary')
            ->orderBy('type')
            ->orderBy('created_at')
            ->get();

        return Inertia::render('SchoolAdmin/Settings/Domains', [
            'domains'            => $domains,
            'cname_target'       => config('tenancy.cname_target', 'tenants.edusystem.store'),
            'tenant_base_domain' => config('tenancy.tenant_base_domain', 'edusystem.store'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $sid = $this->getSchoolId();
        $school = School::findOrFail($sid);

        $data = $request->validate([
            'hostname' => ['required', 'string', 'max:253'],
        ]);

        try {
            $this->domainService->addCustomDomain($school, $data['hostname']);
            return back()->with('success', 'Custom domain added successfully. Please configure your DNS records.');
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['hostname' => $e->getMessage()]);
        }
    }

    public function verify(SchoolDomain $domain): RedirectResponse
    {
        $sid = $this->getSchoolId();
        if ($domain->school_id !== $sid) {
            abort(403, 'Unauthorized access to school domain.');
        }

        $verified = $this->domainService->verifyDomain($domain);

        if ($verified) {
            return back()->with('success', "Domain '{$domain->hostname}' verified and activated successfully.");
        }

        return back()->with('error', "DNS verification failed for '{$domain->hostname}'. Please verify your CNAME or TXT challenge records.");
    }

    public function makePrimary(SchoolDomain $domain): RedirectResponse
    {
        $sid = $this->getSchoolId();
        if ($domain->school_id !== $sid) {
            abort(403, 'Unauthorized access to school domain.');
        }

        try {
            $this->domainService->setPrimary($domain);
            return back()->with('success', "Primary domain updated to '{$domain->hostname}'.");
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function destroy(SchoolDomain $domain): RedirectResponse
    {
        $sid = $this->getSchoolId();
        if ($domain->school_id !== $sid) {
            abort(403, 'Unauthorized access to school domain.');
        }

        try {
            $this->domainService->deleteDomain($domain);
            return back()->with('success', 'Domain removed successfully.');
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }
}
