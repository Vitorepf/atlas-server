<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketExec;

/**
 * Detects HOW the operator's Polymarket account signs, from LOCAL env only.
 *
 * Polymarket has two account shapes and they sign differently:
 *  - EOA: the operator's own externally-owned key signs and funds directly.
 *    CLOB signature type 0.
 *  - Proxy wallet: an email/magic login (the default Polymarket UX). A session
 *    key signs but the FUNDS and positions live in a proxy contract, so orders
 *    must declare that proxy as the funder. CLOB signature type 1 (email/magic)
 *    or 2 (browser-injected). Getting this wrong means signed-but-unfunded
 *    orders, so we detect it explicitly instead of assuming.
 *
 * This class NEVER returns or logs secret values — only presence booleans and
 * the resolved kind. Secrets are read by the Python signer from env at the
 * moment of use; PHP only checks that they exist.
 */
final class PolyAccountIdentity
{
    public const KIND_EOA = 'eoa';

    public const KIND_PROXY = 'proxy';

    public const KIND_UNKNOWN = 'unknown';

    public function __construct(
        private readonly bool $hasPrivateKey,
        private readonly bool $hasFunderAddress,
        private readonly bool $hasApiKey,
        private readonly bool $hasApiSecret,
        private readonly bool $hasApiPassphrase,
        private readonly string $configuredKind,
    ) {}

    public static function detect(): self
    {
        $live = (array) config('atlas.finance_poly_exec.live', []);
        $present = static fn (string $key): bool => is_string($key) && $key !== ''
            && is_string(env($key)) && trim((string) env($key)) !== '';

        return new self(
            hasPrivateKey: $present((string) ($live['private_key_env'] ?? 'ATLAS_POLY_PRIVATE_KEY')),
            hasFunderAddress: $present((string) ($live['funder_address_env'] ?? 'ATLAS_POLY_FUNDER_ADDRESS')),
            hasApiKey: $present((string) ($live['api_key_env'] ?? 'ATLAS_POLY_CLOB_API_KEY')),
            hasApiSecret: $present((string) ($live['api_secret_env'] ?? 'ATLAS_POLY_CLOB_API_SECRET')),
            hasApiPassphrase: $present((string) ($live['api_passphrase_env'] ?? 'ATLAS_POLY_CLOB_API_PASSPHRASE')),
            configuredKind: (string) ($live['account_kind'] ?? 'auto'),
        );
    }

    public function kind(): string
    {
        if ($this->configuredKind === self::KIND_EOA || $this->configuredKind === self::KIND_PROXY) {
            return $this->configuredKind;
        }
        // auto: a configured funder address that differs from the signer implies a
        // proxy wallet; a bare private key with no funder implies a direct EOA.
        if ($this->hasFunderAddress) {
            return self::KIND_PROXY;
        }
        if ($this->hasPrivateKey) {
            return self::KIND_EOA;
        }

        return self::KIND_UNKNOWN;
    }

    /** Polymarket CLOB signature type: 0 = EOA, 1 = email/magic proxy. */
    public function signatureType(): int
    {
        return $this->kind() === self::KIND_PROXY ? 1 : 0;
    }

    public function hasSigningKey(): bool
    {
        return $this->hasPrivateKey;
    }

    public function hasApiCreds(): bool
    {
        return $this->hasApiKey && $this->hasApiSecret && $this->hasApiPassphrase;
    }

    /** A proxy account must declare its funder address or orders are unfunded. */
    public function isFundingResolvable(): bool
    {
        return $this->kind() === self::KIND_EOA
            ? $this->hasPrivateKey
            : ($this->kind() === self::KIND_PROXY && $this->hasFunderAddress && $this->hasPrivateKey);
    }

    /**
     * Why live execution is or isn't wireable — booleans only, no secrets.
     *
     * @return array<string, mixed>
     */
    public function readiness(): array
    {
        $missing = [];
        if (! $this->hasPrivateKey) {
            $missing[] = 'private_key';
        }
        if ($this->kind() === self::KIND_PROXY && ! $this->hasFunderAddress) {
            $missing[] = 'funder_address';
        }
        if (! $this->hasApiCreds()) {
            $missing[] = 'clob_api_creds(l2)';
        }

        return [
            'kind' => $this->kind(),
            'signature_type' => $this->signatureType(),
            'has_signing_key' => $this->hasSigningKey(),
            'has_funder_address' => $this->hasFunderAddress,
            'has_api_creds' => $this->hasApiCreds(),
            'funding_resolvable' => $this->isFundingResolvable(),
            'ready' => $this->isFundingResolvable() && $this->hasApiCreds(),
            'missing' => $missing,
        ];
    }
}
