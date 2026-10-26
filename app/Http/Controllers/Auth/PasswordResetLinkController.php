<?php

declare(strict_types = 1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;

class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($validated);

        if ($status === Password::RESET_LINK_SENT && $user = User::query()->where('email', $validated['email'])->first()) {
            activity('auth')->performedOn($user)->event('password-reset-requested')->log('password-reset-requested');
        }

        // Identical response whether or not the address exists — no account enumeration.
        return back()->with('status', 'If an account exists for that email address, a password reset link is on its way.');
    }
}
