<?php

namespace App\Services\Ai\Programming\BenchmarkReadiness;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Atlas Programming Benchmark Readiness Harness.
 *
 * Mission: PREPARE the benchmark methodology (suite manifest + rubrics +
 * readiness checks) for a future authorized comparison run.
 *
 * Hard refusals enforced here:
 *  - {@see run()} ALWAYS throws — even when authorization is supplied. The
 *    runtime is intentionally not wired in this slice.
 *  - Every output carries `benchmark_status = benchmark_not_run` and
 *    `human_authorization_required = true`.
 *  - The suite does NOT touch Claude Code, Codex, Gemini CLI or any other
 *    rival provider. `rival_placeholder` is a manifest slot, not a runtime
 *    binding.
 *
 * The harness is the canonical place to add new cases. Any new case_type
 * lands in {@see BenchmarkReadinessCaseCatalog} and is reviewed once.
 */
class BenchmarkReadinessHarness
{
    /**
     * Build the canonical readiness suite payload.
     *
     * @return array<string,mixed>
     */
    public function suite(): array
    {
        $cases = $this->normalizeCases(BenchmarkReadinessCaseCatalog::cases());
        $suiteIdSeed = [
            'schema_version' => BenchmarkReadinessCanon::SUITE_SCHEMA_VERSION,
            'case_ids' => array_map(static fn (array $c): string => (string) $c['case_id'], $cases),
            'case_types' => array_map(static fn (array $c): string => (string) $c['case_type'], $cases),
        ];
        $suiteHash = MissionCanonicalHash::sha256($suiteIdSeed);
        $suiteId = 'apbsr_'.substr($suiteHash, 0, 24);

        $readiness = $this->readinessChecks($cases);

        $payload = [
            'schema_version' => BenchmarkReadinessCanon::SUITE_SCHEMA_VERSION,
            'benchmark_suite_id' => $suiteId,
            'benchmark_status' => BenchmarkReadinessCanon::STATUS_NOT_RUN,
            'mode' => BenchmarkReadinessCanon::STATUS_READINESS_ONLY,
            'human_authorization_required' => true,
            'provider_slots' => BenchmarkReadinessCanon::PROVIDER_SLOTS,
            'case_manifest' => $cases,
            'case_count' => count($cases),
            'scoring_rubric_summary' => $this->scoringRubricSummary($cases),
            'required_telemetry_union' => $this->unionOf($cases, 'required_telemetry'),
            'required_evidence_union' => $this->unionOf($cases, 'required_evidence'),
            'allowed_tools_union' => $this->unionAllowedTools($cases),
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'rival_provider_invoked' => false,
                'allows_internal_measurement_claim' => true,
                'allows_external_superiority_claim' => false,
                'next_step_to_unlock_benchmark' => 'human_authorised_external_battery',
            ],
            'readiness_checks' => $readiness,
            'suite_hash' => $suiteHash,
            'note' => 'Atlas Programming Benchmark Readiness Harness. Suite is PREPARED, not executed. No rival provider is invoked.',
        ];

