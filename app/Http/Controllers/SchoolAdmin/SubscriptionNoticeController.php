<?php

namespace App\Http\Controllers\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolSubscription;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionNoticeController extends Controller
{
    /**
     * Display the subscription access notice screen when school operational access is suspended or expired.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();
        $school = $user->school ?? School::find($user->school_id);
        $subscription = SchoolSubscription::where('school_id', $user->school_id)->latest('id')->first();

        return Inertia::render('SchoolAdmin/SubscriptionNotice', [
            'schoolName'         => $school?->name ?? 'School Tenant',
            'subscriptionStatus' => $subscription?->status ?? 'suspended',
            'supportEmail'       => config('mail.from.address', 'support@edusystem.store'),
        ]);
    }
}
