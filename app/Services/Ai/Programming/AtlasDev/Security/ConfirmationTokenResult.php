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

    /**
     * @param  string|null  $expectedCompactSddHash  the CompactSDD hash that
     *                                               was pinned in the DB row when this token was issued. The caller
     *                                               (RunController) feeds it back to the executor so the run can fail
     *                                               closed when the on-disk compact_sdd has been tampered with between
     *                                               Plan and Run. Null when the token row pre-dates the hash-pin column.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $reason,
        public readonly ?string $tokenId = null,
        public readonly ?string $expectedCompactSddHash = null,
    ) {}

    public static function ok(string $tokenId, ?string $expectedCompactSddHash = null): self
    {
        return new self(
            ok: true,
            reason: self::REASON_OK,
            tokenId: $tokenId,
            expectedCompactSddHash: $expectedCompactSddHash,
        );
    }

    public static function fail(string $reason): self
    {
        return new self(ok: false, reason: $reason, tokenId: null, expectedCompactSddHash: null);
    }
}
