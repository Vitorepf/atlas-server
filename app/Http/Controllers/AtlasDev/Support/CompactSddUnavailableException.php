<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev\Support;

use RuntimeException;

/**
 * Thrown by {@see PipelineRunExecutor} when the persisted CompactSDD that
 * carries the canonical task_kind / risk_level for a run cannot be loaded
 * or is structurally invalid.
 *
 * F-03: VerificationReceipt MUST mirror the CompactSDD's task_kind /
 * risk_level. Silently inventing defaults (e.g. `patch`/`R0` or the old
 * `R2`) corrupts honesty downstream — gates, telemetry and operator UI
 * would all read a fabricated risk profile. So the run fails closed.
 *
 * The RunController maps this exception to HTTP 422 with one of the codes
 * `COMPACT_SDD_MISSING`, `COMPACT_SDD_INVALID` or `COMPACT_SDD_TAMPERED`.
 */
final class CompactSddUnavailableException extends RuntimeException
{
    public const REASON_MISSING = 'missing';

    public const REASON_INVALID = 'invalid';

    public const REASON_TAMPERED = 'tampered';

    private function __construct(
        public readonly string $runId,
        public readonly string $reasonCode,
        public readonly string $detail,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function missing(string $runId): self
    {
        return new self(
            runId: $runId,
            reasonCode: self::REASON_MISSING,
            detail: 'no_persisted_compact_sdd',
            message: "compact_sdd.json is missing for run_id '{$runId}'; cannot derive task_kind / risk_level.",
        );
    }

    public static function invalid(string $runId, string $detail): self
    {
        return new self(
            runId: $runId,
            reasonCode: self::REASON_INVALID,
            detail: $detail,
            message: "compact_sdd.json for run_id '{$runId}' is invalid: {$detail}.",
        );
    }

    public static function tampered(string $runId, string $detail): self
    {
        return new self(
            runId: $runId,
            reasonCode: self::REASON_TAMPERED,
            detail: $detail,
            message: "compact_sdd.json for run_id '{$runId}' failed hash-pin validation: {$detail}.",
        );
    }

    public function errorCode(): string
    {
        return match ($this->reasonCode) {
            self::REASON_MISSING => 'COMPACT_SDD_MISSING',
            self::REASON_TAMPERED => 'COMPACT_SDD_TAMPERED',
            default => 'COMPACT_SDD_INVALID',
        };
    }
}