        return $payload;
    }

    /**
     * Validate a (possibly externally-supplied) suite payload against the
     * canon. Returns the same shape the harness produces with a `validation`
     * block attached. Useful for CI smoke and for future diffing.
     *
     * @param  array<string,mixed>|null  $payload  uses canonical suite when null
     * @return array<string,mixed>
     */
    public function validate(?array $payload = null): array
    {
        $payload = $payload ?? $this->suite();

        $violations = [];
        if (($payload['schema_version'] ?? null) !== BenchmarkReadinessCanon::SUITE_SCHEMA_VERSION) {
            $violations[] = 'suite_schema_version_mismatch';
        }
        if (($payload['benchmark_status'] ?? null) !== BenchmarkReadinessCanon::STATUS_NOT_RUN) {
            $violations[] = 'benchmark_status_must_be_not_run';
        }
        if (($payload['human_authorization_required'] ?? null) !== true) {
            $violations[] = 'human_authorization_required_must_be_true';
        }
        $providerSlots = array_values((array) ($payload['provider_slots'] ?? []));
        if ($providerSlots !== BenchmarkReadinessCanon::PROVIDER_SLOTS) {
            $violations[] = 'provider_slots_mismatch';
        }

        $cases = (array) ($payload['case_manifest'] ?? []);
        $expectedTypes = BenchmarkReadinessCanon::CASE_TYPES;
        $presentTypes = array_values(array_unique(array_map(
            static fn ($c): string => is_array($c) ? (string) ($c['case_type'] ?? '') : '',
            $cases,
        )));
        sort($expectedTypes);
        sort($presentTypes);
        if ($presentTypes !== $expectedTypes) {
            $violations[] = 'case_type_coverage_incomplete';
        }

        foreach ($cases as $idx => $case) {
            if (! is_array($case)) {
                $violations[] = "case[$idx]_not_array";

                continue;
            }
            $caseViolations = $this->validateCase($case);
            foreach ($caseViolations as $reason) {
                $violations[] = sprintf('case[%s]:%s', $case['case_id'] ?? $idx, $reason);
            }
        }

        $claimPolicy = (array) ($payload['claim_policy'] ?? []);
        if (($claimPolicy['benchmark_not_run'] ?? null) !== true) {
            $violations[] = 'claim_policy_benchmark_not_run_must_be_true';
        }
        if (($claimPolicy['allows_external_superiority_claim'] ?? null) !== false) {
            $violations[] = 'claim_policy_must_refuse_external_superiority_claim';
        }

        return array_merge($payload, [
            'validation' => [
                'schema_version' => 'atlas.programming.benchmark_readiness.validation.v1',
                'status' => $violations === []
                    ? BenchmarkReadinessCanon::READINESS_PASSED
                    : BenchmarkReadinessCanon::READINESS_BLOCKED,
                'violation_count' => count($violations),
                'violations' => $violations,
            ],
        ]);
    }

    /**
     * Lightweight readiness JSON — drops the heavy manifest to surface just
     * the gates + claim policy.
     *
     * @return array<string,mixed>
     */
    public function readinessJson(): array
    {
        $suite = $this->suite();

        return [
            'schema_version' => 'atlas.programming.benchmark_readiness.snapshot.v1',
            'benchmark_suite_id' => $suite['benchmark_suite_id'],
            'benchmark_status' => $suite['benchmark_status'],
            'mode' => $suite['mode'],
            'human_authorization_required' => true,
            'case_count' => $suite['case_count'],
            'case_types' => array_values(array_unique(array_map(
                static fn (array $c): string => (string) $c['case_type'],
                $suite['case_manifest'],
            ))),
            'readiness_checks' => $suite['readiness_checks'],
            'claim_policy' => $suite['claim_policy'],
            'suite_hash' => $suite['suite_hash'],
        ];
    }

    /**
     * Manifest JSON — the full case_manifest only.
     *
     * @return array<string,mixed>
     */
    public function manifestJson(): array
    {
        $suite = $this->suite();

        return [
            'schema_version' => 'atlas.programming.benchmark_readiness.manifest.v1',
            'benchmark_suite_id' => $suite['benchmark_suite_id'],
            'benchmark_status' => $suite['benchmark_status'],
            'human_authorization_required' => true,
            'case_manifest' => $suite['case_manifest'],
            'suite_hash' => $suite['suite_hash'],
        ];
    }

    /**
     * Refuse ANY attempt to execute the benchmark.
     *
     * Two refusal paths:
     *   - missing authorization (caller did not supply canon-shaped auth);
     *   - authorization-shape-ok but runtime not wired in this slice.
     *
     * @param  array<string,mixed>  $authorization
     *
     * @throws BenchmarkReadinessAuthorizationException always
     */
    public function run(array $authorization = []): never
    {
        $missing = $this->missingAuthorizationFields($authorization);
        if ($missing !== []) {
            throw BenchmarkReadinessAuthorizationException::missingAuthorization($missing);
        }

        throw BenchmarkReadinessAuthorizationException::runtimeNotWired();
    }

    /**
     * @param  array<string,mixed>  $authorization
     * @return array<int,string>
     */
    private function missingAuthorizationFields(array $authorization): array
    {
        $missing = [];
        foreach (BenchmarkReadinessCanon::REQUIRED_AUTHORIZATION_FIELDS as $field) {
            if (! array_key_exists($field, $authorization)) {
                $missing[] = $field;

                continue;
            }
            $value = $authorization[$field];
            if ($field === 'external_battery_authorized') {
                if ($value !== true) {
                    $missing[] = $field;
                }

                continue;
            }
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @param  array<int,array<string,mixed>>  $cases
     * @return array<int,array<string,mixed>>
     */
    private function normalizeCases(array $cases): array
    {
        $normalized = [];
        foreach ($cases as $case) {
            if (! is_array($case)) {
                continue;
            }
            $normalized[] = [
                'schema_version' => BenchmarkReadinessCanon::CASE_SCHEMA_VERSION,
                'case_id' => (string) $case['case_id'],
                'case_type' => (string) $case['case_type'],
                'input_prompt' => (string) $case['input_prompt'],
                'expected_artifacts' => array_values((array) $case['expected_artifacts']),
                'scoring_rubric' => (array) $case['scoring_rubric'],
                'required_telemetry' => array_values((array) $case['required_telemetry']),
                'required_evidence' => array_values((array) $case['required_evidence']),
                'allowed_tools' => array_values((array) $case['allowed_tools']),
                'tool_bundle' => (string) $case['tool_bundle'],
                'provider_slots' => (array) $case['provider_slots'],
                'risk_band' => (string) $case['risk_band'],
                'difficulty' => (string) $case['difficulty'],
                'human_authorization_required' => true,
                'benchmark_status' => BenchmarkReadinessCanon::STATUS_NOT_RUN,
                'notes' => (string) $case['notes'],
                'case_hash' => MissionCanonicalHash::sha256([
                    'case_id' => $case['case_id'],
                    'case_type' => $case['case_type'],
                    'input_prompt' => $case['input_prompt'],
                    'expected_artifacts' => $case['expected_artifacts'],
                    'scoring_rubric' => $case['scoring_rubric'],
                    'required_telemetry' => $case['required_telemetry'],
                    'required_evidence' => $case['required_evidence'],
                    'allowed_tools' => $case['allowed_tools'],
                    'provider_slots' => $case['provider_slots'],
                    'risk_band' => $case['risk_band'],
                    'difficulty' => $case['difficulty'],
                ]),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<int,string>
     */
    private function validateCase(array $case): array
    {
        $violations = [];
        foreach (['case_id', 'case_type', 'input_prompt', 'tool_bundle', 'risk_band', 'difficulty'] as $field) {
            if (! is_string($case[$field] ?? null) || trim((string) $case[$field]) === '') {
                $violations[] = "missing_or_empty_$field";
            }
        }
        if (! in_array($case['case_type'] ?? null, BenchmarkReadinessCanon::CASE_TYPES, true)) {
            $violations[] = 'unknown_case_type';
        }
        if (! in_array($case['risk_band'] ?? null, BenchmarkReadinessCanon::RISK_BANDS, true)) {
            $violations[] = 'unknown_risk_band';
        }
        if (! in_array($case['difficulty'] ?? null, BenchmarkReadinessCanon::DIFFICULTIES, true)) {
            $violations[] = 'unknown_difficulty';
        }
        if (($case['benchmark_status'] ?? null) !== BenchmarkReadinessCanon::STATUS_NOT_RUN) {
            $violations[] = 'benchmark_status_must_be_not_run';
        }
        if (($case['human_authorization_required'] ?? null) !== true) {
            $violations[] = 'human_authorization_required_must_be_true';
        }
        if (($case['expected_artifacts'] ?? []) === []) {
            $violations[] = 'expected_artifacts_empty';
        }
        if (($case['required_telemetry'] ?? []) === []) {
            $violations[] = 'required_telemetry_empty';
        }
        if (($case['required_evidence'] ?? []) === []) {
            $violations[] = 'required_evidence_empty';
        }
        if (($case['allowed_tools'] ?? []) === []) {
            $violations[] = 'allowed_tools_empty';
        }
        $slots = array_values(array_keys((array) ($case['provider_slots'] ?? [])));
        sort($slots);
        $expectedSlots = BenchmarkReadinessCanon::PROVIDER_SLOTS;
        sort($expectedSlots);
        if ($slots !== $expectedSlots) {
            $violations[] = 'provider_slots_incomplete';
        }
        if (($case['provider_slots']['rival_placeholder'] ?? null) !== 'unbound_no_run') {
            $violations[] = 'rival_placeholder_must_be_unbound_no_run';
        }
        $rubric = (array) ($case['scoring_rubric'] ?? []);
        if (($rubric['schema_version'] ?? null) !== BenchmarkReadinessCanon::RUBRIC_SCHEMA_VERSION) {
            $violations[] = 'rubric_schema_mismatch';
        }
        $weights = (array) ($rubric['weights'] ?? []);
        if ($weights === []) {
            $violations[] = 'rubric_weights_empty';
        } else {
            foreach ($weights as $dim => $w) {
                if (! in_array($dim, BenchmarkReadinessCanon::SCORING_DIMENSIONS, true)) {
                    $violations[] = "rubric_unknown_dimension:$dim";
                }
                if (! is_numeric($w) || (float) $w < 0.0 || (float) $w > 1.0) {
                    $violations[] = "rubric_weight_out_of_range:$dim";
                }
            }
            $sum = array_sum(array_map('floatval', $weights));
            if (abs($sum - 1.0) > 0.001) {
                $violations[] = 'rubric_weights_do_not_sum_to_one';
            }
        }
        $threshold = $rubric['pass_threshold'] ?? null;
        if (! is_numeric($threshold) || (float) $threshold < 0.0 || (float) $threshold > 1.0) {
            $violations[] = 'rubric_pass_threshold_invalid';
        }
        if (! is_string($case['case_hash'] ?? null) || strlen((string) $case['case_hash']) !== 64) {
            $violations[] = 'case_hash_missing_or_malformed';
        }

        return $violations;
    }

    /**
     * @param  array<int,array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    private function readinessChecks(array $cases): array
    {
        $invariants = [];

        $invariants[] = $this->invariant(
            'every_case_type_present',
            $this->everyCaseTypePresent($cases),
            'one canonical case per case_type',
        );
        $invariants[] = $this->invariant(
            'every_case_has_rubric_summing_to_one',
            $this->everyRubricNormalized($cases),
            'rubric.weights ∈ [0,1] and sum to 1.0 ± 0.001',
        );
        $invariants[] = $this->invariant(
            'every_case_declares_required_evidence',
            $this->everyCaseHasRequiredEvidence($cases),
            'required_evidence non-empty per case',
        );
        $invariants[] = $this->invariant(
            'every_case_declares_required_telemetry',
            $this->everyCaseHasRequiredTelemetry($cases),
            'required_telemetry non-empty per case',
        );
        $invariants[] = $this->invariant(
            'rival_placeholder_unbound',
            $this->rivalPlaceholderUnbound($cases),
            'rival_placeholder must be unbound_no_run for every case',
        );
        $invariants[] = $this->invariant(
            'no_destructive_tools',
            $this->noDestructiveTools($cases),
            'allowed_tools never include destructive operations',
        );
        $invariants[] = $this->invariant(
            'authorization_required',
            true,
            'human_authorization_required=true on suite and every case',
        );
        $invariants[] = $this->invariant(
            'runtime_not_wired',
            true,
            'run() refuses even with valid-shaped authorization in this slice',
        );

        $failedCount = 0;
        foreach ($invariants as $inv) {
            if ($inv['status'] !== BenchmarkReadinessCanon::READINESS_PASSED) {
                $failedCount++;
            }
        }

        return [
            'schema_version' => 'atlas.programming.benchmark_readiness.checks.v1',
            'status' => $failedCount === 0
                ? BenchmarkReadinessCanon::READINESS_PASSED
                : BenchmarkReadinessCanon::READINESS_BLOCKED,
            'invariants' => $invariants,
            'invariant_count' => count($invariants),
            'failed_count' => $failedCount,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function invariant(string $id, bool $passed, string $reason): array
    {
        return [
            'id' => $id,
            'status' => $passed
                ? BenchmarkReadinessCanon::READINESS_PASSED
                : BenchmarkReadinessCanon::READINESS_BLOCKED,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $cases
     */
    private function everyCaseTypePresent(array $cases): bool
    {
        $expected = BenchmarkReadinessCanon::CASE_TYPES;
        $present = array_values(array_unique(array_map(
            static fn (array $c): string => (string) $c['case_type'],
            $cases,
        )));
        sort($expected);
        sort($present);

        return $expected === $present;
    }

    /**
     * @param  array<int,array<string,mixed>>  $cases
     */
    private function everyRubricNormalized(array $cases): bool
    {
        foreach ($cases as $case) {
            $weights = (array) ($case['scoring_rubric']['weights'] ?? []);
            if ($weights === []) {
                return false;
            }
            if (abs(array_sum(array_map('floatval', $weights)) - 1.0) > 0.001) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int,array<string,mixed>>  $cases
     */
    private function everyCaseHasRequiredEvidence(array $cases): bool
    {
        foreach ($cases as $case) {
            if ((array) ($case['required_evidence'] ?? []) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int,array<string,mixed>>  $cases
     */
    private function everyCaseHasRequiredTelemetry(array $cases): bool
    {
        foreach ($cases as $case) {
            if ((array) ($case['required_telemetry'] ?? []) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int,array<string,mixed>>  $cases
     */
    private function rivalPlaceholderUnbound(array $cases): bool
    {
        foreach ($cases as $case) {
            if (($case['provider_slots']['rival_placeholder'] ?? null) !== 'unbound_no_run') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int,array<string,mixed>>  $cases
     */
    private function noDestructiveTools(array $cases): bool
    {
        $forbidden = ['code.patch.apply', 'file.delete', 'force_push', 'rm_rf', 'db.migrate.rollback'];
        foreach ($cases as $case) {
            foreach ((array) ($case['allowed_tools'] ?? []) as $tool) {
                if (in_array((string) $tool, $forbidden, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<int,array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    private function scoringRubricSummary(array $cases): array
    {
        $byType = [];
        foreach ($cases as $case) {
            $type = (string) $case['case_type'];
            $rubric = (array) ($case['scoring_rubric'] ?? []);
            $byType[$type] = [
                'dimensions' => array_values((array) ($rubric['dimensions'] ?? [])),
                'pass_threshold' => $rubric['pass_threshold'] ?? null,
            ];
        }

        return [
            'schema_version' => BenchmarkReadinessCanon::RUBRIC_SCHEMA_VERSION,
            'dimensions_universe' => BenchmarkReadinessCanon::SCORING_DIMENSIONS,
            'by_case_type' => $byType,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $cases
     * @return array<int,string>
     */
    private function unionOf(array $cases, string $key): array
    {
        $union = [];
        foreach ($cases as $case) {
            foreach ((array) ($case[$key] ?? []) as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $union[$entry] = true;
                }
            }
        }
        $keys = array_keys($union);
        sort($keys);

        return $keys;
    }

    /**
     * @param  array<int,array<string,mixed>>  $cases
     * @return array<int,string>
     */
    private function unionAllowedTools(array $cases): array
    {
        return $this->unionOf($cases, 'allowed_tools');
    }
}
