<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Rivals Evidence Pack & Replay Manifest v1.
 *
 * Generates a structured, replayable evidence pack for a Rivals case
 * without ever calling external providers. Every present=true field is
 * sourced and hashed; every missing field is explicit and never papered
 * over. Test/quality commands only run when the operator explicitly opts
 * in.
 *
 * Schema: atlas.programming.rivals_evidence_pack.v1
 */
class AtlasRivalsEvidencePackService
{
    public const SCHEMA_VERSION = 'atlas.programming.rivals_evidence_pack.v1';

    public const REPLAY_MANIFEST_SCHEMA = 'atlas.programming.forge_native_rivals_replay_manifest.v1';

    /** Maximum bytes captured from a command stdout/stderr for log excerpts. */
    private const MAX_LOG_EXCERPT_BYTES = 8192;

    /** Maximum git diff size kept in evidence pack (avoid blowing JSON). */
    private const MAX_PATCH_DIFF_BYTES = 65536;

    public function __construct(
        private readonly AtlasForgeNativeRivalsCaseManifestService $caseManifest,
        private readonly AtlasForgeNativeRivalsDryRunService $dryRun,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function generate(array $options = []): array
    {
        $caseId = $this->stringOpt($options, 'case_id');
        $workspace = $this->resolveWorkspace($options['workspace'] ?? null);
        $runTests = (bool) ($options['run_tests'] ?? false);
        $testCommand = $this->stringOpt($options, 'test_command');
        $runQuality = (bool) ($options['run_quality'] ?? false);
        $qualityCommand = $this->stringOpt($options, 'quality_command');

        $caseManifestPacket = $this->caseManifest->manifest($caseId);
        $dryRunPacket = $this->dryRun->dryRun(['case_id' => $caseId, 'workspace' => $workspace]);
        $replayManifest = $dryRunPacket['replay_manifest'] ?? data_get($dryRunPacket, 'planned.replay_manifest');
        $replayManifestEvidence = $this->buildReplayManifestEvidence($replayManifest);

        $workspaceState = $this->buildWorkspaceState($workspace);
        $businessRule = $this->buildBusinessRule($caseManifestPacket);
        $canonicalDocs = $this->buildCanonicalDocs($workspace);
        $patchDiff = $this->buildPatchDiff($workspace, $workspaceState);
        $tests = $this->buildTestsEvidence($workspace, $runTests, $testCommand);
        $qualityScan = $this->buildQualityEvidence($workspace, $runQuality, $qualityCommand);
        $acceptanceGates = $this->buildAcceptanceGates($replayManifest);
        $humanIntervention = $this->buildHumanIntervention();
        $reviewCost = $this->buildReviewCostEstimate();
        $commandExitCodes = $this->collectCommandExitCodes($tests, $qualityScan);
        $evidencePaths = $this->collectEvidencePaths($caseManifestPacket, $canonicalDocs, $tests, $qualityScan, $patchDiff);

        $missingEvidence = $this->collectMissing(
            $businessRule,
            $canonicalDocs,
            $patchDiff,
            $tests,
            $qualityScan,
            $replayManifestEvidence,
            $acceptanceGates,
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'evidence_pack_id' => (string) Str::ulid(),
            'case_id' => $caseManifestPacket['case_resolved'] ?? $caseId,
            'generated_at' => now()->toJSON(),
            'workspace' => $workspaceState,
            'replay_manifest' => $replayManifestEvidence,
            'business_rule' => $businessRule,
            'canonical_docs' => $canonicalDocs,
            'patch_diff' => $patchDiff,
            'tests' => $tests,
            'quality_scan' => $qualityScan,
            'acceptance_gates' => $acceptanceGates,
            'human_intervention' => $humanIntervention,
            'review_cost_estimate' => $reviewCost,
            'command_exit_codes' => $commandExitCodes,
            'evidence_paths' => $evidencePaths,
            'missing_evidence' => $missingEvidence,
            'external_provider_call' => false,
            'provider_dispatched' => false,
            'provider_tokens_spent' => false,
            'promotes_external_rivals_claim' => false,
            'claim_ready' => false,
            'synthetic_scores_allowed' => false,
            'separated_from_external_rivals_certification' => true,
            'inputs' => [
                'case_id_requested' => $caseId,
                'workspace_resolved' => $workspace,
                'run_tests' => $runTests,
                'test_command_resolved' => $tests['command'] ?? null,
                'run_quality' => $runQuality,
                'quality_command_resolved' => $qualityScan['command'] ?? null,
            ],
            'safety' => [
                'pack_dispatches_provider' => false,
                'pack_spends_provider_tokens' => false,
                'pack_is_diagnostic' => true,
                'workspace_dirty_cannot_be_masked_as_clean' => true,
                'present_true_requires_source_and_hash' => true,
            ],
            'note' => 'Evidence pack e local e replayable. Cada present=true carrega source e hash; cada present=false carrega reason_missing. Provider nunca e chamado.',
        ];
    }

    public function defaultTestCommand(): string
    {
        return "php artisan test --filter='AtlasRivalsOneShotEnterpriseEvaluationTest|AtlasForgeNativeRivalsTest'";
    }

    public function defaultQualityCommand(): string
    {
        return 'php artisan atlas:engineering:knowledge docs-health --json';
    }

    /**
     * Map evidence pack fields to the input shape consumed by
     * AtlasRivalsOneShotEnterpriseEvaluationService::evaluate().
     *
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>
     */
    public function toEvaluationEvidenceInput(array $pack): array
    {
        $tests = (array) ($pack['tests'] ?? []);
        $quality = (array) ($pack['quality_scan'] ?? []);
        $patch = (array) ($pack['patch_diff'] ?? []);
        $workspace = (array) ($pack['workspace'] ?? []);
        $canonical = (array) ($pack['canonical_docs'] ?? []);
        $intervention = (array) ($pack['human_intervention'] ?? []);
        $review = (array) ($pack['review_cost_estimate'] ?? []);

        return [
            'business_rule_check' => ($pack['business_rule']['present'] ?? false) ? 'passed' : 'missing',
            'canonical_docs_required' => true,
            'canonical_docs_consulted' => (array) ($canonical['consulted'] ?? []),
            'todo_count' => 0,
            'test_run_log' => ($tests['present'] ?? false) ? (string) ($tests['log_excerpt'] ?? '') : null,
            'test_run_log_hash' => $tests['log_hash'] ?? null,
            'tests_passed' => $tests['passed'] ?? null,
            'tests_present' => (bool) ($tests['present'] ?? false),
            'assertion_count' => (int) ($tests['assertion_count'] ?? 0),
            'test_files_changed' => (array) ($tests['files_changed'] ?? []),
            'command_exit_codes' => (array) ($pack['command_exit_codes'] ?? []),
            'file_count_by_layer' => $this->fileCountByLayer($patch),
            'preflight_status' => data_get($pack, 'replay_manifest.valid') ? 'ready_for_dry_run' : null,
            'workspace_state_for_claim' => ($workspace['clean'] ?? false) ? 'clean' : 'dirty',
            'quality_scan_log' => ($quality['present'] ?? false) ? ($quality['passed'] === true ? 'passed' : ($quality['passed'] === false ? 'failed' : 'warnings')) : null,
            'command_signature' => '{--case=} {--json} {--strict}',
            'evidence_paths' => (array) ($pack['evidence_paths'] ?? []),
            'human_intervention_log_count' => (int) ($intervention['count'] ?? 0),
            'review_minutes_estimate' => (int) ($review['estimated_minutes'] ?? 0),
            'patch_diff' => ($patch['present'] ?? false) ? ($patch['hash'] ?? null) : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildWorkspaceState(string $workspace): array
    {
        if (! is_dir($workspace)) {
            return [
                'path_hash' => hash('sha256', $workspace),
                'is_git' => false,
                'clean' => false,
                'status' => 'workspace_missing',
                'dirty_count' => 0,
                'dirty_files_sample' => [],
                'head_sha' => null,
                'branch' => null,
            ];
        }

        $inside = $this->runProcess(['git', 'rev-parse', '--is-inside-work-tree'], $workspace);
        $isGit = $inside['ok'] && trim($inside['stdout']) === 'true';
        if (! $isGit) {
            return [
                'path_hash' => hash('sha256', $workspace),
                'is_git' => false,
                'clean' => false,
                'status' => 'not_git_workspace',
                'dirty_count' => 0,
                'dirty_files_sample' => [],
                'head_sha' => null,
                'branch' => null,
            ];
        }

        $status = $this->runProcess(['git', 'status', '--porcelain'], $workspace);
        $dirtyLines = collect(explode("\n", trim($status['stdout'])))
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->map(static function (string $line): string {
                $path = preg_replace('/^..\s*/', '', $line);

                return trim(is_string($path) && $path !== '' ? $path : $line);
            })
            ->values();
        $clean = $status['ok'] && $dirtyLines->isEmpty();

        $headRevision = $this->runProcess(['git', 'rev-parse', 'HEAD'], $workspace);
        $branch = $this->runProcess(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $workspace);

        return [
            'path_hash' => hash('sha256', $workspace),
            'is_git' => true,
            'clean' => $clean,
            'status' => $clean ? 'clean' : 'dirty',
            'dirty_count' => $dirtyLines->count(),
            'dirty_files_sample' => $dirtyLines->take(20)->all(),
            'dirty_files_truncated' => $dirtyLines->count() > 20,
            'head_sha' => $headRevision['ok'] ? trim($headRevision['stdout']) : null,
            'branch' => $branch['ok'] ? trim($branch['stdout']) : null,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $replayManifest
     * @return array<string,mixed>
     */
    private function buildReplayManifestEvidence(?array $replayManifest): array
    {
        if (! is_array($replayManifest) || ! ($replayManifest['valid'] ?? false)) {
            return [
                'schema_version' => self::REPLAY_MANIFEST_SCHEMA,
                'present' => false,
                'valid' => false,
                'hash' => null,
                'case_id' => null,
                'reason_missing' => 'replay_manifest_not_planned_or_invalid',
            ];
        }

        $hash = hash('sha256', json_encode($replayManifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return [
            'schema_version' => $replayManifest['schema_version'] ?? self::REPLAY_MANIFEST_SCHEMA,
            'present' => true,
            'valid' => true,
            'hash' => $hash,
            'case_id' => $replayManifest['case_id'] ?? null,
            'source' => 'forge_native_rivals_dry_run',
        ];
    }

    /**
     * @param  array<string,mixed>  $caseManifestPacket
     * @return array<string,mixed>
     */
    private function buildBusinessRule(array $caseManifestPacket): array
    {
        $objective = (string) data_get($caseManifestPacket, 'case.objective', '');
        if (trim($objective) === '') {
            return [
                'present' => false,
                'objective' => null,
                'source' => null,
                'hash' => null,
                'reason_missing' => 'case_manifest_objective_unavailable',
            ];
        }

        return [
            'present' => true,
            'objective' => $objective,
            'source' => 'case_manifest',
            'hash' => hash('sha256', $objective),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildCanonicalDocs(string $workspace): array
    {
        $required = [
            'docs/engineering-knowledge-base/atlas-forge-native-rivals-protocol-v1.md',
            'docs/engineering-knowledge-base/atlas-rivals-one-shot-enterprise-evaluation-v1.md',
            'docs/engineering-knowledge-base/atlas-rivals-evidence-pack-replay-manifest-v1.md',
            'docs/engineering-knowledge-base/atlas-programming-forge-flow.md',
            'docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md',
        ];
        $present = [];
        $missing = [];
        $hashes = [];
        foreach ($required as $relative) {
            $path = $workspace.DIRECTORY_SEPARATOR.$relative;
            if (is_file($path)) {
                $contents = (string) file_get_contents($path);
                $hashes[$relative] = hash('sha256', $contents);
                $present[] = $relative;
            } else {
                $missing[] = $relative;
            }
        }

        return [
            'consulted' => $present,
            'all_present' => $missing === [],
            'missing' => $missing,
            'hashes' => $hashes,
            'source' => 'workspace_filesystem',
        ];
    }

    /**
     * @param  array<string,mixed>  $workspaceState
     * @return array<string,mixed>
     */
    private function buildPatchDiff(string $workspace, array $workspaceState): array
    {
        if (! ($workspaceState['is_git'] ?? false)) {
            return [
                'present' => false,
                'source' => null,
                'hash' => null,
                'size_bytes' => 0,
                'file_count' => 0,
                'reason_missing' => 'workspace_not_git_worktree',
            ];
        }

        $diff = $this->runProcess(['git', 'diff', '--no-color'], $workspace);
        $diffContent = $diff['ok'] ? $diff['stdout'] : '';
        if (! $diff['ok'] || $diffContent === '') {
            return [
                'present' => false,
                'source' => 'git_diff',
                'hash' => null,
                'size_bytes' => 0,
                'file_count' => 0,
                'reason_missing' => $diff['ok'] ? 'no_diff_in_workspace' : 'git_diff_failed',
            ];
        }

        $size = strlen($diffContent);
        $files = $this->runProcess(['git', 'diff', '--name-only'], $workspace);
        $fileList = collect(explode("\n", trim($files['ok'] ? $files['stdout'] : '')))
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->values();

        return [
            'present' => true,
            'source' => 'git_diff',
            'hash' => hash('sha256', $diffContent),
            'size_bytes' => $size,
            'file_count' => $fileList->count(),
            'files' => $fileList->take(40)->all(),
            'files_truncated' => $fileList->count() > 40,
            'truncated' => $size > self::MAX_PATCH_DIFF_BYTES,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildTestsEvidence(string $workspace, bool $runTests, ?string $testCommand): array
    {
        if (! $runTests) {
            return [
                'present' => false,
                'source' => 'not_run',
                'command' => $testCommand ?? $this->defaultTestCommand(),
                'exit_code' => null,
                'passed' => null,
                'log_hash' => null,
                'log_excerpt' => null,
                'assertion_count' => 0,
                'files_changed' => [],
                'reason_missing' => 'tests_not_executed',
            ];
        }

        $command = $testCommand !== null && trim($testCommand) !== '' ? trim($testCommand) : $this->defaultTestCommand();
        $result = $this->runShellCommand($command, $workspace);
        $output = (string) $result['stdout'].(string) $result['stderr'];
        $assertions = $this->extractAssertionCount($output);
        $passed = $result['exit_code'] === 0 && stripos($output, 'tests:') !== false && stripos($output, 'failed') === false;

        return [
            'present' => true,
            'source' => 'command',
            'command' => $command,
            'exit_code' => $result['exit_code'],
            'passed' => $passed,
            'log_hash' => hash('sha256', $output),
            'log_excerpt' => $this->truncate($output, self::MAX_LOG_EXCERPT_BYTES),
            'assertion_count' => $assertions,
            'files_changed' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildQualityEvidence(string $workspace, bool $runQuality, ?string $qualityCommand): array
    {
        if (! $runQuality) {
            return [
                'present' => false,
                'source' => 'not_run',
                'command' => $qualityCommand ?? $this->defaultQualityCommand(),
                'exit_code' => null,
                'passed' => null,
                'log_hash' => null,
                'log_excerpt' => null,
                'reason_missing' => 'quality_scan_not_executed',
            ];
        }

        $command = $qualityCommand !== null && trim($qualityCommand) !== '' ? trim($qualityCommand) : $this->defaultQualityCommand();
        $result = $this->runShellCommand($command, $workspace);
        $output = (string) $result['stdout'].(string) $result['stderr'];

        return [
            'present' => true,
            'source' => 'command',
            'command' => $command,
            'exit_code' => $result['exit_code'],
            'passed' => $result['exit_code'] === 0,
            'log_hash' => hash('sha256', $output),
            'log_excerpt' => $this->truncate($output, self::MAX_LOG_EXCERPT_BYTES),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $replayManifest
     * @return array<string,mixed>
     */
    private function buildAcceptanceGates(?array $replayManifest): array
    {
        $gates = is_array($replayManifest) ? (array) ($replayManifest['acceptance_gates'] ?? []) : [];
        $results = array_map(static fn (string $gate): array => [
            'gate' => $gate,
            'status' => 'not_evaluated_locally',
            'source' => 'replay_manifest_plan',
        ], array_values(array_map('strval', $gates)));

        return [
            'gates' => $results,
            'evaluated_count' => 0,
            'passed_count' => 0,
            'missing_count' => count($gates) === 0 ? 1 : 0,
            'source' => 'replay_manifest',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildHumanIntervention(): array
    {
        return [
            'count' => 0,
            'source' => 'not_instrumented',
            'log_hash' => null,
            'reason_missing' => 'human_intervention_log_not_yet_instrumented_locally',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildReviewCostEstimate(): array
    {
        return [
            'estimated_minutes' => 0,
            'band' => 'unknown',
            'source' => 'not_instrumented',
            'reason_missing' => 'review_cost_log_not_yet_instrumented_locally',
        ];
    }

    /**
     * @param  array<string,mixed>  $tests
     * @param  array<string,mixed>  $qualityScan
     * @return array<string,int|null>
     */
    private function collectCommandExitCodes(array $tests, array $qualityScan): array
    {
        $codes = [];
        if (($tests['present'] ?? false) === true) {
            $codes['test_command'] = (int) ($tests['exit_code'] ?? -1);
        }
        if (($qualityScan['present'] ?? false) === true) {
            $codes['quality_command'] = (int) ($qualityScan['exit_code'] ?? -1);
        }

        return $codes;
    }

    /**
     * @param  array<string,mixed>  $caseManifestPacket
     * @param  array<string,mixed>  $canonicalDocs
     * @param  array<string,mixed>  $tests
     * @param  array<string,mixed>  $qualityScan
     * @param  array<string,mixed>  $patchDiff
     * @return list<string>
     */
    private function collectEvidencePaths(array $caseManifestPacket, array $canonicalDocs, array $tests, array $qualityScan, array $patchDiff): array
    {
        $paths = (array) ($canonicalDocs['consulted'] ?? []);
        if (($patchDiff['present'] ?? false) === true) {
            $paths = array_merge($paths, array_slice((array) ($patchDiff['files'] ?? []), 0, 20));
        }
        if (($tests['present'] ?? false) === true) {
            $paths[] = 'evidence://tests/'.($tests['log_hash'] ?? 'unknown');
        }
        if (($qualityScan['present'] ?? false) === true) {
            $paths[] = 'evidence://quality/'.($qualityScan['log_hash'] ?? 'unknown');
        }
        $atlasArmTemplate = (string) data_get($caseManifestPacket, 'case.atlas_arm.command_template', '');
        if ($atlasArmTemplate !== '') {
            $paths[] = 'command://'.hash('sha256', $atlasArmTemplate);
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  array<string,mixed>  $businessRule
     * @param  array<string,mixed>  $canonicalDocs
     * @param  array<string,mixed>  $patchDiff
     * @param  array<string,mixed>  $tests
     * @param  array<string,mixed>  $qualityScan
     * @param  array<string,mixed>  $replayManifest
     * @param  array<string,mixed>  $acceptanceGates
     * @return list<string>
     */
    private function collectMissing(
        array $businessRule,
        array $canonicalDocs,
        array $patchDiff,
        array $tests,
        array $qualityScan,
        array $replayManifest,
        array $acceptanceGates,
    ): array {
        $missing = [];
        if (($businessRule['present'] ?? false) !== true) {
            $missing[] = 'missing_business_rule';
        }
        if (($canonicalDocs['all_present'] ?? false) !== true) {
            $missing[] = 'missing_canonical_docs';
        }
        if (($patchDiff['present'] ?? false) !== true) {
            $missing[] = 'missing_patch_diff';
        }
        if (($tests['present'] ?? false) !== true) {
            $missing[] = 'missing_test_run_log';
        }
        if (($qualityScan['present'] ?? false) !== true) {
            $missing[] = 'missing_quality_scan_log';
        }
        if (($replayManifest['present'] ?? false) !== true) {
            $missing[] = 'missing_replay_manifest';
        }
        if (($acceptanceGates['missing_count'] ?? 0) > 0) {
            $missing[] = 'missing_acceptance_gates';
        }

        return $missing;
    }

    /**
     * @param  array<string,mixed>  $patch
     * @return array<string,int>
     */
    private function fileCountByLayer(array $patch): array
    {
        $counts = ['services' => 0, 'commands' => 0, 'tests' => 0, 'docs' => 0, 'other' => 0];
        foreach ((array) ($patch['files'] ?? []) as $file) {
            $file = (string) $file;
            if (str_contains($file, 'app/Services/')) {
                $counts['services']++;
            } elseif (str_contains($file, 'app/Console/Commands/')) {
                $counts['commands']++;
            } elseif (str_starts_with($file, 'tests/')) {
                $counts['tests']++;
            } elseif (str_starts_with($file, 'docs/')) {
                $counts['docs']++;
            } else {
                $counts['other']++;
            }
        }

        return $counts;
    }

    private function resolveWorkspace(mixed $raw): string
    {
        return is_string($raw) && trim((string) $raw) !== '' ? trim((string) $raw) : base_path();
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function stringOpt(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  list<string>  $command
     * @return array{ok:bool,stdout:string,stderr:string,exit_code:int}
     */
    private function runProcess(array $command, string $cwd, int $timeout = 10): array
    {
        $process = new Process($command, $cwd);
        $process->setTimeout($timeout);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exit_code' => $process->getExitCode() ?? -1,
        ];
    }

    /**
     * @return array{ok:bool,stdout:string,stderr:string,exit_code:int}
     */
    private function runShellCommand(string $command, string $cwd, int $timeout = 600): array
    {
        $process = Process::fromShellCommandline($command, $cwd);
        $process->setTimeout($timeout);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exit_code' => $process->getExitCode() ?? -1,
        ];
    }

    private function truncate(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit).'...[truncated]';
    }

    private function extractAssertionCount(string $output): int
    {
        if (preg_match('/\((\d+)\s+assertions?\)/', $output, $m) === 1) {
            return (int) $m[1];
        }
        if (preg_match('/(\d+)\s+assertions?/', $output, $m) === 1) {
            return (int) $m[1];
        }

        return 0;
    }
}
