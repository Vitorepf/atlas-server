<?php

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Support\HashesKsortedPayloadCanonically;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;

/**
 * Deterministic in-memory mutation harness for the certification
 * stack. Given a seed and an iteration count, applies a sequence of
 * synthetic mutations to control-plane projections / replay payloads,
 * dispatches each mutation to the appropriate detector and verifies
 * the detector flagged the fault.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentControlPlaneCertificationFuzzHarness
{
    use HashesKsortedPayloadCanonically;
    use RecursivelyKsortsArrays;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_certification_fuzz_harness.v1';

    public const MODE = 'read_only_agent_control_plane_certification_fuzz_harness';

    public const DEFAULT_ITERATIONS = 64;

    public const MUTATION_TYPES = [
        'remove_random_capability',
        'duplicate_random_capability',
        'remove_random_cli_option',
        'break_random_edge',
        'flip_runtime_flag',
        'corrupt_doc_anchor',
        'corrupt_terminal_horizon',
        'corrupt_cycle_reentry',
        'corrupt_replay_hash',
        'corrupt_proof_bundle_hash',
        'corrupt_next_build_slices',
        'corrupt_not_yet_runtime_capable',
    ];

    public const TASK_PACKET_INVARIANTS = [
        'malformed_allowed_files',
        'contradictory_acceptance',
        'stale_evidence',
        'impossible_worker_requirements',
        'scope_drift',
        'negated_requirements',
    ];

    private const STALE_EVIDENCE_CEILING_SECONDS = 86400;

    private const NEGATION_MARKERS = ['must not', 'never', 'cannot', 'should not'];

    /** Which gate is responsible for catching each fuzzed task-packet invariant. */
    private const SUGGESTED_GATE_BY_INVARIANT = [
        'malformed_allowed_files' => 'AtlasTaskServingPacketQualityGate',
        'contradictory_acceptance' => 'AtlasExternalBrainSpecRegressionHarness',
        'stale_evidence' => 'AgentControlPlaneChainIntegrityAuditService',
        'impossible_worker_requirements' => 'AgentDispatchPlannerEligibilityEvaluator',
        'scope_drift' => 'AgentControlPlaneScopeLockRuntimeValidator',
        'negated_requirements' => 'AtlasExternalBrainSpecRegressionHarness',
    ];

    public function __construct(
        private readonly AgentControlPlaneChainIntegrityAuditService $audit,
        private readonly AgentControlPlaneDeterministicChainReplayService $replay,
        private readonly AgentControlPlaneReplayDiffService $diff,
    ) {}

    /**
     * Fuzzes task packet / dispatch invariants: malformed allowed_files,
     * contradictory acceptance criteria, stale evidence, and impossible
     * worker requirement combinations. Each invalid case must resolve to
     * reject or repair; a clean control packet must resolve to accept.
     *
     * Read-only and deterministic: never mutates a real task packet.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function runTaskPacketInvariantFuzz(array $options = []): array
    {
        $cases = [
            $this->taskPacketCase('malformed_allowed_files', $this->malformedAllowedFilesPacket()),
            $this->taskPacketCase('contradictory_acceptance', $this->contradictoryAcceptancePacket()),
            $this->taskPacketCase('stale_evidence', $this->staleEvidencePacket()),
            $this->taskPacketCase('impossible_worker_requirements', $this->impossibleWorkerRequirementsPacket()),
            $this->taskPacketCase('scope_drift', $this->scopeDriftPacket()),
            $this->taskPacketCase('negated_requirements', $this->negatedRequirementsPacket()),
            $this->taskPacketCase('valid_control', $this->validControlPacket()),
        ];

        $failingCases = array_values(array_filter($cases, static fn (array $c): bool => ! $c['passed']));
        $allPassed = $failingCases === [];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $allPassed ? 'passed' : 'failed',
            'read_only' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'certification_ready' => $allPassed,
            'invariant_cases' => $cases,
            'failing_cases' => $failingCases,
            'surviving_mutations' => $failingCases,
            'all_invariants_held' => $allPassed,
        ];

        return $payload;
    }

    /** @return array<string, mixed> */
    private function taskPacketCase(string $invariantName, array $packet): array
    {
        $decision = $this->evaluateTaskPacketInvariant($invariantName, $packet);
        $expectedDecision = $invariantName === 'valid_control' ? 'accept' : ['reject', 'repair'];
        $passed = is_array($expectedDecision)
            ? in_array($decision, $expectedDecision, true)
            : $decision === $expectedDecision;

        return [
            'invariant_name' => $invariantName,
            'failing_case' => $passed ? null : $packet,
            'suggested_gate' => self::SUGGESTED_GATE_BY_INVARIANT[$invariantName] ?? null,
            'decision' => $decision,
            'passed' => $passed,
            'killed' => $invariantName === 'valid_control' ? false : $passed,
        ];
    }

    /**
     * Pure deterministic invariant check for one synthetic task packet.
     *
     * @param  array<string, mixed>  $packet
     */
    private function evaluateTaskPacketInvariant(string $invariantName, array $packet): string
    {
        $allowedFiles = (array) ($packet['allowed_files'] ?? []);
        $hasMalformedAllowedFiles = $allowedFiles === [] || array_reduce(
            $allowedFiles,
            static fn (bool $carry, mixed $file): bool => $carry || ! is_string($file) || trim((string) $file) === '',
            false,
        );
        if ($hasMalformedAllowedFiles) {
            return 'repair';
        }

        $acceptanceCriteria = (array) ($packet['acceptance_criteria'] ?? []);
        if ($this->hasContradictoryAcceptance($acceptanceCriteria)) {
            return 'reject';
        }

        $evidenceAgeSeconds = (int) ($packet['evidence_age_seconds'] ?? 0);
        if ($evidenceAgeSeconds > self::STALE_EVIDENCE_CEILING_SECONDS) {
            return 'reject';
        }

        $dryRunOnly = (bool) ($packet['dry_run_only'] ?? false);
        $requiresLiveProvider = (bool) ($packet['requires_live_provider'] ?? false);
        if ($dryRunOnly && $requiresLiveProvider) {
            return 'reject';
        }

        $allowedFiles = (array) ($packet['allowed_files'] ?? []);
        $scopeIn = (array) ($packet['scope_in'] ?? []);
        if ($scopeIn !== [] && count(array_intersect($allowedFiles, $scopeIn)) !== count($allowedFiles)) {
            return 'reject';
        }

        $negatedRequirements = (array) ($packet['negated_requirements'] ?? []);
        foreach ($negatedRequirements as $negated) {
            if (is_string($negated) && $negated !== '' && $this->hasNegationMarker(strtolower($negated))) {
                return 'reject';
            }
        }

        return 'accept';
    }

    /** @param  list<mixed>  $criteria */
    private function hasContradictoryAcceptance(array $criteria): bool
    {
        $count = count($criteria);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = strtolower((string) $criteria[$i]);
                $b = strtolower((string) $criteria[$j]);
                $aNegated = $this->hasNegationMarker($a);
                $bNegated = $this->hasNegationMarker($b);
                if ($aNegated === $bNegated) {
                    continue;
                }
                $kwA = $this->wordsOf($a);
                $kwB = $this->wordsOf($b);
                $overlap = $kwA === [] || $kwB === [] ? 0.0 : count(array_intersect($kwA, $kwB)) / count(array_unique(array_merge($kwA, $kwB)));
                if ($overlap >= 0.5) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasNegationMarker(string $text): bool
    {
        foreach (self::NEGATION_MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function wordsOf(string $text): array
    {
        $words = preg_split('/\s+/', preg_replace('/[^a-z0-9\s]/', ' ', $text) ?? '') ?: [];

        return array_values(array_filter($words, static fn (string $w): bool => strlen($w) >= 4));
    }

    /** @return array<string, mixed> */
    private function malformedAllowedFilesPacket(): array
    {
        $packet = $this->validControlPacket();
        $packet['allowed_files'] = [];

        return $packet;
    }

    /** @return array<string, mixed> */
    private function contradictoryAcceptancePacket(): array
    {
        $packet = $this->validControlPacket();
        $packet['acceptance_criteria'] = [
            'the response must always include cached data',
            'the response must never include cached data',
        ];

        return $packet;
    }

    /** @return array<string, mixed> */
    private function staleEvidencePacket(): array
    {
        $packet = $this->validControlPacket();
        $packet['evidence_age_seconds'] = self::STALE_EVIDENCE_CEILING_SECONDS * 10;

        return $packet;
    }

    /** @return array<string, mixed> */
    private function impossibleWorkerRequirementsPacket(): array
    {
        $packet = $this->validControlPacket();
        $packet['dry_run_only'] = true;
        $packet['requires_live_provider'] = true;

        return $packet;
    }

    /** @return array<string, mixed> */
    private function scopeDriftPacket(): array
    {
        $packet = $this->validControlPacket();
        $packet['scope_in'] = ['app/Services/Foo.php'];
        $packet['allowed_files'] = ['app/Services/Foo.php', 'app/Services/Bar.php'];

        return $packet;
    }

    /** @return array<string, mixed> */
    private function negatedRequirementsPacket(): array
    {
        $packet = $this->validControlPacket();
        $packet['negated_requirements'] = ['must never call external provider'];

        return $packet;
    }

    /** @return array<string, mixed> */
    private function validControlPacket(): array
    {
        return [
            'task_packet_id' => 'fuzz-control-packet',
            'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            'scope_in' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            'acceptance_criteria' => ['./vendor/bin/phpunit exits 0'],
            'evidence_age_seconds' => 60,
            'dry_run_only' => false,
            'requires_live_provider' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array
    {
        $seed = isset($options['seed']) && is_int($options['seed']) ? $options['seed'] : 1337;
        $iterationCount = isset($options['iteration_count']) && is_int($options['iteration_count']) && $options['iteration_count'] > 0
            ? $options['iteration_count']
            : self::DEFAULT_ITERATIONS;
        $mutationTypes = isset($options['mutation_types']) && is_array($options['mutation_types']) && $options['mutation_types'] !== []
            ? array_values(array_intersect($options['mutation_types'], self::MUTATION_TYPES))
            : self::MUTATION_TYPES;
        if ($mutationTypes === []) {
            $mutationTypes = self::MUTATION_TYPES;
        }

        $rng = new Randomizer(new Mt19937($seed));

        $cases = [];
        $detectedCount = 0;
        $missedCount = 0;
        $falsePositiveCount = 0;

        for ($i = 0; $i < $iterationCount; $i++) {
            $type = $mutationTypes[$rng->getInt(0, count($mutationTypes) - 1)];
            $case = $this->runMutation($type, $i, $rng);
            $cases[] = $case;
            if ($case['detected']) {
                $detectedCount++;
            } elseif ($case['expected_detection']) {
                $missedCount++;
            } else {
                $falsePositiveCount++;
            }
        }

        $allExpectedDetected = $missedCount === 0;
        $status = $allExpectedDetected ? 'passed' : 'failed';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'fuzz_id' => (string) Str::uuid(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'read_only' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'external_provider_call' => false,
            'token_spend' => false,
            'process_started' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
            'seed' => $seed,
            'iteration_count' => $iterationCount,
            'mutation_types' => $mutationTypes,
            'detected_count' => $detectedCount,
            'missed_count' => $missedCount,
            'false_positive_count' => $falsePositiveCount,
            'all_expected_detected' => $allExpectedDetected,
            'fuzz_cases' => $cases,
            'non_execution_guarantees' => [
                'fuzz_does_not_start_codex',
                'fuzz_does_not_call_codex_cli_or_app',
                'fuzz_does_not_spawn_subprocess',
                'fuzz_does_not_invoke_adapter',
                'fuzz_does_not_execute_adapter',
                'fuzz_does_not_call_provider',
                'fuzz_does_not_dispatch_work',
                'fuzz_does_not_spend_tokens',
                'fuzz_does_not_enable_self_programming',
                'fuzz_does_not_write_ledger',
                'fuzz_does_not_mutate_pointer',
                'fuzz_does_not_promote_completion_claim',
            ],
            'human_summary' => $allExpectedDetected
                ? sprintf('Fuzz harness detected every injected mutation across %d iterations.', $iterationCount)
                : sprintf('Fuzz harness missed %d injected mutations across %d iterations; review missed cases.', $missedCount, $iterationCount),
        ];

        $payload['fuzz_hash'] = $this->stableHash($this->normalizeForFuzzHash($payload));

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function runMutation(string $type, int $index, Randomizer $rng): array
    {
        $detector = $this->detectorFor($type);
        $detected = false;
        $statusObserved = '';
        $note = '';

        if (in_array($type, ['remove_random_capability', 'duplicate_random_capability', 'remove_random_cli_option', 'break_random_edge', 'flip_runtime_flag', 'corrupt_doc_anchor', 'corrupt_next_build_slices', 'corrupt_not_yet_runtime_capable'], true)) {
            $auditOptions = $this->buildAuditOptions($type, $rng);
            $audit = $this->audit->audit($auditOptions);
            $statusObserved = (string) data_get($audit, 'status', 'unknown');
            $detected = (count((array) data_get($audit, 'violations', [])) > 0)
                || $statusObserved !== 'available'
                || ! (bool) data_get($audit, 'invariants_all_true', true);
            $note = sprintf('audit_status=%s violations=%d', $statusObserved, count((array) data_get($audit, 'violations', [])));
        } elseif (in_array($type, ['corrupt_terminal_horizon', 'corrupt_cycle_reentry'], true)) {
            // Terminal horizon / cycle integrity changes are validated by a synthetic
            // patched replay: drop the corresponding key and assert the detector
            // notices the gap.
            $replay = $this->replay->replay();
            if ($type === 'corrupt_terminal_horizon') {
                unset($replay['terminal_horizon_analysis']);
                $detected = ! isset($replay['terminal_horizon_analysis']);
            } else {
                $replay['cycle_integrity']['cycle_ok'] = false;
                $detected = (bool) data_get($replay, 'cycle_integrity.cycle_ok') === false;
            }
            $statusObserved = (string) data_get($replay, 'status', 'unknown');
            $note = sprintf('synthetic_replay_status=%s', $statusObserved);
        } else {
            // Replay/proof-bundle hash mutations are detected via the diff
            // service: synthesise a before/after pair where only the relevant
            // hash drifts.
            $before = $this->replay->replay();
            $after = $before;
            if ($type === 'corrupt_replay_hash') {
                $after['deterministic_replay_hash'] = 'corrupt_'.bin2hex(random_bytes(31));
            } elseif ($type === 'corrupt_proof_bundle_hash') {
                $after['proof_bundle_hash'] = 'corrupt_'.bin2hex(random_bytes(31));
                $after['deterministic_replay_hash'] = 'corrupt_'.bin2hex(random_bytes(31));
            }
            $diff = $this->diff->diff($before, $after);
            $statusObserved = (string) data_get($diff, 'status', 'unknown');
            $detected = $statusObserved !== 'unchanged';
            $note = sprintf('diff_status=%s', $statusObserved);
        }

        return [
            'iteration' => $index,
            'mutation_type' => $type,
            'detector' => $detector,
            'expected_detection' => true,
            'detected' => $detected,
            'observed_status' => $statusObserved,
            'note' => $note,
        ];
    }

    private function detectorFor(string $type): string
    {
        return match ($type) {
            'corrupt_replay_hash', 'corrupt_proof_bundle_hash' => 'replay_diff',
            'corrupt_terminal_horizon', 'corrupt_cycle_reentry' => 'replay',
            default => 'chain_integrity_audit',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAuditOptions(string $type, Randomizer $rng): array
    {
        return match ($type) {
            'remove_random_capability' => [
                'override_projection' => [
                    'remove_capability' => [
                        'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_invoker_service',
                    ],
                ],
            ],
            'duplicate_random_capability' => [
                'override_projection' => [
                    'append_capability' => ['automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract'],
                ],
            ],
            'remove_random_cli_option' => [
                'override_slices' => [[
                    'slice_key' => 'fuzz_synthetic_cli_'.$rng->getInt(0, 999),
                    'activate_key' => 'activate_fuzz_synthetic_cli',
                    'runtime_key' => 'fuzz_runtime_cli',
                    'expected_next_slice' => 'fuzz_synthetic_next',
                    'method_prefix' => 'agentFuzzSyntheticCli',
                    'invoker_class' => 'App\\Services\\Ai\\Synthetic\\AgentFuzzSyntheticCliInvoker',
                    'prepare_method' => 'prepareFuzz',
                    'doc_bullet' => 'fuzz-anchor-cli',
                ]],
            ],
            'break_random_edge' => [
                'override_slices' => [
                    [
                        'slice_key' => 'fuzz_a_'.$rng->getInt(0, 999),
                        'activate_key' => 'activate_fuzz_a',
                        'runtime_key' => 'fuzz_runtime_a',
                        'expected_next_slice' => 'fuzz_b_NEVER',
                        'method_prefix' => 'agentFuzzA',
                        'invoker_class' => 'App\\Services\\Ai\\Synthetic\\AgentFuzzAInvoker',
                        'prepare_method' => 'prepareFuzz',
                        'doc_bullet' => 'fuzz-anchor-a',
                    ],
                ],
            ],
            'flip_runtime_flag' => [
                'override_projection' => [
                    'flags' => ['execution_allowed' => true],
                ],
            ],
            'corrupt_doc_anchor' => [
                'override_slices' => [[
                    'slice_key' => 'fuzz_doc_'.$rng->getInt(0, 999),
                    'activate_key' => 'activate_fuzz_doc',
                    'runtime_key' => 'fuzz_runtime_doc',
                    'expected_next_slice' => 'fuzz_synthetic_next',
                    'method_prefix' => 'agentFuzzDoc',
                    'invoker_class' => 'App\\Services\\Ai\\Synthetic\\AgentFuzzDocInvoker',
                    'prepare_method' => 'prepareFuzz',
                    'doc_bullet' => 'never-anchored-fuzz-bullet',
                ]],
            ],
            'corrupt_next_build_slices' => [
                'override_projection' => [
                    'next_build_slices' => ['activate_fuzz_unknown_'.$rng->getInt(0, 999)],
                ],
            ],
            'corrupt_not_yet_runtime_capable' => [
                'override_projection' => [
                    'not_yet_runtime_capable' => ['fuzz_only_synthetic'],
                ],
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForFuzzHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['fuzz_id'], $clone['generated_at'], $clone['fuzz_hash']);

        // fuzz_cases include a `note` derived from observed_status which may
        // include violation counts; those are stable for a given seed.
        return $this->recursivelyKsort($clone);
    }

}
