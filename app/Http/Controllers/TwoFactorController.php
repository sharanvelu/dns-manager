<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use App\Http\Requests\TwoFactorCodeRequest;

/** Self-service TOTP enrollment from the profile page. */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactor $twoFactor)
    {
    }

    /** Issues a fresh secret; abandoned or restarted enrollments simply rotate it. */
    public function enable(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        if ($user->hasTwoFactorEnabled()) {
            return back()->withErrors(['code' => 'Two-factor authentication is already enabled.']);
        }

        $user->forceFill([
            'two_factor_secret' => $this->twoFactor->generateSecret(),
            'two_factor_recovery_codes' => $this->twoFactor->generateRecoveryCodes(),
            'two_factor_confirmed_at' => null,
        ])->save();

        return back();
    }

    public function confirm(TwoFactorCodeRequest $request): RedirectResponse
    {
        $user = $this->user($request);

        if (! $user->hasPendingTwoFactor()) {
            return back()->withErrors(['code' => 'Start two-factor setup first.']);
        }

        if (! $this->twoFactor->verify($user, (string) $request->validated('code'))) {
            return back()->withErrors(['code' => 'That code is not valid. Check your authenticator app and try again.']);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        activity('auth')->causedBy($user)->performedOn($user)->event('two-factor-enabled')->log('two-factor-enabled');

        return back()
            ->with('success', 'Two-factor authentication is on. Save your recovery codes.')
            ->with('twoFactorRecoveryCodes', $user->two_factor_recovery_codes);
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);

        $user = $this->user($request);
        $wasEnabled = $user->hasTwoFactorEnabled();

        $this->twoFactor->clear($user);

        if ($wasEnabled) {
            activity('auth')->causedBy($user)->performedOn($user)->event('two-factor-disabled')->log('two-factor-disabled');
        }

        return back()->with('success', $wasEnabled ? 'Two-factor authentication is off.' : 'Two-factor setup cancelled.');
    }

    public function recoveryCodes(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);

        $user = $this->user($request);

        if (! $user->hasTwoFactorEnabled()) {
            return back()->withErrors(['current_password' => 'Two-factor authentication is not enabled.']);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        activity('auth')->causedBy($user)->performedOn($user)->event('two-factor-recovery-codes-regenerated')->log('two-factor-recovery-codes-regenerated');

        return back()
            ->with('success', 'New recovery codes generated. The old ones no longer work.')
            ->with('twoFactorRecoveryCodes', $codes);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
