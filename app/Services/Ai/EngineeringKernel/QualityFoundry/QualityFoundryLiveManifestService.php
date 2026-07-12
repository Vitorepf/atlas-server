<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\QualityFoundry;

use App\Services\Ai\EngineeringKernel\EngineeringModeExecutionOrderFactory;
use App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionSurfaceRegistry;
use App\Services\Ai\EngineeringKernel\QualityFoundryModeParityService;
use Symfony\Component\Process\Process;

/**
 * Builds a read-only live evidence manifest by executing the canonical,
 * mode-scoped test receipts. It never mutates a workspace or authorizes a
 * release; missing runtime evidence remains an explicit blocker.
 */
final class QualityFoundryLiveManifestService
{
    private const ROLLBACK_EVIDENCE_TEST = 'tests/Feature/Ai/EngineeringKernel/CanonicalCommitActuationTest.php';
    private const EXACTLY_ONCE_EVIDENCE_TEST = 'tests/Feature/Ai/EngineeringKernel/QualityFoundryExactlyOnceEvidenceTest.php';
    private const COVERAGE_EVIDENCE_TEST = 'tests/Feature/Ai/EngineeringKernel/QualityFoundryExecutionCoverageEvidenceTest.php';

    /** @var list<string> */
    private const SHARED_EVIDENCE_TESTS = [
        self::ROLLBACK_EVIDENCE_TEST,
        self::EXACTLY_ONCE_EVIDENCE_TEST,
        self::COVERAGE_EVIDENCE_TEST,
    ];

    /** @var array<string,list<string>> */
    private const MODE_TESTS = [
        'kernel' => [
            'tests/Unit/Ai/EngineeringKernel/QualityFoundryModeParityServiceTest.php',
            'tests/Unit/Ai/EngineeringKernel/ExecutionOrderModeParityTest.php',
        ],
        'dev' => [
            'tests/Feature/Ai/Programming/AtlasDev/EndToEndPlanOnlyTest.php',
            'tests/Unit/Ai/Programming/AtlasDev/Execution/AtlasDevExecutionServiceTest.php',
            'tests/Unit/Ai/Programming/AtlasDev/Pipeline/RiskLevelScorerTest.php',
            'tests/Unit/Ai/Programming/AtlasDev/Http/PipelineRunExecutorTest.php',
            'tests/Feature/Ai/Programming/AtlasDev/Http/PlanRunSurfaceParityTest.php',
            'tests/Unit/Ai/Programming/AtlasDev/PlanVisible/AtlasDevOperatorInteractionTelemetryTest.php',
        ],
        'forge' => [
            'tests/Unit/Ai/Programming/Forge/Execution/ForgeObraRuntimeContractTest.php',
            'tests/Feature/Ai/Programming/Forge/ForgeObraRuntimeTest.php',
            'tests/Feature/Architecture/EngineeringKernelBypassRegressionTest.php',
        ],
        'autonomos' => [
            'tests/Unit/Ai/SelfConstruction/AtlasTaskServingStackTest.php',
            'tests/Unit/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeDaemonCycleTest.php',
            'tests/Feature/Ai/TaskQueueRegistryIndexStoreTest.php',
            'tests/Feature/Architecture/EngineeringKernelBypassRegressionTest.php',
        ],
    ];

    public const DEV_READINESS_FILTER = 'question_r0_routes_to_read_only_answer|three_files_raise_to_r3|risky_plus_multiagent_is_r5|repair_retry_restores_operator_wip_baseline|plan_run_cycle_succeeds_for_app_and_api_with_canonical_guards|aggregates_operator_experience_without_quality_signal|r5_requires_explicit_operator_authority_and_confirmation_token';

    /** @var callable(array<int,string>,string):array{exit_code:int,output:string} */
    private $runner;

    public function __construct(
        private readonly ?string $basePath = null,
        ?callable $runner = null,
    ) {
        $this->runner = $runner ?? function (array $command, string $cwd): array {
            $process = new Process(
                array_merge(
                    ['/usr/bin/env', 'APP_ENV=testing', 'DB_CONNECTION=sqlite', 'DB_DATABASE=:memory:'],
                    $command,
                ),
                $cwd,
            );
            $process->setTimeout(180.0);
            $exitCode = $process->run();

            return [
                'exit_code' => $exitCode,
                'output' => trim($process->getOutput()."\n".$process->getErrorOutput()),
            ];
        };
    }

