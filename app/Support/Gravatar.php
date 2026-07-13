<?php

namespace App\Support;

class Gravatar
{
    /**
     * Gravatar URL for the email. `d=404` makes Gravatar return HTTP 404
     * when no avatar exists, so the frontend falls back to initials.
     */
    public static function url(string $email, int $size = 160): string
    {
        $hash = hash('sha256', strtolower(trim($email)));

        return "https://www.gravatar.com/avatar/{$hash}?s={$size}&d=404";
    }
}
