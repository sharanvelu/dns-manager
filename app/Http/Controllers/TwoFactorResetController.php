<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

/** Admin escape hatch for a user who lost both their device and their recovery codes. */
class TwoFactorResetController extends Controller
{
    public function destroy(Request $request, User $user, TwoFactor $twoFactor): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        if ($admin->is($user)) {
            return back()->withErrors(['user' => 'Manage your own two-factor authentication from your profile.']);
        }

        if (! $user->hasTwoFactorEnabled() && ! $user->hasPendingTwoFactor()) {
            return back()->withErrors(['user' => "{$user->name} has no two-factor authentication to reset."]);
        }

        $twoFactor->clear($user);
        $user->signOutOtherSessions();

        activity('auth')->causedBy($admin)->performedOn($user)->event('two-factor-reset')->log('two-factor-reset');

        return back()->with('success', "Two-factor authentication reset for {$user->name}. Their sessions were ended.");
    }
}