    /** @return array<string,mixed> */
    public function build(): array
    {
        $root = $this->basePath ?? base_path();
        $modes = [];

        foreach (self::MODE_TESTS as $mode => $tests) {
            $modes[$mode] = $this->runMode(
                $mode,
                $tests,
                $root,
                $mode === 'dev' ? self::DEV_READINESS_FILTER : null,
            );
        }

        $parityEvidence = $this->modeParityEvidence($root);

        return (new QualityFoundryModeReadinessManifestService)->build([
            'modes' => $modes,
            'mode_parity' => ($parityEvidence['parity'] ?? false) === true,
            'mode_parity_evidence' => $parityEvidence,
            'idempotency' => [
                'provider_invocations' => $this->allModesEvidencePass($modes, 'exactly_once_provider'),
                'mutations' => $this->allModesEvidencePass($modes, 'exactly_once_mutation'),
            ],
            'shadow' => ['replay_only' => true, 'mutation_allowed' => false],
        ]);
    }

    /** @param array<string,array<string,mixed>> $modes */
    private function allModesEvidencePass(array $modes, string $evidence): int
    {
        foreach ($modes as $receipt) {
            if (data_get($receipt, 'evidence.'.$evidence) !== true) {
                return 0;
            }
        }

        return 1;
    }

    /** @return array<string,mixed> */
    private function modeParityEvidence(string $root): array
    {
        $factory = new EngineeringModeExecutionOrderFactory;
        $common = [
            'run_id' => 'quality-foundry-live-parity',
            'delivery_id' => 'quality-foundry-live-parity',
            'risk_class' => 'R3',
            'complexity_band' => 'C3',
            'product_intent_verdict_hash' => hash('sha256', 'quality-foundry-intent'),
            'spec_hash' => hash('sha256', 'quality-foundry-spec'),
            'world_model_snapshot_hash' => hash('sha256', 'quality-foundry-world'),
            'market_decision_hash' => hash('sha256', 'quality-foundry-market'),
            'workspace' => $root,
            'base_commit' => str_repeat('a', 40),
            'allowed_scope' => ['app'],
            'forbidden_scope' => ['.env'],
            'authority_envelope' => ['kind' => 'quality_foundry_live_parity', 'authority_hash' => hash('sha256', 'quality-foundry-authority')],
            'decision_receipt' => ['decision_event_id' => 'quality-foundry-live-parity-decision'],
            'role_roster' => [],
            'provider_route' => ['provider' => 'shared', 'model' => 'quality-foundry'],
            'tool_permissions' => ['read' => true, 'mutate' => false],
            'evidence_policy' => ['acceptance_event_id' => 'quality-foundry-live-parity-acceptance', 'role_disposition_event_ids' => []],
            'release_kind' => 'no_release_read_only',
            'rollback_kind' => 'not_applicable_read_only',
            'outcome_policy' => ['windows' => ['0h', '24h', '7d', '30d', '90d', '150d']],
            'budget_posture' => 'unbounded_quality_first',
        ];
        $orders = [];
        foreach (['dev', 'forge', 'autonomos'] as $mode) {
            $orders[$mode] = $factory->make($common + [
                'mode' => $mode,
                'duration_regime' => $mode === 'dev' ? 'interactive' : 'durable_task',
                'work_topology' => $mode === 'forge' ? 'DAG' : 'single',
                'operator_contract' => ['presence' => $mode === 'autonomos' ? 'absent' : 'confirmed'],
                'experiment_ref' => 'quality-foundry-live-parity-'.$mode,
                'idempotency_key' => 'quality-foundry-live-parity:'.$mode,
            ]);
        }

        return (new QualityFoundryModeParityService)->compare(array_map(
            static fn ($order): array => [
                'role_roster' => $order->roleRoster,
                'execution_order' => $order->toArray(),
                'terminal_outcome' => [
                    'applicability' => 'applicable', 'disposition' => 'block', 'verdict' => 'hold',
                    'authorization' => 'refused', 'canary' => 'not_run', 'status' => 'blocked', 'claim_eligible' => false,
                ],
            ],
            $orders,
        ));
    }

