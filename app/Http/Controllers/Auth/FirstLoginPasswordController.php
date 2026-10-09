<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\PortalCredentialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class FirstLoginPasswordController extends Controller
{
    public function show(): Response|RedirectResponse
    {
        $user = auth()->user();

        if (! $user->must_change_password) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/FirstChangePassword', [
            'username' => $user->username,
            'role'     => $user->getRoleNames()->first() ?? 'user',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = auth()->user();

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        PortalCredentialService::completePasswordChange($user, $data['password']);

        $message = 'Your new password has been set successfully. Welcome!';

        return match (true) {
            $user->hasRole('student') => redirect()->route('student.dashboard')->with('success', $message),
            $user->hasRole('parent')  => redirect()->route('parent.dashboard')->with('success', $message),
            default                  => redirect()->route('dashboard')->with('success', $message),
        };
    }
}
