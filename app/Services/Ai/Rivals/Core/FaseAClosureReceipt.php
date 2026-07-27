<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Benchmarks\BenchmarkRepoManager;
use App\Services\Ai\Rivals\Support\AtomicWriter;
use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;
use Illuminate\Support\Facades\Process;

/** Machine-generated authority for the exact phrase "Fase A 100%". */
final class FaseAClosureReceipt
{
    public const SCHEMA = 'atlas.rivals2.fase_a_closure.v2';

    /** @param array<string, mixed> $codeGates */
    public function build(array $codeGates = []): array
    {
        $registry = new SuiteRegistry;
        $registryOk = true;
        try {
            $registry->assertComplete();
        } catch (\Throwable) {
            $registryOk = false;
        }
        $benchmarks = (new BenchmarkRepoManager)->status();
        $smokeFresh = $this->smokeFresh($benchmarks['repos'] ?? []);
        $nativeRuns = $this->nativeRuns($registry->externalSuiteIds());
        $uplifts = $this->upliftRuns((array) config('atlas_rivals.uplift_families', []));
        $ledger = (new ResultLedger)->verifySemantic();
        $operational = $this->operationalPrerequisites();
        $workspace = $this->workspaceState();
        $enterprise = $this->enterpriseReportGate();

        $gates = [
            'registry_10_of_10' => $registryOk,
            'smoke_10_of_10' => ($benchmarks['running'] ?? 0) === 10
                && ($benchmarks['blocked'] ?? 1) === 0
                && $smokeFresh['fresh'],
            'native_run_10_of_10' => count($nativeRuns['completed']) === 10,
            'uplift_families_5_of_5' => count($uplifts['completed']) === 5,
            'enterprise_report_present' => $enterprise['ready'],
            'tests_green' => ($codeGates['tests']['passed'] ?? false) === true,
            'docs_health_green' => ($codeGates['docs_health']['passed'] ?? false) === true,
            // Verified AND non-empty: verifyChain() reports verified=true when the
            // ledger file is absent (a fresh install is healthy), so gating on
            // `verified` alone let `rm` on the ledger pass as tamper-evidence.
            'ledger_verified' => $ledger['verified'] && ($ledger['evidence_present'] ?? false),
            'workspace_clean' => $workspace['clean'],
            'operational_prerequisites' => $operational['ready'],
        ];
        $blockers = [];
        foreach ($gates as $gate => $passed) {
            if (! $passed) {
                $blockers[] = 'gate_failed:'.$gate;
            }
        }
        $blockers = array_values(array_unique(array_merge(
            $blockers,
            $smokeFresh['blockers'],
            $nativeRuns['blockers'],
            $uplifts['blockers'],
            $enterprise['blockers'],
            $ledger['failures'],
            $operational['blockers'],
        )));
        $receipt = [
            'schema_version' => self::SCHEMA,
            'product' => 'Rivals',
            'version' => (string) config('atlas_rivals.version'),
            'generated_at' => now()->toIso8601String(),
            'generator_git_head' => $workspace['git_head'],
            'config_hash' => hash('sha256', json_encode(
                config('atlas_rivals'),
                JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            )),
            'gates' => $gates,
            'benchmarks' => [
                'total' => $benchmarks['total'] ?? 0,
                'running' => $benchmarks['running'] ?? 0,
                'blocked' => $benchmarks['blocked'] ?? 0,
                'freshness' => $smokeFresh,
            ],
            'native_runs' => $nativeRuns,
            'uplifts' => $uplifts,
            'enterprise_report' => $enterprise,
            'code_gates' => $codeGates,
            'ledger' => $ledger,
            'operational' => $operational,
            'workspace' => $workspace,
            'blockers' => $blockers,
            'fase_a_100_percent_authorized' => $blockers === [],
        ];
        $receipt['closure_hash'] = self::hashPayload($receipt);
        AtomicWriter::write(
            RunPaths::closureReceiptPath(),
            json_encode(
                $receipt,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            )
        );

        return $receipt;
    }

