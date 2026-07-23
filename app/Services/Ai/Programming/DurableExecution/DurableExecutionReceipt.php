<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\DurableExecution;


/**
 * Programming Harness — Durable Execution Receipt (AP-285).
 *
 * Append-only receipt that records the outcome of a durable execution
 * run: success, failure, timeout, escalation, or cancellation. Consumed
 * by the Evidence Ledger and the architecture-validate gate. NEVER
 * mutates; callers issue a NEW receipt for each state transition.
 *
 * Implements AP-285 under the canonical naming policy.
 */
final class DurableExecutionReceipt
{
    public const SCHEMA_VERSION = 'atlas.programming.durable_execution_receipt.v1';

    public const AP_REFERENCE = 'AP-285';

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_TIMEOUT = 'timeout';

    public const OUTCOME_ESCALATED = 'escalated_to_forge';

    public const OUTCOME_CANCELLED = 'cancelled';

    public const OUTCOME_REPAIR_BUDGET_EXHAUSTED = 'repair_budget_exhausted';

    public const ALLOWED_OUTCOMES = [
        self::OUTCOME_SUCCESS,
        self::OUTCOME_FAILED,
        self::OUTCOME_TIMEOUT,
        self::OUTCOME_ESCALATED,
        self::OUTCOME_CANCELLED,
        self::OUTCOME_REPAIR_BUDGET_EXHAUSTED,
    ];

    /**
     * @param  array<string,mixed>  $decision  The AP-284 decision envelope this receipt closes.
     * @param  array<string,mixed>  $metrics   Runtime metrics (duration_ms, repair_attempts, etc).
     * @return array{
     *   schema_version: string,
     *   ap_reference: string,
     *   outcome: string,
     *   issued_at: string,
     *   work_item_id: ?string,
     *   plan_hash: ?string,
     *   spec_hash: ?string,
     *   decision_ref: array{decision: ?string, actor: ?string, decided_at: ?string},
     *   metrics: array{duration_ms: int, repair_attempts: int, providers_consulted: list<string>},
     *   receipt_hash: string,
     *   provider_safe: bool
     * }
     */
    public function issue(string $outcome, array $decision, array $metrics = []): array
    {
        if (! in_array($outcome, self::ALLOWED_OUTCOMES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Outcome must be one of [%s], got "%s".', implode(',', self::ALLOWED_OUTCOMES), $outcome),
            );
        }

        $envelope = [
            'schema_version' => self::SCHEMA_VERSION,
            'ap_reference' => self::AP_REFERENCE,
            'outcome' => $outcome,
            'issued_at' => now()->toAtomString(),
            'work_item_id' => DurableExecutionFieldReader::stringOrNull($decision, 'work_item_id'),
            'plan_hash' => DurableExecutionFieldReader::stringOrNull($decision, 'plan_hash'),
            'spec_hash' => DurableExecutionFieldReader::stringOrNull($decision, 'spec_hash'),
            'decision_ref' => [
                'decision' => DurableExecutionFieldReader::stringOrNull($decision, 'decision'),
                'actor' => DurableExecutionFieldReader::stringOrNull($decision, 'actor'),
                'decided_at' => DurableExecutionFieldReader::stringOrNull($decision, 'decided_at'),
            ],
            'metrics' => [
                'duration_ms' => (int) ($metrics['duration_ms'] ?? 0),
                'repair_attempts' => (int) ($metrics['repair_attempts'] ?? 0),
                'providers_consulted' => $this->normaliseProviders($metrics['providers_consulted'] ?? []),
            ],
            'receipt_hash' => 'pending',
            'provider_safe' => true,
        ];

        // Receipt hash is over the canonical envelope minus the hash field
        // itself, mirroring AtlasDev/Schemas/Support/CanonicalHasher pattern.
        $envelope['receipt_hash'] = hash('sha256', json_encode(
            array_diff_key($envelope, ['receipt_hash' => true]),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: '');

        return $envelope;
    }

    /**
     * Convenience: receipt for a successful run.
     */
    public function success(array $decision, int $durationMs, int $repairAttempts = 0): array
    {
        return $this->issue(self::OUTCOME_SUCCESS, $decision, [
            'duration_ms' => $durationMs,
            'repair_attempts' => $repairAttempts,
        ]);
    }

    /**
     * Convenience: receipt for a failed run with metrics.
     */
    public function failure(array $decision, string $failureSignature, int $durationMs): array
    {
        $envelope = $this->issue(self::OUTCOME_FAILED, $decision, [
            'duration_ms' => $durationMs,
        ]);
        $envelope['failure_signature'] = $failureSignature;

        return $envelope;
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function normaliseProviders($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $clean = [];
        foreach ($raw as $entry) {
            if (is_string($entry) && $entry !== '') {
                $clean[] = $entry;
            }
        }

        return $clean;
    }
}
