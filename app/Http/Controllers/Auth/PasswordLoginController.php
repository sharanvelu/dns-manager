<?php

declare(strict_types = 1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\RedirectResponse;
use App\Http\Requests\Auth\PasswordLoginRequest;

class PasswordLoginController extends Controller
{
    public function store(PasswordLoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = Auth::user();

        activity('auth')->causedBy($user)->performedOn($user)->event('login')->withProperties(['method' => 'password'])->log('login');

        return redirect()->intended(route('dashboard'));
    }
}