    /** @return array{verified: bool, authorized: bool, failures: list<string>, closure_hash: ?string} */
    public function verify(): array
    {
        $path = RunPaths::closureReceiptPath();
        if (! is_file($path)) {
            return [
                'verified' => false,
                'authorized' => false,
                'failures' => ['closure_receipt_missing'],
                'closure_hash' => null,
            ];
        }
        $receipt = json_decode((string) file_get_contents($path), true);
        if (! is_array($receipt) || ($receipt['schema_version'] ?? null) !== self::SCHEMA) {
            return [
                'verified' => false,
                'authorized' => false,
                'failures' => ['closure_receipt_invalid'],
                'closure_hash' => null,
            ];
        }
        $validHash = hash_equals(
            (string) ($receipt['closure_hash'] ?? ''),
            self::hashPayload($receipt),
        );
        $registry = new SuiteRegistry;
        $registryOk = true;
        try {
            $registry->assertComplete();
        } catch (\Throwable) {
            $registryOk = false;
        }
        $benchmarks = (new BenchmarkRepoManager)->status();
        $smoke = $this->smokeFresh($benchmarks['repos'] ?? []);
        $native = $this->nativeRuns($registry->externalSuiteIds());
        $uplifts = $this->upliftRuns((array) config('atlas_rivals.uplift_families', []));
        $ledger = (new ResultLedger)->verifySemantic();
        $operational = $this->operationalPrerequisites();
        $workspace = $this->workspaceState();
        $enterprise = $this->enterpriseReportGate();
        $currentConfigHash = hash('sha256', json_encode(
            config('atlas_rivals'),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
        $liveGates = [
            'registry_10_of_10' => $registryOk,
            'smoke_10_of_10' => ($benchmarks['running'] ?? 0) === 10
                && ($benchmarks['blocked'] ?? 1) === 0
                && $smoke['fresh'],
            'native_run_10_of_10' => count($native['completed']) === 10,
            'uplift_families_5_of_5' => count($uplifts['completed']) === 5,
            'enterprise_report_present' => $enterprise['ready'],
            'tests_green' => ($receipt['code_gates']['tests']['passed'] ?? false) === true,
            'docs_health_green' => ($receipt['code_gates']['docs_health']['passed'] ?? false) === true,
            // Verified AND non-empty: verifyChain() reports verified=true when the
            // ledger file is absent (a fresh install is healthy), so gating on
            // `verified` alone let `rm` on the ledger pass as tamper-evidence.
            'ledger_verified' => $ledger['verified'] && ($ledger['evidence_present'] ?? false),
            'workspace_clean' => $workspace['clean'],
            'operational_prerequisites' => $operational['ready'],
            'generator_git_head_current' => hash_equals(
                (string) ($receipt['generator_git_head'] ?? ''),
                (string) $workspace['git_head'],
            ),
            'config_current' => hash_equals(
                (string) ($receipt['config_hash'] ?? ''),
                $currentConfigHash,
            ),
        ];
        $liveFailures = array_map(
            fn (string $gate): string => 'live_gate_failed:'.$gate,
            array_keys(array_filter($liveGates, fn (bool $passed): bool => ! $passed)),
        );
        $failures = array_values(array_unique(array_merge(
            $validHash ? [] : ['closure_hash_mismatch'],
            $liveFailures,
            $smoke['blockers'],
            $native['blockers'],
            $uplifts['blockers'],
            $enterprise['blockers'],
            $ledger['failures'],
            $operational['blockers'],
        )));

        return [
            'verified' => $validHash,
            'authorized' => $validHash
                && ($receipt['fase_a_100_percent_authorized'] ?? false) === true
                && $failures === [],
            'failures' => $failures,
            'closure_hash' => $receipt['closure_hash'] ?? null,
        ];
    }

    /** @param list<array<string, mixed>> $repos */
    private function smokeFresh(array $repos): array
    {
        $maxAge = (int) config('atlas_rivals.claim.smoke_max_age_hours', 168);
        $blockers = [];
        $ages = [];
        foreach ($repos as $repo) {
            $finished = strtotime((string) ($repo['last_smoke_at'] ?? '')) ?: 0;
            $age = $finished > 0 ? (time() - $finished) / 3600 : INF;
            $ages[$repo['repo_id']] = is_finite($age) ? round($age, 2) : null;
            if ($age > $maxAge) {
                $blockers[] = 'smoke_stale:'.$repo['repo_id'];
            }
        }

        return ['fresh' => $blockers === [], 'max_age_hours' => $maxAge, 'ages_hours' => $ages, 'blockers' => $blockers];
    }

    /** @param list<string> $suiteIds */
    private function nativeRuns(array $suiteIds): array
    {
        $completed = [];
        $runsDir = RunPaths::runsDir();
        $runIds = is_dir($runsDir)
            ? array_values(array_diff(scandir($runsDir) ?: [], ['.', '..']))
            : [];
        rsort($runIds);
        foreach ($runIds as $runId) {
            try {
                $plan = RunPlan::load($runId);
            } catch (\Throwable) {
                continue;
            }
            $suite = (string) $plan->data['suite_id'];
            if (! in_array($suite, $suiteIds, true) || isset($completed[$suite])) {
                continue;
            }
            $reportPath = RunPaths::reportPath($runId);
            if (! is_file($reportPath) || ! is_file(RunPaths::bundleManifestPath($runId))) {
                continue;
            }
            $report = json_decode((string) file_get_contents($reportPath), true) ?? [];
            $adjudicationPath = RunPaths::adjudicationPath($runId);
            $adjudication = is_file($adjudicationPath)
                ? (json_decode((string) file_get_contents($adjudicationPath), true) ?? [])
                : [];
            $bundle = (new BundleManifest)->verify(RunPaths::runDir($runId));
            $manifest = NativeExecutionManifest::load($runId);
            $nativeReceipts = NativeExecutionReceipt::loadAll($runId);
            $runReceipts = RunReceipt::loadAll($runId);
            $entries = $manifest->entries();
            $commandsAreIndependent = count(array_unique(array_column($entries, 'command_hash')))
                === count($entries);
            $hasEnvironmentFailure = collect($runReceipts)->contains(
                fn (RunReceipt $receipt): bool => $receipt->data['failure_class'] === 'environment_failure',
            );
            $nativeExecutionIsReal = collect($nativeReceipts)->every(
                fn (NativeExecutionReceipt $receipt): bool => $receipt->data['status'] === 'success'
                    && ($receipt->data['runner']['mode'] ?? null) === 'execute',
            );
            $replayVerified = (new ReplayVerifier)->verify($runId)['verified'];
            $reportHashValid = ($report['report_hash'] ?? null) === ReportBuilder::hashPayload($report);
            if (($report['pipeline_valid'] ?? false) !== true
                || ($report['internal_claim_allowed'] ?? false) !== true
                || ($adjudication['internal_claim_allowed'] ?? false) !== true
                || ($adjudication['statistical_analysis']['adequate'] ?? false) !== true
                || ! $bundle['verified']
                || ! $reportHashValid
                || ! $replayVerified
                || $manifest->data['claim_tier'] === ClaimTier::HARNESS
                || (int) $plan->data['repetitions'] < (int) config('atlas_rivals.claim.min_repetitions', 3)
                || ! $commandsAreIndependent
                || $hasEnvironmentFailure
                || ! $nativeExecutionIsReal
                || count($nativeReceipts) !== count($manifest->entries())) {
                continue;
            }
            $completed[$suite] = [
                'run_id' => $runId,
                'report_hash' => $report['report_hash'] ?? null,
                'bundle_hash' => $bundle['bundle_hash'],
                'manifest_hash' => $manifest->hash(),
                'cost_usd' => array_sum(array_column($report['rows'] ?? [], 'total_cost_usd')),
            ];
        }
        $missing = array_values(array_diff($suiteIds, array_keys($completed)));

        return [
            'completed' => $completed,
            'missing' => $missing,
            'blockers' => array_map(fn (string $suite): string => 'native_run_missing:'.$suite, $missing),
        ];
    }

    /** @param array<string, string> $families */
    private function upliftRuns(array $families): array
    {
        $completed = [];
        foreach ($this->allRunIds() as $runId) {
            $path = RunPaths::runDir($runId).'/uplift.json';
            if (! is_file($path)) {
                continue;
            }
            $uplift = json_decode((string) file_get_contents($path), true) ?? [];
            $modelId = (string) ($uplift['model_id'] ?? '');
            if ($modelId === '') {
                continue;
            }
            try {
                $plan = RunPlan::load($runId);
                $suite = $plan->data['suite_id'];
                $freshUplift = (new AtlasUpliftRunner)->compare($runId, $modelId);
                $adjudication = json_decode(
                    (string) file_get_contents(RunPaths::adjudicationPath($runId)),
                    true,
                ) ?? [];
            } catch (\Throwable) {
                continue;
            }
            if (($freshUplift['uplift_kind'] ?? null) !== 'real_uplift'
                || ($freshUplift['internal_claim_allowed'] ?? false) !== true
                || ($freshUplift['stop_the_line'] ?? true) === true
                || ($adjudication['internal_claim_allowed'] ?? false) !== true
                || ($adjudication['statistical_analysis']['adequate'] ?? false) !== true
                || count(array_unique($plan->data['case_ids'])) < (int) config(
                    'atlas_rivals.claim.min_distinct_cases_internal',
                    3,
                )) {
                continue;
            }
            foreach ($families as $family => $requiredSuite) {
                if ($suite === $requiredSuite && ! isset($completed[$family])) {
                    $completed[$family] = [
                        'suite_id' => $suite,
                        'run_id' => $runId,
                        'uplift_hash' => hash_file('sha256', $path),
                    ];
                }
            }
        }
        $missing = array_values(array_diff(array_keys($families), array_keys($completed)));

        return [
            'required' => $families,
            'completed' => $completed,
            'missing' => $missing,
            'blockers' => array_map(fn (string $family): string => 'real_uplift_missing:'.$family, $missing),
        ];
    }

    /** @return list<string> */
    private function allRunIds(): array
    {
        if (! is_dir(RunPaths::runsDir())) {
            return [];
        }

        return array_values(array_filter(
            array_diff(scandir(RunPaths::runsDir()) ?: [], ['.', '..']),
            fn (string $runId): bool => is_dir(RunPaths::runsDir().'/'.$runId),
        ));
    }

    /** @return array{ready: bool, blockers: list<string>, path: ?string, report_hash: ?string, suite_rows: int} */
    private function enterpriseReportGate(): array
    {
        $path = RunPaths::enterpriseReportPath();
        if (! is_file($path)) {
            return [
                'ready' => false,
                'blockers' => ['enterprise_report_missing'],
                'path' => null,
                'report_hash' => null,
                'suite_rows' => 0,
            ];
        }
        $payload = json_decode((string) file_get_contents($path), true) ?? [];
        $blockers = [];
        if (($payload['schema_version'] ?? null) !== SchemaContract::ENTERPRISE_REPORT) {
            $blockers[] = 'enterprise_report_schema_mismatch';
        }
        if (($payload['claim_allowed'] ?? true) !== false) {
            $blockers[] = 'enterprise_report_claim_allowed_not_false';
        }
        $rows = (array) ($payload['suite_rows'] ?? []);
        $expected = count((new SuiteRegistry)->externalSuiteIds());
        if (count($rows) !== $expected) {
            $blockers[] = 'enterprise_report_suite_rows_count:'.count($rows);
        }
        $storedHash = (string) ($payload['report_hash'] ?? '');
        $recomputed = EnterpriseReportBuilder::hashPayload($payload);
        if ($storedHash === '' || ! hash_equals($storedHash, $recomputed)) {
            $blockers[] = 'enterprise_report_hash_mismatch';
        }

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'path' => $path,
            'report_hash' => $storedHash !== '' ? $storedHash : null,
            'suite_rows' => count($rows),
        ];
    }

    private function operationalPrerequisites(): array
    {
        $modal = base_path('tools/rivals/benchmarks/swe_marathon/.atlas-venv/bin/modal');
        $marathonEnvironment = (string) config(
            'atlas_rivals.native_execution.swe_marathon_environment',
            'docker',
        );
        $checks = [
            'docker' => Process::run(['docker', 'info'])->successful(),
            'marathon_environment' => in_array($marathonEnvironment, ['docker', 'modal'], true),
            'modal_cli' => $marathonEnvironment !== 'modal' || is_file($modal),
            'modal_auth' => $marathonEnvironment !== 'modal'
                || (is_file($modal) && Process::run([$modal, 'token', 'info'])->successful()),
            'claude_cli' => Process::run(['/bin/zsh', '-lc', 'command -v claude'])->successful(),
            'codex_cli' => Process::run(['/bin/zsh', '-lc', 'command -v codex'])->successful(),
            'hermes_cli' => Process::run(['/bin/zsh', '-lc', 'command -v hermes'])->successful(),
            'verboo_credentials' => (new VerbooEnvironment)->available(),
            'free_disk_20gb' => disk_free_space(base_path()) >= 20 * 1024 ** 3,
        ];

        return [
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'blockers' => array_map(
                fn (string $name): string => 'prerequisite_missing:'.$name,
                array_keys(array_filter($checks, fn (bool $passed): bool => ! $passed)),
            ),
        ];
    }

    private function workspaceState(): array
    {
        $head = Process::path(base_path())->run(['git', 'rev-parse', 'HEAD']);
        $status = Process::path(base_path())->run(['git', 'status', '--porcelain']);

        return [
            'git_head' => trim($head->output()),
            'clean' => trim($status->output()) === '',
            'dirty_count' => count(array_filter(explode("\n", trim($status->output())))),
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function hashPayload(array $payload): string
    {
        unset($payload['closure_hash']);

        return hash('sha256', json_encode(
            self::canonicalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonicalize(...), $value);
    }
}
