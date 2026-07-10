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
        if ($mode === 'model_matrix') {
            throw new InvalidArgumentException(
                'rivals_battery_model_matrix_not_supported_use_bare_or_uplift'
            );
        }
        $primary = (string) config('atlas_rivals.fase_a.primary_model', 'verboo_kimi_k2_7');
        $this->assertHermesModel($primary);

        $suites = $mode === 'uplift'
            ? array_values(array_unique(array_values((array) config('atlas_rivals.uplift_families', []))))
            : $this->suites->externalSuiteIds();

        $casePacks = (array) config('atlas_rivals.fase_a.case_packs', []);
        $repetitions = (int) config('atlas_rivals.fase_a.default_repetitions', 3);
        $minCases = (int) config('atlas_rivals.fase_a.min_distinct_cases', 3);
        $plans = [];
        foreach ($suites as $suiteId) {
            $cases = array_values(array_unique(array_map('strval', (array) ($casePacks[$suiteId] ?? []))));
            if (count($cases) < $minCases) {
                throw new RuntimeException(
                    "rivals_battery_case_pack_too_small:{$suiteId}:".count($cases)."<{$minCases}"
                );
            }
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
        $fixtureRoot = $this->assertFixtureRoot((string) $planSpec['fixture_root']);

        $import = $this->importFixtureCases($suiteId, $fixtureRoot);
        $caseIds = array_values((array) $planSpec['cases']);
        $missing = array_values(array_diff($caseIds, $import['imported']));
        if ($missing !== []) {
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
        foreach ($arms as $arm) {
            $this->assertHermesModel((string) ($arm['model_id'] ?? ''));
        }

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

        $budgetCap = max(0.01, (float) config('atlas_rivals.fase_a.budget_usd_cap', 50.0));
        $plan = RunPlan::make(
            $suiteId,
            $caseIds,
            $arms,
            (int) $planSpec['repetitions'],
            [
                'max_usd' => $budgetCap,
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
        $data['environment']['fase_a_battery'] = true;
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
        if ($commands === []) {
            throw new RuntimeException("rivals_battery_plan_commands_empty:{$suiteId}");
        }
        $manifest = NativeExecutionManifest::fromPlan($plan, $adapter, $commands);
        $manifest->persist();

        $preflight = $this->softPreflight($runId, $suiteId);

        return [
            'suite_id' => $suiteId,
            'status' => $preflight['status'] === 'ok' ? 'prepared' : 'prepared_with_preflight_warnings',
            'run_id' => $runId,
            'imported_cases' => $import['imported'],
            'units_expected' => $planSpec['units_expected'],
            'budget_usd_cap' => $budgetCap,
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

    private function assertFixtureRoot(string $relativeRoot): string
    {
        RunPaths::assertRelativePath($relativeRoot);
        if (! str_starts_with($relativeRoot, 'tests/Fixtures/Rivals/cases/')) {
            throw new RuntimeException('rivals_battery_fixture_root_outside_allowlist:'.$relativeRoot);
        }
        $resolved = RunPaths::resolveContained(base_path(), $relativeRoot, mustExist: true);
        if (! is_dir($resolved)) {
            throw new RuntimeException('rivals_battery_fixture_root_missing:'.$relativeRoot);
        }

        return $resolved;
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
            'provider_spend_approved' => false,
            'hermes_arms_only' => true,
        ];
        try {
            $manifest = NativeExecutionManifest::load($runId);
            $checks['manifest_valid'] = $manifest->data['run_id'] === $runId;
            $checks['provider_spend_approved'] = ($manifest->data['provider_spend_approved'] ?? false) === true;
            foreach ($manifest->entries() as $entry) {
                $modelId = (string) ($entry['model_id'] ?? '');
                $provider = (string) ((new ModelRegistry)->get($modelId)['provider'] ?? '');
                if ($provider !== 'hermes') {
                    $checks['hermes_arms_only'] = false;
                    break;
                }
            }
        } catch (\Throwable) {
            $checks['manifest_valid'] = false;
        }

        $hardOk = ! in_array(false, [
            $checks['plan_valid'],
            $checks['storage_writable'],
            $checks['manifest_valid'],
            $checks['provider_spend_approved'],
            $checks['hermes_arms_only'],
        ], true);

        $checks['benchmark_smoke_running'] = 'advisory_mac_only';
        $checks['verboo_credentials_present'] = (new VerbooEnvironment)->available();
        $checks['note'] = 'prepare does not require live smoke; Mac execute does. Credentials never serialized.';

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
            'verboo_credentials_present' => (new VerbooEnvironment)->available(),
            'provider_spend_allowed' => (bool) config('atlas_rivals.provider_spend_allowed', false),
            'rivals_enabled' => (bool) config('atlas_rivals.enabled', false),
            'execute_allowed_here' => false,
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
        if ($modelId === '' || $provider === '' || ! in_array($provider, $allowed, true)) {
            throw new RuntimeException("rivals_battery_model_not_hermes_verboo:{$modelId}");
        }
        if (($models[$modelId]['enabled'] ?? true) !== true) {
            throw new RuntimeException("rivals_battery_model_disabled:{$modelId}");
        }
    }
}
