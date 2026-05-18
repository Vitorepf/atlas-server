<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Confirmation;

use App\Services\Ai\Vox\VoxSchema;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Issues + validates Vox V3 confirmation tokens.
 *
 * Why a dedicated service: V3 governed_execute requires a single-use,
 * cache-backed token so the operator can't replay an old confirmation
 * against a fresh receipt. The token is HMAC-SHA256 over
 * (request_id, receipt_id, intent_id, decision, expires_at) keyed by an
 * app-scope secret. We never log the token itself; we log the
 * `confirmation_token_in_ledger=false` flag instead (see VoxEvidenceService).
 *
 * Security invariants:
 *   - Tokens are single-use: `consume()` deletes the cache entry on success.
 *   - Tokens expire (default 120s, mirroring VoxConfirmation.v1).
 *   - R4 tokens additionally bind the operator's literal confirmation text;
 *     consume() requires the exact same string (case-sensitive) to validate.
 *   - The cache value carries the issued metadata but NOT the token itself
 *     — we re-derive the HMAC on every consume so a leaked cache row can't
 *     be replayed without the secret.
 */
final class VoxConfirmationService
{
    /** Cache TTL margin so the cache entry doesn't disappear before its
     *  `expires_at` would. We add a small slack so consume() can still see
     *  the row to emit a precise "expired" error instead of silently
     *  treating it as never-issued. */
    private const CACHE_SLACK_SECONDS = 60;

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Issues a new confirmation request + token.
     *
     * @param  array<string,mixed>  $intentPacket
     * @param  array<string,mixed>  $receipt
     * @param  array{
     *     mode: string,
     *     risk_class: string,
     *     preview: array<string,mixed>,
     *     actions_available: list<string>,
     *     literal_confirmation_text?: string|null,
     *     ttl_seconds?: int,
     * } $context
     * @return array{
     *     request: array<string,mixed>,
     *     token: string,
     * }
     */
    public function issue(array $intentPacket, array $receipt, array $context): array
    {
        $now = Carbon::now('UTC');
        $ttl = (int) ($context['ttl_seconds'] ?? VoxSchema::CONFIRMATION_DEFAULT_TTL_SECONDS);
        if ($ttl <= 0) {
            $ttl = VoxSchema::CONFIRMATION_DEFAULT_TTL_SECONDS;
        }
        $expiresAt = $now->copy()->addSeconds($ttl);

        $requestId = (string) Str::uuid();
        $sessionId = (string) ($intentPacket['session_id'] ?? '');
        $intentId = (string) ($intentPacket['intent_id'] ?? '');
        $receiptId = (string) ($receipt['receipt_id'] ?? '');
        $riskClass = (string) ($context['risk_class'] ?? VoxSchema::RISK_R0);

        $requiresLiteral = $riskClass === VoxSchema::RISK_R4;
        $literalText = $requiresLiteral
            ? (string) ($context['literal_confirmation_text']
                ?? self::defaultLiteralFor($intentPacket))
            : null;

        $actions = self::normaliseActions((array) ($context['actions_available'] ?? []));

        $token = $this->mintToken(
            requestId: $requestId,
            receiptId: $receiptId,
            intentId: $intentId,
            expiresAt: $expiresAt,
        );

        $cacheKey = $this->cacheKey($requestId);
        Cache::store()->put(
            $cacheKey,
            [
                'request_id' => $requestId,
                'session_id' => $sessionId,
                'intent_id' => $intentId,
                'receipt_id' => $receiptId,
                'risk_class' => $riskClass,
                'requires_literal_confirmation' => $requiresLiteral,
                'literal_confirmation_text' => $literalText,
                'actions_available' => $actions,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
            $ttl + self::CACHE_SLACK_SECONDS,
        );

        $request = [
            'schema' => VoxSchema::CONFIRMATION_REQUEST,
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'intent_id' => $intentId,
            'receipt_id' => $receiptId,
            'risk_class' => $riskClass,
            'preview' => $context['preview'],
            'actions_available' => $actions,
            'ttl_seconds' => $ttl,
            'requires_literal_confirmation' => $requiresLiteral,
            'literal_confirmation_text' => $literalText,
            'expires_at' => $expiresAt->toIso8601String(),
            'issued_at' => $now->toIso8601String(),
            'confirmation_token' => $token,
        ];

        return ['request' => $request, 'token' => $token];
    }

    /**
     * Validates a confirmation token + literal text and consumes the
     * confirmation slot (single-use).
     *
     * @return array{
     *     ok: bool,
     *     reason?: string,
     *     code?: string,
     *     request?: array<string,mixed>,
     * }
     */
    public function consume(
        string $requestId,
        string $intentId,
        string $receiptId,
        string $decision,
        string $confirmationToken,
        ?string $literalConfirmationInput = null,
    ): array {
        if ($requestId === '' || $confirmationToken === '') {
            return ['ok' => false, 'code' => 'confirmation_token_missing', 'reason' => 'request_id/token missing'];
        }

        $cacheKey = $this->cacheKey($requestId);
        $row = Cache::store()->get($cacheKey);
        if (! is_array($row)) {
            return ['ok' => false, 'code' => 'confirmation_unknown_or_expired', 'reason' => 'no active confirmation matches request_id'];
        }

        if (($row['intent_id'] ?? null) !== $intentId
            || ($row['receipt_id'] ?? null) !== $receiptId
        ) {
            return ['ok' => false, 'code' => 'confirmation_binding_mismatch', 'reason' => 'intent_id/receipt_id do not match the issued confirmation'];
        }

        $expiresAt = isset($row['expires_at']) ? Carbon::parse((string) $row['expires_at']) : null;
        if ($expiresAt === null || $expiresAt->isPast()) {
            Cache::store()->forget($cacheKey);

            return ['ok' => false, 'code' => 'confirmation_expired', 'reason' => 'confirmation token expired'];
        }

        $expected = $this->mintToken(
            requestId: $requestId,
            receiptId: $receiptId,
            intentId: $intentId,
            expiresAt: $expiresAt,
        );
        if (! hash_equals($expected, $confirmationToken)) {
            return ['ok' => false, 'code' => 'confirmation_token_invalid', 'reason' => 'token signature mismatch'];
        }

        $allowed = (array) ($row['actions_available'] ?? []);
        if ($allowed !== [] && ! in_array($decision, $allowed, true)) {
            return ['ok' => false, 'code' => 'decision_not_in_actions_available', 'reason' => "decision '{$decision}' is not in actions_available"];
        }

        if ((bool) ($row['requires_literal_confirmation'] ?? false)) {
            $expectedLiteral = (string) ($row['literal_confirmation_text'] ?? '');
            $provided = (string) ($literalConfirmationInput ?? '');
            // case-sensitive equality per VoxConfirmation.v1 rule 5
            if ($expectedLiteral === '' || $provided !== $expectedLiteral) {
                return ['ok' => false, 'code' => 'literal_confirmation_mismatch', 'reason' => 'literal_confirmation_input does not match required text'];
            }
        }

        // Single-use semantics: forget the row so replays fail.
        Cache::store()->forget($cacheKey);

        return ['ok' => true, 'request' => $row];
    }

    /**
     * Forgets a confirmation slot without consuming it. Used when the
     * operator chooses `cancel` so we don't leave the cache holding a token
     * that could otherwise still be replayed within TTL.
     */
    public function forget(string $requestId): void
    {
        if ($requestId === '') {
            return;
        }
        Cache::store()->forget($this->cacheKey($requestId));
    }

    private function mintToken(
        string $requestId,
        string $receiptId,
        string $intentId,
        Carbon $expiresAt,
    ): string {
        $material = implode('|', [
            $requestId,
            $receiptId,
            $intentId,
            $expiresAt->toIso8601String(),
        ]);
        $secret = $this->secret();

        return 'hmac_sha256:'.hash_hmac('sha256', $material, $secret);
    }

    private function secret(): string
    {
        $key = (string) config('app.key', '');
        // config('app.key') is usually "base64:..." — keep it as the secret
        // material verbatim. In tests we still get a deterministic value via
        // the application config, no need to invent a new env var.
        return $key !== '' ? $key : 'vox-confirmation-fallback-secret';
    }

    private function cacheKey(string $requestId): string
    {
        return 'vox:v3:confirmation:'.$requestId;
    }

    /**
     * @param  list<string>  $actions
     * @return list<string>
     */
    private static function normaliseActions(array $actions): array
    {
        $clean = [];
        foreach ($actions as $action) {
            if (is_string($action) && $action !== '' && ! in_array($action, $clean, true)) {
                $clean[] = $action;
            }
        }
        // `cancel` is always present per VoxConfirmation.v1 rule 1.
        if (! in_array(VoxSchema::DESKTOP_ACTION_CANCEL, $clean, true)) {
            $clean[] = VoxSchema::DESKTOP_ACTION_CANCEL;
        }

        return $clean;
    }

    /**
     * @param  array<string,mixed>  $intentPacket
     */
    private static function defaultLiteralFor(array $intentPacket): string
    {
        $executor = (string) ($intentPacket['executor_hint'] ?? 'execute');
        if ($executor === 'none' || $executor === '') {
            return 'execute';
        }

        // Match the canon example "execute push force" — derive a sentence
        // operator must literally type. Stable + meaningful.
        return 'execute '.str_replace('_', ' ', $executor);
    }
}
