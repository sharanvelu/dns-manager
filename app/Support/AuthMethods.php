<?php

declare(strict_types = 1);

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * Which sign-in methods are live. Basic (password) auth may only be turned
 * off while SSO is on — with SSO off it is forced on so nobody is locked out.
 */
class AuthMethods
{
    public static function sso(): bool
    {
        return (bool) config('auth.sso_enabled');
    }

    public static function basic(): bool
    {
        return ! self::sso() || (bool) config('auth.basic_enabled');
    }

    /** Create accounts on first SSO login. Meaningless (always false) while SSO is off. */
    public static function autoProvision(): bool
    {
        return self::sso() && (bool) config('auth.sso_auto_provision');
    }

    /**
     * Whether admins may add users by hand. Hidden only when it would be
     * pointless: SSO-only sign-in where accounts already appear on first login.
     */
    public static function canCreateUsers(): bool
    {
        return self::basic() || ! self::autoProvision();
    }

    public static function passwordRule(): Password
    {
        return Password::min(max(1, (int) config('auth.password_min_length')));
    }
}