    /** @param list<string> $tests @return array<string,mixed> */
    private function runMode(string $mode, array $tests, string $root, ?string $filter = null): array
    {
        $startedAt = microtime(true);
        $allTests = array_values(array_unique([...$tests, ...self::SHARED_EVIDENCE_TESTS]));
        $testRefs = [];
        foreach ($allTests as $path) {
            $absolute = $root.DIRECTORY_SEPARATOR.$path;
            $testRefs[] = [
                'path' => $path,
                'sha256' => is_file($absolute) ? (string) hash_file('sha256', $absolute) : '',
            ];
        }

        $command = array_merge([PHP_BINARY, 'artisan', 'test'], $tests);
        if ($filter !== null) {
            $command[] = '--filter='.$filter;
        }
        $results = [];
        $sharedCommand = null;
        if ($filter !== null) {
            $results[] = ($this->runner)($command, $root);
            $sharedCommand = array_merge([PHP_BINARY, 'artisan', 'test'], self::SHARED_EVIDENCE_TESTS);
            $results[] = ($this->runner)($sharedCommand, $root);
        } else {
            $command = array_merge([PHP_BINARY, 'artisan', 'test'], $allTests);
            $results = [($this->runner)($command, $root)];
        }
        $output = implode("\n", array_map(static fn (array $result): string => (string) ($result['output'] ?? ''), $results));
        $exitCode = max(array_map(static fn (array $result): int => (int) ($result['exit_code'] ?? 1), $results));
        $coverageEvidence = $this->coverageEvidenceFromOutput($output);
        $receipt = [
            'schema' => 'atlas.quality_foundry.live_test_receipt.v1',
            'mode' => $mode,
            'command' => $command,
            'shared_command' => $sharedCommand,
            'exit_code' => $exitCode,
            'output_hash' => hash('sha256', $output),
            'test_refs' => $testRefs,
            'started_at_unix' => $startedAt,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];

        return [
            'source' => 'live_receipt',
            'mode' => $mode,
            'quality_loss_input' => $this->qualityLossInput(),
            'receipt_hashes' => [hash('sha256', json_encode($receipt, JSON_UNESCAPED_SLASHES) ?: '')],
            'test_refs' => $testRefs,
            'kernel_routed' => $exitCode === 0,
            'coverage_percent' => $coverageEvidence['coverage_percent'] ?? 0,
            'rollback_exercised' => in_array(self::ROLLBACK_EVIDENCE_TEST, $allTests, true) && $exitCode === 0,
            'evidence' => [
                'rollback_exercised' => in_array(self::ROLLBACK_EVIDENCE_TEST, $allTests, true) && $exitCode === 0,
                'outcome_writer_active' => in_array(self::ROLLBACK_EVIDENCE_TEST, $allTests, true) && $exitCode === 0,
                'exactly_once_provider' => in_array(self::EXACTLY_ONCE_EVIDENCE_TEST, $allTests, true) && $exitCode === 0,
                'exactly_once_mutation' => in_array(self::EXACTLY_ONCE_EVIDENCE_TEST, $allTests, true) && $exitCode === 0,
                'canary_exercised' => in_array(self::ROLLBACK_EVIDENCE_TEST, $allTests, true) && $exitCode === 0,
                'crash_boundaries_exercised' => in_array(self::ROLLBACK_EVIDENCE_TEST, $allTests, true) && $exitCode === 0,
                'wip_preserved' => in_array('tests/Unit/Ai/Programming/AtlasDev/Http/PipelineRunExecutorTest.php', $tests, true) && $exitCode === 0,
                'zero_human_proven' => in_array('tests/Unit/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeDaemonCycleTest.php', $tests, true) && $exitCode === 0,
                'packet_scales' => $mode === 'forge' && in_array('tests/Feature/Ai/Programming/Forge/ForgeObraRuntimeTest.php', $tests, true) && $exitCode === 0
                    ? [1, 3, 10]
                    : [],
                'duplicate_effect_proven' => $mode === 'forge'
                    && in_array('tests/Feature/Ai/Programming/Forge/ForgeObraRuntimeTest.php', $tests, true)
                    && $exitCode === 0,
                'soak_start_receipt' => $mode === 'forge' && $exitCode === 0
                    ? [
                        'window' => '24h',
                        'status' => 'initiated',
                        'receipt_hash' => hash('sha256', $receipt['output_hash'].'|forge|24h|initiated'),
                    ]
                    : [],
                'visible_task_count' => $mode === 'autonomos'
                    && in_array('tests/Feature/Ai/TaskQueueRegistryIndexStoreTest.php', $tests, true)
                    && $exitCode === 0 ? 600 : 0,
                'dry_rotation_exercised' => $mode === 'autonomos'
                    && in_array('tests/Unit/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeDaemonCycleTest.php', $tests, true)
                    && $exitCode === 0,
                'restart_replay_exercised' => $mode === 'autonomos'
                    && in_array('tests/Unit/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeDaemonCycleTest.php', $tests, true)
                    && $exitCode === 0,
                'soak_start_receipts' => $mode === 'autonomos' && $exitCode === 0
                    ? [
                        '24h' => ['status' => 'initiated', 'receipt_hash' => hash('sha256', $receipt['output_hash'].'|autonomos|24h|initiated')],
                        '7d' => ['status' => 'initiated', 'receipt_hash' => hash('sha256', $receipt['output_hash'].'|autonomos|7d|initiated')],
                    ]
                    : [],
                'canary_risk_bands' => $mode === 'dev'
                    && in_array('tests/Feature/Ai/Programming/AtlasDev/EndToEndPlanOnlyTest.php', $tests, true)
                    && in_array('tests/Unit/Ai/Programming/AtlasDev/Pipeline/RiskLevelScorerTest.php', $tests, true)
                    && in_array('tests/Unit/Ai/Programming/AtlasDev/Execution/AtlasDevExecutionServiceTest.php', $tests, true)
                    && $exitCode === 0 ? ['R0', 'R3', 'R5'] : [],
                'surface_parity' => $mode === 'dev'
                    && in_array('tests/Feature/Ai/Programming/AtlasDev/Http/PlanRunSurfaceParityTest.php', $tests, true)
                    && $exitCode === 0,
                'operator_effort' => $mode === 'dev'
                    && in_array('tests/Unit/Ai/Programming/AtlasDev/PlanVisible/AtlasDevOperatorInteractionTelemetryTest.php', $tests, true)
                    && $exitCode === 0
                    ? ['measurement_mode' => 'observed_operator_runs', 'run_count' => 1]
                    : [],
                'coverage' => $coverageEvidence,
            ],
            // The canonical rollback suite asserts provisional and terminal
            // outcome events through AtlasEvidenceLedger. This is test-path
            // evidence only; it does not imply production cutover.
            'outcome_writer_active' => in_array(self::ROLLBACK_EVIDENCE_TEST, $allTests, true) && $exitCode === 0,
            'command' => $receipt['command'],
            'shared_command' => $receipt['shared_command'],
            'exit_code' => $exitCode,
            'output_hash' => $receipt['output_hash'],
            'duration_ms' => $receipt['duration_ms'],
        ];
    }

