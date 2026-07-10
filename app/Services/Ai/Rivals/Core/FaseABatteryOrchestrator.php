<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\RunPaths;
use InvalidArgumentException;
use RuntimeException;

/**
 * Fase A battery planner — dry-run / prepare surfaces without native spend.
 * Execute mode is Mac-only and must be invoked explicitly by the operator.
 */
class FaseABatteryOrchestrator
{
    public function __construct(
        private readonly SuiteRegistry $suites = new SuiteRegistry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dryRun(string $mode = 'bare'): array
    {
        $mode = $this->assertMode($mode);
        $primary = (string) config('atlas_rivals.fase_a.primary_model', 'verboo_kimi_k2_7');
        $this->assertHermesModel($primary);

        $suites = $mode === 'uplift'
            ? array_values(array_unique(array_values((array) config('atlas_rivals.uplift_families', []))))
            : $this->suites->externalSuiteIds();

        $casePacks = (array) config('atlas_rivals.fase_a.case_packs', []);
        $repetitions = (int) config('atlas_rivals.fase_a.default_repetitions', 3);
        $plans = [];
        foreach ($suites as $suiteId) {
            $cases = array_values((array) ($casePacks[$suiteId] ?? []));
            $arms = $mode === 'uplift'
                ? ["{$primary}@bare", "{$primary}@atlas_dev"]
                : ["{$primary}@bare"];
            $plans[] = [
                'suite_id' => $suiteId,
                'cases' => $cases,
                'case_count' => count($cases),
                'arms' => $arms,
                'repetitions' => $repetitions,
                'units_expected' => count($cases) * count($arms) * $repetitions,
                'fixture_root' => 'tests/Fixtures/Rivals/cases/'.$suiteId,
                'native_runner_hint' => 'php scripts/rivals-native-runner.php --manifest=... --approve-provider-spend',
            ];
        }

        return [
            'schema_version' => 'atlas.rivals2.fase_a_battery_dry_run.v1',
            'mode' => $mode,
            'primary_model' => $primary,
            'provider_binding' => 'hermes+verboo',
            'execute_allowed_here' => false,
            'hint' => 'dry-run only — use --mode=prepare on Mac (no native spend); --mode=execute is Mac-only',
            'suite_count' => count($plans),
            'plans' => $plans,
        ];
    }

    /**
     * Import fixture cases + create plans + soft preflight for each suite.
     * Does NOT run native spend. Requires ATLAS_RIVALS2_ENABLED + spend approval flags.
     *
     * @return array<string, mixed>
     */
    public function prepare(string $mode = 'bare', bool $approveProviderSpend = false): array
    {
        if (! (bool) config('atlas_rivals.enabled', false)) {
            throw new RuntimeException('atlas_rivals_disabled');
        }
        if (! $approveProviderSpend) {
            throw new RuntimeException('rivals_battery_prepare_requires_approve_provider_spend');
        }
        if (! (bool) config('atlas_rivals.provider_spend_allowed', false)) {
            throw new RuntimeException('rivals_provider_spend_not_allowed');
        }

        $dry = $this->dryRun($mode);
        $prepared = [];
        $errors = [];
        foreach ($dry['plans'] as $planSpec) {
            $suiteId = (string) $planSpec['suite_id'];
            try {
                $prepared[] = $this->prepareOneSuite($planSpec, $approveProviderSpend);
            } catch (\Throwable $e) {
                $errors[] = ['suite_id' => $suiteId, 'error' => $e->getMessage()];
            }
        }

        return [
            'schema_version' => 'atlas.rivals2.fase_a_battery_prepare.v1',
            'mode' => $mode,
            'primary_model' => $dry['primary_model'],
            'execute_allowed_here' => false,
            'hint' => 'prepare imports cases + persists plans/manifests + soft preflight — native runner is separate (execute / Mac)',
            'prepared' => $prepared,
            'errors' => $errors,
            'status' => $errors === [] ? 'ok' : 'error',
        ];
    }

    /**
     * @param  array<string, mixed>  $planSpec
     * @return array<string, mixed>
     */
    private function prepareOneSuite(array $planSpec, bool $approveProviderSpend): array
    {
        $suiteId = (string) $planSpec['suite_id'];
        $fixtureRoot = base_path((string) $planSpec['fixture_root']);
        if (! is_dir($fixtureRoot)) {
            throw new RuntimeException("rivals_battery_fixture_root_missing:{$suiteId}");
        }

        $import = $this->importFixtureCases($suiteId, $fixtureRoot);
        $caseIds = array_values((array) $planSpec['cases']);
        $missing = array_values(array_diff($caseIds, $import['imported']));
        if ($missing !== []) {
            // Cases may already exist from a prior import; verify on disk.
            $onDisk = [];
            foreach ($caseIds as $caseId) {
                if (is_file(RunPaths::root()."/external/{$suiteId}/cases/{$caseId}.json")) {
                    $onDisk[] = $caseId;
                }
            }
            $stillMissing = array_values(array_diff($caseIds, $onDisk));
            if ($stillMissing !== []) {
                throw new RuntimeException(
                    "rivals_battery_cases_missing:{$suiteId}:".implode(',', $stillMissing)
                );
            }
        }

        $adapter = $this->suites->adapterFor($suiteId, allowLegacyAlias: false);
        $armRegistry = new ArmRegistry;
        $arms = array_map(
            fn (string $arm): array => $armRegistry->parse($arm, $suiteId),
            array_values((array) $planSpec['arms']),
        );

        $judgeConfig = null;
        if ($suiteId === 'senior_swe_bench') {
            $judgePath = base_path('tests/Fixtures/Rivals/senior_swe_kimi_judge.json');
            if (! is_file($judgePath)) {
                throw new RuntimeException('rivals_battery_senior_judge_fixture_missing');
            }
            $judgeConfig = json_decode((string) file_get_contents($judgePath), true);
            if (! is_array($judgeConfig)) {
                throw new RuntimeException('rivals_battery_senior_judge_invalid');
            }
        }

        $plan = RunPlan::make(
            $suiteId,
            $caseIds,
            $arms,
            (int) $planSpec['repetitions'],
            [
                'max_usd' => 0.0,
                'max_minutes' => (int) config(
                    "atlas_rivals.benchmarks.repos.{$suiteId}.native_timeout_minutes",
                    30
                ),
            ],
            42,
            $judgeConfig,
        );
        $data = $plan->data;
        $data['environment']['source_repo'] = $suiteId;
        $data['environment']['approve_provider_spend'] = $approveProviderSpend;
        $data['environment']['adapter_hash'] = hash(
            'sha256',
            $adapter::class.'|'.(string) config('atlas_rivals.version', '2.0')
        );
        $plan = RunPlan::fromArray($data);
        $preregistration = Preregistration::fromPlan($plan);
        $data = $plan->data;
        $data['preregistration_hash'] = $preregistration->hash();
        $plan = RunPlan::fromArray($data);
        $runId = $plan->persist();
        $preregistration->persist();
        (new RunStateMachine)->mark($runId, RunStateMachine::PLANNED, [
            'suite_id' => $suiteId,
            'cases' => count($caseIds),
            'arms' => count($arms),
            'source' => 'fase_a_battery_prepare',
        ]);

        $commands = $adapter->planCommands($plan);
        $manifest = NativeExecutionManifest::fromPlan($plan, $adapter, $commands);
        $manifest->persist();

        $preflight = $this->softPreflight($runId, $suiteId);

        return [
            'suite_id' => $suiteId,
            'status' => $preflight['status'] === 'ok' ? 'prepared' : 'prepared_with_preflight_warnings',
            'run_id' => $runId,
            'imported_cases' => $import['imported'],
            'units_expected' => $planSpec['units_expected'],
            'native_manifest_path' => RunPaths::nativeManifestPath($runId),
            'native_manifest_hash' => $manifest->hash(),
            'preflight' => $preflight,
            'import_cases' => [
                'action' => 'import-cases',
                'suite' => $suiteId,
                'file' => $fixtureRoot,
                'status' => 'done',
            ],
            'plan' => [
                'action' => 'plan',
                'suite' => $suiteId,
                'cases' => implode(',', $caseIds),
                'arms' => implode(',', (array) $planSpec['arms']),
                'repetitions' => $planSpec['repetitions'],
                'approve_provider_spend' => true,
                'status' => 'done',
                'run_id' => $runId,
            ],
        ];
    }

    /**
     * @return array{imported: list<string>, rejected: list<array<string, string>>}
     */
    private function importFixtureCases(string $suiteId, string $fixtureRoot): array
    {
        $files = glob($fixtureRoot.'/*.json') ?: [];
        if ($files === []) {
            throw new RuntimeException("rivals_battery_no_case_files:{$suiteId}");
        }
        $dir = RunPaths::root()."/external/{$suiteId}/cases";
        RunPaths::ensureDir($dir);
        $imported = [];
        $rejected = [];
        foreach ($files as $file) {
            $payload = json_decode((string) file_get_contents($file), true);
            $cases = isset($payload['case_id']) ? [$payload] : (array) ($payload['cases'] ?? $payload);
            foreach ($cases as $case) {
                if (! is_array($case) || ! isset($case['case_id'], $case['task_type'])) {
                    $rejected[] = ['file' => basename($file), 'reason' => 'missing_case_id_or_task_type'];

                    continue;
                }
                try {
                    RunPaths::assertCaseId((string) $case['case_id']);
                } catch (\Throwable $e) {
                    $rejected[] = ['file' => basename($file), 'reason' => $e->getMessage()];

                    continue;
                }
                $case['suite_id'] = $suiteId;
                $case['source_repo'] = $case['source_repo'] ?? $suiteId;
                file_put_contents(
                    $dir.'/'.$case['case_id'].'.json',
                    json_encode($case, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );
                $imported[] = (string) $case['case_id'];
            }
        }

        return [
            'imported' => array_values(array_unique($imported)),
            'rejected' => $rejected,
        ];
    }

    /**
     * Soft preflight: validates plan/manifest/storage. Smoke is advisory
     * (required for Mac execute, not for prepare itself).
     *
     * @return array<string, mixed>
     */
    private function softPreflight(string $runId, string $suiteId): array
    {
        $checks = [
            'plan_valid' => is_file(RunPaths::planPath($runId)),
            'storage_writable' => is_writable(RunPaths::runDir($runId)),
            'manifest_valid' => false,
        ];
        try {
            $manifest = NativeExecutionManifest::load($runId);
            $checks['manifest_valid'] = $manifest->data['run_id'] === $runId;
        } catch (\Throwable) {
            $checks['manifest_valid'] = false;
        }

        $hardOk = ! in_array(false, [
            $checks['plan_valid'],
            $checks['storage_writable'],
            $checks['manifest_valid'],
        ], true);

        $checks['benchmark_smoke_running'] = 'advisory_mac_only';
        $checks['note'] = 'prepare does not require live smoke; Mac execute does';

        if ($hardOk) {
            $state = (new RunStateMachine)->current($runId);
            if (($state['state'] ?? null) === RunStateMachine::PLANNED) {
                (new RunStateMachine)->mark(
                    $runId,
                    RunStateMachine::PREFLIGHTED,
                    ['checks' => $checks, 'source' => 'fase_a_battery_prepare'],
                );
            }
        }

        return [
            'schema_version' => 'atlas.rivals2.fase_a_battery_prepare_preflight.v1',
            'status' => $hardOk ? 'ok' : 'error',
            'run_id' => $runId,
            'suite_id' => $suiteId,
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $dry = $this->dryRun('bare');
        $enterprisePath = RunPaths::enterpriseReportPath();
        $enterprise = is_file($enterprisePath)
            ? (json_decode((string) file_get_contents($enterprisePath), true) ?? null)
            : null;

        return [
            'schema_version' => 'atlas.rivals2.fase_a_battery_status.v1',
            'dry_run' => $dry,
            'enterprise_report_present' => is_array($enterprise),
            'enterprise_suites_ok' => (int) ($enterprise['executive_summary']['suites_ok'] ?? 0),
            'enterprise_suites_not_run' => (int) ($enterprise['executive_summary']['suites_not_run'] ?? 10),
        ];
    }

    private function assertMode(string $mode): string
    {
        if (! in_array($mode, ['bare', 'uplift', 'model_matrix'], true)) {
            throw new InvalidArgumentException("rivals_battery_invalid_mode:{$mode}");
        }

        return $mode;
    }

    private function assertHermesModel(string $modelId): void
    {
        $models = (array) config('atlas_rivals.models', []);
        $provider = (string) ($models[$modelId]['provider'] ?? '');
        $allowed = (array) config('atlas_rivals.fase_a.allowed_providers', ['hermes']);
        if ($provider === '' || ! in_array($provider, $allowed, true)) {
            throw new RuntimeException("rivals_battery_model_not_hermes_verboo:{$modelId}");
        }
    }
}
