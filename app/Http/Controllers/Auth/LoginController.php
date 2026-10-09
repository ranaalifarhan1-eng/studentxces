<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    /**
     * Demo accounts shown only on .test domains or xgenious.com
     */
    private array $demoAccounts = [
        [
            'role'     => 'Super Admin',
            'email'    => 'admin@genius-sms.test',
            'password' => 'password',
            'color'    => 'indigo',
        ],
        [
            'role'     => 'School Admin',
            'email'    => 'school-admin@genius-sms.test',
            'password' => 'password',
            'color'    => 'violet',
        ],
        [
            'role'     => 'Principal',
            'email'    => 'principal@genius-sms.test',
            'password' => 'password',
            'color'    => 'blue',
        ],
        [
            'role'     => 'Teacher',
            'email'    => 'teacher@genius-sms.test',
            'password' => 'password',
            'color'    => 'sky',
        ],
        [
            'role'     => 'Accountant',
            'email'    => 'accountant@genius-sms.test',
            'password' => 'password',
            'color'    => 'emerald',
        ],
        [
            'role'     => 'Student',
            'email'    => 'student@genius-sms.test',
            'password' => 'password',
            'color'    => 'amber',
        ],
        [
            'role'     => 'Parent',
            'email'    => 'parent@genius-sms.test',
            'password' => 'password',
            'color'    => 'orange',
        ],
    ];

    public function create(Request $request): Response
    {
        $host = $request->getHost();
        $showDemo = str_ends_with($host, '.test') || str_contains($host, 'xgenious.com');

        return Inertia::render('Auth/Login', [
            'showDemo'     => $showDemo,
            'demoAccounts' => $showDemo ? $this->demoAccounts : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $loginInput = trim($request->input('login') ?? $request->input('email') ?? '');
        $password   = (string) $request->input('password');

        if ($loginInput === '' || $password === '') {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $isEmail = filter_var($loginInput, FILTER_VALIDATE_EMAIL) !== false;
        $credentialKey = $isEmail ? 'email' : 'username';

        $authenticated = Auth::attempt([
            $credentialKey => $loginInput,
            'password'     => $password,
        ], $request->boolean('remember'));

        // Case-insensitive fallback for username
        if (! $authenticated && ! $isEmail) {
            $userCandidate = \App\Models\User::whereRaw('LOWER(username) = ?', [strtolower($loginInput)])->first();
            if ($userCandidate && \Illuminate\Support\Facades\Hash::check($password, $userCandidate->password)) {
                Auth::login($userCandidate, $request->boolean('remember'));
                $authenticated = true;
            }
        }

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        $user = Auth::user();

        // Check if account is inactive
        if ($user->status !== 'active') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'Your account has been deactivated. Please contact your school administrator.',
            ]);
        }

        // Check if temporary password has expired
        if ($user->must_change_password && $user->temporary_password_expires_at && $user->temporary_password_expires_at->isPast()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'Your temporary password has expired. Please contact your school administrator to reset your credentials.',
            ]);
        }

        // Host-based login domain isolation
        $resolvedSchool = app(\App\Services\ActiveSchoolContext::class)->getHostResolvedSchool();
        if ($resolvedSchool) {
            if ($user->hasRole('super-admin') || (int) $user->school_id !== (int) $resolvedSchool->id) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                throw ValidationException::withMessages([
                    'email' => 'This account does not have access to this school portal.',
                ]);
            }
        }

        $user->update(['last_login_at' => now()]);

        activity()
            ->causedBy($user)
            ->withProperties([
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent(),
                'school_id'  => $user->school_id,
            ])
            ->log('User logged in');

        if ($user->must_change_password) {
            return redirect()->route('password.first-change');
        }

        return redirect()->route('dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
