<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Security;

use App\Models\AtlasDevConfirmationToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Anti-replay confirmation token for Atlas Dev provider runs.
 *
 * Surface flow:
 *   1. Surface adapter calls `issue(runId, taskContractHash, surfaceId)` after
 *      plan-only is complete. Plaintext is returned exactly once.
 *   2. Surface stores the plaintext locally and shows it to the human.
 *   3. To trigger the provider run, surface sends the plaintext + runId +
 *      taskContractHash. Server calls `validateAndConsume(...)`.
 *   4. The token is single-use; reuse, expiry, run/contract mismatch all
 *      return a typed failure result.
 *
 * Plaintext is never persisted: only HMAC-SHA256(plaintext, app.key) is stored.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
 */
class ConfirmationTokenService
{
    public function __construct() {}

    public function issue(string $runId, string $taskContractHash, string $surfaceId): ConfirmationTokenIssue
    {
        $this->assertNonEmpty('run_id', $runId);
        $this->assertNonEmpty('task_contract_hash', $taskContractHash);
        $this->assertNonEmpty('surface_id', $surfaceId);

        $bytes = max(16, (int) config('atlas_dev.confirmation_token.plaintext_bytes', 32));
        $plaintext = $this->generatePlaintext($bytes);
        $ttl = max(30, (int) config('atlas_dev.confirmation_token.ttl_seconds', 300));

        $now = Carbon::now();
        $expiresAt = $now->copy()->addSeconds($ttl);

        $row = AtlasDevConfirmationToken::query()->create([
            'run_id' => $runId,
            'token_hash' => $this->hashSecret($plaintext),
            'surface_id' => $surfaceId,
            'task_contract_hash' => $taskContractHash,
            'issued_at' => $now,
            'expires_at' => $expiresAt,
            'used_at' => null,
        ]);

        return new ConfirmationTokenIssue(
            tokenId: (string) $row->id,
            plaintext: $plaintext,
            issuedAt: $now,
            expiresAt: $expiresAt,
        );
    }

    public function validateAndConsume(
        string $runId,
        string $taskContractHash,
        string $plaintext,
    ): ConfirmationTokenResult {
        if ($runId === '' || $taskContractHash === '' || $plaintext === '') {
            return ConfirmationTokenResult::fail(ConfirmationTokenResult::REASON_INVALID);
        }

        try {
            $hash = $this->hashSecret($plaintext);
        } catch (ConfirmationTokenKeyMissingException) {
            // F-09: fail closed and let the caller (RunController) translate
            // this into a 500 ATLAS_DEV_KEY_MISSING. No token can possibly
            // be valid under a missing/short APP_KEY.
            return ConfirmationTokenResult::fail(ConfirmationTokenResult::REASON_KEY_MISSING);
        }

        return DB::transaction(function () use ($runId, $taskContractHash, $hash): ConfirmationTokenResult {
            $query = AtlasDevConfirmationToken::query()->where('token_hash', $hash);

            if (DB::connection()->getDriverName() !== 'sqlite') {
                $query->lockForUpdate();
            }

            $token = $query->first();

            if ($token === null) {
                return ConfirmationTokenResult::fail(ConfirmationTokenResult::REASON_NOT_FOUND);
            }

            if (! hash_equals((string) $token->run_id, $runId)) {
                return ConfirmationTokenResult::fail(ConfirmationTokenResult::REASON_RUN_ID_MISMATCH);
            }

            if (! hash_equals((string) $token->task_contract_hash, $taskContractHash)) {
                return ConfirmationTokenResult::fail(ConfirmationTokenResult::REASON_TASK_CONTRACT_MISMATCH);
            }

            if ($token->used_at !== null) {
                return ConfirmationTokenResult::fail(ConfirmationTokenResult::REASON_ALREADY_USED);
            }

            if ($token->expires_at !== null && $token->expires_at->isPast()) {
                return ConfirmationTokenResult::fail(ConfirmationTokenResult::REASON_EXPIRED);
            }

            $token->forceFill(['used_at' => Carbon::now()])->save();

            return ConfirmationTokenResult::ok((string) $token->id);
        });
    }

    /**
     * Hash a confirmation token secret with HMAC-SHA256 over APP_KEY.
     *
     * F-09 (review finding) — fails closed when APP_KEY is missing or shorter
     * than 32 bytes (after stripping the optional "base64:" prefix). There is
     * NO public fallback constant: a weak HMAC key would reduce the contract
     * to plain sha256, which any attacker can forge.
     */
    public function hashSecret(string $secret): string
    {
        return hash_hmac('sha256', $secret, $this->signingKey());
    }

    /**
     * @internal exposed for tests; do not depend on this from controllers.
     */
    public function signingKey(): string
    {
        $raw = (string) config('app.key', '');

        // Laravel stores keys as "base64:..." — accept the decoded bytes when
        // present, fall back to the raw string when not (e.g. testing env
        // injecting a hex/random string directly).
        $material = $raw;
        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);
            $material = $decoded === false ? '' : $decoded;
        }

        if ($material === '' || strlen($material) < 32) {
            throw ConfirmationTokenKeyMissingException::notConfigured();
        }

        return $material;
    }

    private function generatePlaintext(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function assertNonEmpty(string $field, string $value): void
    {
        if ($value === '') {
            throw new InvalidArgumentException("ConfirmationTokenService.{$field} must be non-empty.");
        }
    }
}
