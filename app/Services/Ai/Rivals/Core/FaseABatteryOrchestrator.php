<?php

namespace App\Services\Ai\Rivals\Core;

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
     * Import fixture cases + create plans + preflight for each suite.
     * Does NOT run native spend. Requires ATLAS_RIVALS2_ENABLED + spend approval flags
     * because plan() is a mutating action with provider-spend gate.
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
                $fixtureRoot = base_path((string) $planSpec['fixture_root']);
                if (! is_dir($fixtureRoot)) {
                    throw new RuntimeException("rivals_battery_fixture_root_missing:{$suiteId}");
                }
                // Defer actual artisan plan/import to CLI layer; return actionable steps.
                $prepared[] = [
                    'suite_id' => $suiteId,
                    'status' => 'ready_to_plan',
                    'import_cases' => [
                        'action' => 'import-cases',
                        'suite' => $suiteId,
                        'file' => $fixtureRoot,
                    ],
                    'plan' => [
                        'action' => 'plan',
                        'suite' => $suiteId,
                        'cases' => implode(',', (array) $planSpec['cases']),
                        'arms' => implode(',', (array) $planSpec['arms']),
                        'repetitions' => $planSpec['repetitions'],
                        'approve_provider_spend' => true,
                    ],
                    'preflight' => [
                        'action' => 'preflight',
                        'after' => 'plan',
                    ],
                    'units_expected' => $planSpec['units_expected'],
                ];
            } catch (\Throwable $e) {
                $errors[] = ['suite_id' => $suiteId, 'error' => $e->getMessage()];
            }
        }

        return [
            'schema_version' => 'atlas.rivals2.fase_a_battery_prepare.v1',
            'mode' => $mode,
            'primary_model' => $dry['primary_model'],
            'execute_allowed_here' => false,
            'hint' => 'prepare emits import/plan/preflight steps — operator/CLI executes them; native runner is separate (execute)',
            'prepared' => $prepared,
            'errors' => $errors,
            'status' => $errors === [] ? 'ok' : 'error',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $dry = $this->dryRun('bare');
        $enterprisePath = \App\Services\Ai\Rivals\Support\RunPaths::enterpriseReportPath();
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
