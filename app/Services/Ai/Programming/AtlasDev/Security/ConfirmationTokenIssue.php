<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Security;

use Illuminate\Support\Carbon;

final class ConfirmationTokenIssue
{
    public function __construct(
        public readonly string $tokenId,
        public readonly string $plaintext,
        public readonly Carbon $issuedAt,
        public readonly Carbon $expiresAt,
    ) {}
}
