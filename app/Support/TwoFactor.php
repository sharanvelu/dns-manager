<?php

declare(strict_types = 1);

namespace App\Support;

use App\Models\User;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Illuminate\Support\Facades\Cache;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;

/** TOTP enrollment and verification (RFC 6238) plus single-use recovery codes. */
class TwoFactor
{
    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly Google2FA $google2fa)
    {
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => Str::random(10) . '-' . Str::random(10))
            ->all();
    }

    /** Inline SVG of the otpauth:// QR code, without the XML declaration so it can be embedded in HTML. */
    public function qrCodeSvg(User $user, string $secret): string
    {
        $url = $this->google2fa->getQRCodeUrl((string) config('app.name'), $user->email, $secret);

        $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd())))->writeString($url);

        return trim((string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg));
    }

    /** Accepts one step of clock drift either side; each accepted code is burned so it cannot be replayed. */
    public function verify(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if ($secret === null || ! $this->google2fa->verifyKey($secret, $code, 1)) {
            return false;
        }

        return Cache::add("two-factor-used:{$user->getKey()}:{$code}", true, 90);
    }

    /** Burns the matching recovery code, returning whether one matched. */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = collect($user->getAttribute('two_factor_recovery_codes'));
        $remaining = $codes->reject(fn ($candidate) => hash_equals((string) $candidate, $code))->values();

        if ($remaining->count() === $codes->count()) {
            return false;
        }

        $user->forceFill(['two_factor_recovery_codes' => $remaining->all()])->save();

        return true;
    }

    public function clear(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }
}
