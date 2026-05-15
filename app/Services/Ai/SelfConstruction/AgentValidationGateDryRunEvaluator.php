<?php

namespace App\Services\Ai\SelfConstruction;

/**
 * Evaluates a validation plan against a synthetic input map and returns a
 * deterministic dry-run result set. Never executes real commands.
 *
 * Each gate in the plan is matched to a synthetic input shape:
 *   - synthetic_inputs[gate_id] = [
 *         'status' => 'pass' | 'fail' | 'warn' | 'skip',
 *         'evidence_artifact' => string,           // synthetic artifact tag
 *         'detail' => array<string, mixed>,        // optional structured detail
 *     ]
 *
 * If a gate has no synthetic input, the evaluator returns 'unknown' for it.
 * The aggregate result is shaped to be consumed by the failure classifier,
 * the repair builder and the certification service.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentValidationGateDryRunEvaluator
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_validation_gate_dry_run.v1';

    public const MODE = 'read_only_agent_validation_gate_dry_run';

    public const ALLOWED_STATUSES = ['pass', 'fail', 'warn', 'skip'];

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, array<string, mixed>>  $syntheticInputs
     * @return array<string, mixed>
     */
    public function evaluate(array $plan, array $syntheticInputs = []): array
    {
        $orderedRuns = (array) ($plan['ordered_runs'] ?? []);
        $planId = (string) ($plan['plan_id'] ?? 'plan-unknown');
        $planHash = (string) ($plan['plan_hash'] ?? '');

        $results = [];
        $counts = [
            'pass' => 0,
            'fail' => 0,
            'warn' => 0,
            'skip' => 0,
            'unknown' => 0,
        ];

        $aborted = false;
        $abortGateId = null;

        foreach ($orderedRuns as $run) {
            $gateId = (string) ($run['gate_id'] ?? '');
            $blocking = (bool) ($run['blocking'] ?? false);
            $input = $syntheticInputs[$gateId] ?? null;

            if ($aborted) {
                $results[] = $this->makeResult(
                    gateId: $gateId,
                    run: $run,
                    status: 'skip',
                    reason: 'previous_blocking_gate_failed',
                    evidence: 'aborted_after_'.$abortGateId,
                    detail: ['skipped_due_to' => $abortGateId],
                );
                $counts['skip']++;

                continue;
            }

            if ($input === null) {
                $results[] = $this->makeResult(
                    gateId: $gateId,
                    run: $run,
                    status: 'unknown',
                    reason: 'no_synthetic_input',
                    evidence: 'missing_synthetic_input',
                    detail: [],
                );
                $counts['unknown']++;
                if ($blocking) {
                    $aborted = true;
                    $abortGateId = $gateId;
                }

                continue;
            }

            $status = $this->normalizeStatus((string) ($input['status'] ?? 'unknown'));
            $evidenceArtifact = (string) ($input['evidence_artifact'] ?? 'unspecified_artifact');
            $detail = (array) ($input['detail'] ?? []);

            if ($status === 'unknown') {
                $counts['unknown']++;
                $results[] = $this->makeResult(
                    gateId: $gateId,
                    run: $run,
                    status: 'unknown',
                    reason: 'unsupported_synthetic_status',
                    evidence: $evidenceArtifact,
                    detail: $detail,
                );
                if ($blocking) {
                    $aborted = true;
                    $abortGateId = $gateId;
                }

                continue;
            }

            $counts[$status]++;
            $reason = match ($status) {
                'pass' => 'expected_artifact_observed',
                'fail' => 'expected_artifact_missing_or_failed',
                'warn' => 'gate_emitted_warning',
                'skip' => 'gate_skip_condition_active',
                default => 'unknown',
            };
            $results[] = $this->makeResult(
                gateId: $gateId,
                run: $run,
                status: $status,
                reason: $reason,
                evidence: $evidenceArtifact,
                detail: $detail,
            );

            if ($status === 'fail' && $blocking) {
                $aborted = true;
                $abortGateId = $gateId;
            }
        }

        $overall = $this->classifyOverall($counts, $aborted, count($orderedRuns));
        $resultSetId = $this->resultSetId($results, $planHash);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'evaluated',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'result_set_id' => $resultSetId,
            'plan_id' => $planId,
            'plan_hash' => $planHash,
            'overall_status' => $overall,
            'aborted' => $aborted,
            'aborted_at_gate' => $abortGateId,
            'counts' => $counts,
            'evaluations' => $results,
            'failed_gate_ids' => $this->idsByStatus($results, 'fail'),
            'warn_gate_ids' => $this->idsByStatus($results, 'warn'),
            'skipped_gate_ids' => $this->idsByStatus($results, 'skip'),
            'unknown_gate_ids' => $this->idsByStatus($results, 'unknown'),
            'pass_gate_ids' => $this->idsByStatus($results, 'pass'),
            'evaluation_hash' => $this->hashOf($results, $planHash),
            'runtime_safety' => [
                'runtime_safety_all_false' => true,
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
            ],
        ];
    }

    private function normalizeStatus(string $raw): string
    {
        $raw = strtolower($raw);

        return in_array($raw, self::ALLOWED_STATUSES, true) ? $raw : 'unknown';
    }

    /** @param array<string,int> $counts */
    private function classifyOverall(array $counts, bool $aborted, int $totalRuns): string
    {
        if ($totalRuns === 0) {
            return 'empty';
        }
        if ($counts['fail'] > 0 || $aborted) {
            return 'failed';
        }
        if ($counts['unknown'] > 0) {
            return 'inconclusive';
        }
        if ($counts['warn'] > 0) {
            return 'passed_with_warnings';
        }
        if ($counts['pass'] === 0 && $counts['skip'] === $totalRuns) {
            return 'all_skipped';
        }

        return 'passed';
    }

    /** @param array<string,mixed> $run */
    private function makeResult(
        string $gateId,
        array $run,
        string $status,
        string $reason,
        string $evidence,
        array $detail,
    ): array {
        return [
            'gate_id' => $gateId,
            'gate_type' => (string) ($run['gate_type'] ?? 'unknown'),
            'severity' => (string) ($run['severity'] ?? 'unknown'),
            'blocking' => (bool) ($run['blocking'] ?? false),
            'expected_artifact' => (string) ($run['expected_artifact'] ?? 'unspecified'),
            'observed_status' => $status,
            'observed_reason' => $reason,
            'observed_evidence_artifact' => $evidence,
            'detail' => $detail,
            'is_failure' => $status === 'fail',
            'is_skip' => $status === 'skip',
            'is_unknown' => $status === 'unknown',
            'is_warn' => $status === 'warn',
            'is_pass' => $status === 'pass',
        ];
    }

    /**
     * @param  array<int, array<string,mixed>>  $results
     * @return array<int, string>
     */
    private function idsByStatus(array $results, string $status): array
    {
        $out = [];
        foreach ($results as $r) {
            if ($r['observed_status'] === $status) {
                $out[] = $r['gate_id'];
            }
        }

        return $out;
    }

    /** @param array<int, array<string,mixed>> $results */
    private function hashOf(array $results, string $planHash): string
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'plan_hash' => $planHash,
            'results' => $results,
        ];

        return hash('sha256', (string) json_encode($payload));
    }

    /** @param array<int, array<string,mixed>> $results */
    private function resultSetId(array $results, string $planHash): string
    {
        return 'result-'.substr($this->hashOf($results, $planHash), 0, 16);
    }
}
