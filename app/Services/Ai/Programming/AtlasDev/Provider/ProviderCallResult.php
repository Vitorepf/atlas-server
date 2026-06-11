<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Provider;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use InvalidArgumentException;

/**
 * Transport-edge result emitted by SonnetClaudeCliAdapter::executeOneCall().
 *
 * Records what *actually* happened at the provider boundary — never masks
 * fallback, never silently retries. Higher layers (DiffParser, ScopeGuard,
 * VerificationGate, CompletionStateGate) consume this DTO to build receipts.
 */
final class ProviderCallResult implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.provider_call_result.v1';

    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $actualProvider,
        public readonly string $actualModelFamily,
        public readonly int $exitStatus,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly int $durationMs,
        public readonly ?int $tokensIn,
        public readonly ?int $tokensOut,
        public readonly ?float $costEstimateUsd,
        public readonly string $rawResponseHash,
        public readonly bool $providerSafe,
        public readonly array $errors = [],
    ) {
        if ($this->runId === '') {
            throw new InvalidArgumentException('ProviderCallResult.run_id must not be empty.');
        }
        if ($this->actualProvider === '') {
            throw new InvalidArgumentException('ProviderCallResult.actual_provider must not be empty.');
        }
        if ($this->actualModelFamily === '') {
            throw new InvalidArgumentException('ProviderCallResult.actual_model_family must not be empty.');
        }
        if ($this->durationMs < 0) {
            throw new InvalidArgumentException('ProviderCallResult.duration_ms must be non-negative.');
        }
        if ($this->rawResponseHash === '') {
            throw new InvalidArgumentException('ProviderCallResult.raw_response_hash must not be empty.');
        }
        if ($this->tokensIn !== null && $this->tokensIn < 0) {
            throw new InvalidArgumentException('ProviderCallResult.tokens_in must be null or non-negative.');
        }
        if ($this->tokensOut !== null && $this->tokensOut < 0) {
            throw new InvalidArgumentException('ProviderCallResult.tokens_out must be null or non-negative.');
        }
        AtlasDevStringListNormalizer::requireNonEmptyStrings($this->errors, 'ProviderCallResult.errors');
    }

    public function ok(): bool
    {
        return $this->exitStatus === 0
            && $this->providerSafe
            && $this->errors === [];
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'actual_model_family' => $this->actualModelFamily,
            'actual_provider' => $this->actualProvider,
            'cost_estimate_usd' => $this->costEstimateUsd,
            'duration_ms' => $this->durationMs,
            'errors' => array_values($this->errors),
            'exit_status' => $this->exitStatus,
            'ok' => $this->ok(),
            'provider_safe' => $this->providerSafe,
            'raw_response_hash' => $this->rawResponseHash,
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'stderr' => $this->stderr,
            'stdout' => $this->stdout,
            'tokens_in' => $this->tokensIn,
            'tokens_out' => $this->tokensOut,
        ]);
    }

    public function toProviderSafeArray(): array
    {
        return $this->toCanonicalArray();
    }

    /**
     * Summary safe for HTTP/CLI responses. Raw stdout/stderr stay in the
     * persisted provider_call_result artifact for audit and replay.
     *
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return CanonicalJson::canonicalize([
            'actual_model_family' => $this->actualModelFamily,
            'actual_provider' => $this->actualProvider,
            'cost_estimate_usd' => $this->costEstimateUsd,
            'duration_ms' => $this->durationMs,
            'errors' => array_values($this->errors),
            'exit_status' => $this->exitStatus,
            'ok' => $this->ok(),
            'provider_safe' => $this->providerSafe,
            'raw_response_hash' => $this->rawResponseHash,
            'schema_version' => self::SCHEMA_VERSION,
            'stderr_bytes' => strlen($this->stderr),
            'stdout_bytes' => strlen($this->stdout),
            'tokens_in' => $this->tokensIn,
            'tokens_out' => $this->tokensOut,
        ]);
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toCanonicalArray());
    }

    public function hash(): string
    {
        return CanonicalHasher::hash($this->toCanonicalArray());
    }

    public function isProviderSafe(): bool
    {
        return $this->providerSafe;
    }

    public static function fromStdout(
        string $runId,
        string $actualProvider,
        string $actualModelFamily,
        int $exitStatus,
        string $stdout,
        string $stderr,
        int $durationMs,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        ?float $costEstimateUsd = null,
        bool $providerSafe = true,
        array $errors = [],
    ): self {
        return new self(
            runId: $runId,
            actualProvider: $actualProvider,
            actualModelFamily: $actualModelFamily,
            exitStatus: $exitStatus,
            stdout: $stdout,
            stderr: $stderr,
            durationMs: $durationMs,
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            costEstimateUsd: $costEstimateUsd,
            rawResponseHash: hash('sha256', $stdout),
            providerSafe: $providerSafe,
            errors: array_values($errors),
        );
    }
}
