<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile page (profile info + appearance preference).
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('profile', [
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's display name. Email and identity come from the
     * OIDC provider and are refreshed on every login.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());
        $request->user()->save();

        return to_route('profile.edit');
    }
}
