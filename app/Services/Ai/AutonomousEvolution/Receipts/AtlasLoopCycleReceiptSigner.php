<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Receipts;


use App\Services\Ai\SelfConstruction\Support\CanonicalizesNestedValues;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Deterministic signer for loop-cycle receipts. It takes the composer BODY (atlas.loop.cycle_receipt.body.v1)
 * and wraps it in a SIGNED envelope (atlas.loop.cycle_receipt.signed.v1): an HMAC-SHA256 over the CANONICAL
 * JSON of the body (recursively sorted keys, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) plus the
 * body's canonical sha256. {@see verify()} recomputes both and returns true only when the stored sha AND the
 * stored signature match byte-for-byte — so any single mutated byte (in the body OR the stored sha) is caught.
 *
 * The HMAC key is read from config('atlas.ai.loop.cycle_receipt_signing_key') (production sets it via env);
 * a documented dev fallback keeps the signer usable in local/test runs. NO chain logic here — that is packet 3.
 */
final class AtlasLoopCycleReceiptSigner
{
    use CanonicalizesNestedValues;

    public const BODY_SCHEMA = 'atlas.loop.cycle_receipt.body.v1';

    public const SIGNED_SCHEMA = 'atlas.loop.cycle_receipt.signed.v1';

    /** Documented DEV fallback — production MUST set config('atlas.ai.loop.cycle_receipt_signing_key') via env. */
    public const DEV_FALLBACK_SIGNING_KEY = 'atlas-loop-cycle-receipt-dev-signing-key-do-not-use-in-prod';

    private const CANONICAL_FLAGS = JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    private ?Closure $clock = null;

    public function setClock(Closure $clock): void
    {
        $this->clock = $clock;
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array{schema_version:string, body:array<string,mixed>, body_canonical_sha256:string, signature:string, signed_at_iso:string}
     */
    public function sign(array $body): array
    {
        $canonical = $this->canonicalJson($body);

        return [
            'schema_version' => self::SIGNED_SCHEMA,
            'body' => $body,
            'body_canonical_sha256' => hash('sha256', $canonical),
            'signature' => hash_hmac('sha256', $canonical, $this->signingKey()),
            'signed_at_iso' => $this->now()->toIso8601String(),
        ];
    }

    /**
     * True only if the signed receipt is intact: the body's recomputed canonical sha matches the stored sha AND
     * the recomputed HMAC matches the stored signature (both via constant-time compare).
     *
     * @param  array<string,mixed>  $signed
     */
    public function verify(array $signed): bool
    {
        $body = is_array($signed['body'] ?? null) ? (array) $signed['body'] : null;
        if ($body === null) {
            return false;
        }

        $canonical = $this->canonicalJson($body);
        $sha = hash('sha256', $canonical);
        $signature = hash_hmac('sha256', $canonical, $this->signingKey());

        return hash_equals($sha, (string) ($signed['body_canonical_sha256'] ?? ''))
            && hash_equals($signature, (string) ($signed['signature'] ?? ''));
    }

    private function signingKey(): string
    {
        $key = (string) config('atlas.ai.loop.cycle_receipt_signing_key', '');

        return $key !== '' ? $key : self::DEV_FALLBACK_SIGNING_KEY;
    }

    /**
     * @param  array<string,mixed>  $body
     */
    private function canonicalJson(array $body): string
    {
        return (string) json_encode($this->canonicalize($body), self::CANONICAL_FLAGS);
    }


    private function now(): CarbonImmutable
    {
        if ($this->clock !== null) {
            return CarbonImmutable::instance(($this->clock)())->utc();
        }

        return CarbonImmutable::now('UTC');
    }
}
