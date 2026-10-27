<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use App\Support\TwoFactor;
use App\Support\AuthMethods;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use App\Http\Requests\ProfileUpdateRequest;

class ProfileController extends Controller
{
    /**
     * Show the user's profile page (profile info + appearance preference).
     */
    public function edit(Request $request, TwoFactor $twoFactor): Response
    {
        return Inertia::render('profile', [
            'status' => $request->session()->get('status'),
            'basicEnabled' => AuthMethods::basic(),
            'hasPassword' => $request->user()->password !== null,
            'ssoLinked' => $request->user()->oidc_sub !== null,
            'ssoOnly' => ! $request->user()->passwordLoginAllowed(),
            'twoFactor' => [
                'enabled' => $request->user()->hasTwoFactorEnabled(),
                'pending' => $request->user()->hasPendingTwoFactor()
                    ? [
                        'qrSvg' => $twoFactor->qrCodeSvg($request->user(), $request->user()->two_factor_secret),
                        'secret' => $request->user()->two_factor_secret,
                    ]
                    : null,
                'recoveryCodes' => $request->session()->get('twoFactorRecoveryCodes'),
            ],
        ]);
    }

    /**
     * Update the user's display name. Email is fixed; for SSO users
     * identity comes from the OIDC provider and is refreshed on every login.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());
        $request->user()->save();

        return to_route('profile.edit');
    }
}
