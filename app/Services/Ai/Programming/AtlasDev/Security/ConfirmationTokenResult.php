<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Security;

final class ConfirmationTokenResult
{
    public const REASON_OK = 'ok';

    public const REASON_NOT_FOUND = 'not_found';

    public const REASON_RUN_ID_MISMATCH = 'run_id_mismatch';

    public const REASON_TASK_CONTRACT_MISMATCH = 'task_contract_mismatch';

    public const REASON_EXPIRED = 'expired';

    public const REASON_ALREADY_USED = 'already_used';

    public const REASON_INVALID = 'invalid_token';

    public const REASON_KEY_MISSING = 'key_missing';

    public function __construct(
        public readonly bool $ok,
        public readonly string $reason,
        public readonly ?string $tokenId = null,
    ) {}

    public static function ok(string $tokenId): self
    {
        return new self(ok: true, reason: self::REASON_OK, tokenId: $tokenId);
    }

    public static function fail(string $reason): self
    {
        return new self(ok: false, reason: $reason, tokenId: null);
    }
}