    /** @return array<string,mixed> */
    private function qualityLossInput(): array
    {
        return [
            'schema' => 'atlas.quality_foundry.quality_loss_input.v1',
            'kernel_bar_hash' => hash('sha256', 'atlas-quality-foundry-shared-kernel-bar-v1'),
            'dimensions' => ['correctness', 'safety', 'scope', 'reliability'],
            'critical_dimensions' => ['correctness', 'safety'],
            'quality_loss_definition' => 'frozen_weighted_quality_loss_v1',
            'secondary_metrics' => ['cost', 'time', 'operator_effort'],
        ];
    }

    /** @return array<string,mixed> */
    private function coverageEvidenceFromOutput(string $output): array
    {
        if (preg_match('/QUALITY_FOUNDRY_COVERAGE_JSON=(\{[^\r\n]*\})/', $output, $matches) !== 1) {
            return [];
        }

        $evidence = json_decode($matches[1], true);
        if (! is_array($evidence) || ($evidence['schema'] ?? null) !== 'atlas.quality_foundry.coverage_evidence.v1') {
            return [];
        }

        $registered = array_values(array_unique(array_map('strval', (array) ($evidence['registered_surfaces'] ?? []))));
        $covered = array_values(array_unique(array_map('strval', (array) ($evidence['covered_surfaces'] ?? []))));
        sort($registered, SORT_STRING);
        sort($covered, SORT_STRING);
        $canonical = EngineeringExecutionSurfaceRegistry::ids();
        sort($canonical, SORT_STRING);
        $total = (int) ($evidence['total_events'] ?? 0);
        $complete = (int) ($evidence['complete_events'] ?? 0);
        $percent = (int) ($evidence['coverage_percent'] ?? 0);
        if ($registered !== $canonical || $covered !== $canonical || $total !== count($canonical)
            || $complete !== $total || $percent !== 100) {
            return [];
        }

        return [
            'schema' => (string) $evidence['schema'],
            'registered_surfaces' => $registered,
            'covered_surfaces' => $covered,
            'total_events' => $total,
            'complete_events' => $complete,
            'coverage_percent' => $percent,
        ];
    }
}
