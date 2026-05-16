<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Security;

use RuntimeException;

/**
 * Fail-closed signal for review finding F-09.
 *
 * The Atlas Dev confirmation token derives its server-side hash from
 * HMAC-SHA256(plaintext, APP_KEY). If APP_KEY is empty or shorter than 32
 * bytes, the HMAC degrades to a publicly known constant — equivalent to
 * plain sha256 of the plaintext. Issuing or consuming under that condition
 * would allow any caller to forge a valid token, so the service refuses to
 * proceed and throws this exception instead of returning a "valid" token.
 *
 * Operators see a 500 with code ATLAS_DEV_KEY_MISSING; they MUST rotate
 * APP_KEY (php artisan key:generate) before Atlas Dev Run will accept any
 * confirmation token.
 */
final class ConfirmationTokenKeyMissingException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self(
            'Atlas Dev confirmation_token requires APP_KEY (base64:... at least 32 bytes) to be configured. '
            .'Refusing to issue/consume tokens with a missing or short signing key.',
        );
    }
}
