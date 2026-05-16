<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev\Support;

/**
 * Transport struct returned by {@see RunExecutor}.
 *
 * Carries the canonical completion_state plus pointers to the persisted
 * receipts so the HTTP layer can return a thin summary without serializing
 * the full schema objects in-band.
 *
 * Provider call metadata is sanitized: never includes raw stdout/stderr,
 * tokens, secrets or system prompts — only counts and exit codes.
 *
 * @phpstan-type ProviderCallSummary array{
 *   provider: string,
 *   model_family: string,
 *   provider_calls: int,
 *   exit_code: int,
 *   duration_ms: int,
 *   tokens_in: int|null,
 *   tokens_out: int|null,
 *   estimated_cost_usd: float|null,
 *   error_codes: list<string>,
 *   raw_response_hash?: string|null,
 *   stdout_bytes?: int,
 *   stderr_bytes?: int,
 * }
 */
final class RunExecutionResult
{
    /**
     * @param  array<string, string>  $persistedReceiptPaths  receipt_name => absolute_path
     * @param  ProviderCallSummary  $providerCallSummary
     */
    public function __construct(
        public readonly string $completionState,
        public readonly string $scopeGuardStatus,
        public readonly string $verificationStatus,
        public readonly array $persistedReceiptPaths,
        public readonly array $providerCallSummary,
        public readonly ?array $diffParseSummary = null,
        public readonly ?string $verificationReceiptHash = null,
        public readonly ?string $scopeGuardReceiptHash = null,
        public readonly ?string $diffHash = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'completion_state' => $this->completionState,
            'scope_guard_status' => $this->scopeGuardStatus,
            'verification_status' => $this->verificationStatus,
            'verification_receipt_hash' => $this->verificationReceiptHash,
            'scope_guard_receipt_hash' => $this->scopeGuardReceiptHash,
            'diff_hash' => $this->diffHash,
            'diff_parse' => $this->diffParseSummary,
            'provider_call' => $this->providerCallSummary,
            'persisted_receipt_paths' => $this->persistedReceiptPaths,
        ];
    }
}
