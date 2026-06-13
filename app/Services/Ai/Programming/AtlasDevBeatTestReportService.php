<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\AutonomousEvolution\AtlasLoopBacklogManifestService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * L4-9: honest Atlas Dev beat-test report.
 *
 * This service scores evidence from real Atlas Dev runs. It intentionally does
 * not execute providers or rivals; missing comparable evidence becomes a gap,
 * not a fabricated superiority claim.
 */
final class AtlasDevBeatTestReportService
{
    public const SCHEMA_VERSION = 'atlas.programming.dev_beat_test_report.v1';

    public const EVIDENCE_SCHEMA_VERSION = 'atlas.programming.dev_beat_test_evidence.v1';

    public const BACKLOG_SOURCE = 'dev_beat_test:l4_9';

    public const DEFAULT_MAX_SECONDS = 900;

    public const DEFAULT_RECEIPT_LIMIT = 50;

    /** @var list<string> */
    public const REQUIRED_TASK_TYPES = ['bug', 'feature', 'refactor'];

    /** @var list<string> */
    private const ALLOWED_EXTERNAL_BASELINE_RUNNERS = ['claude_code', 'cursor', 'operator_approved_equivalent'];

    /** @var list<string> */
    private const ATLAS_DEV_TABLES = [
        'atlas_dev_run_index',
        'atlas_dev_run_certifications',
        'atlas_dev_task_packets',
        'atlas_dev_context_gates',
        'atlas_dev_failure_capsules',
        'atlas_dev_outcome_memories',
    ];

    /** @var list<string> */
    private const FIXTURE_OR_GENERATED_PATH_MARKERS = [
        'src/SmokeSubject.php',
        'tests/SmokeSubjectTest.php',
        'src/Button.css',
        'resources/views/status-card.blade.php',
        'GENERATED_OBJECTIVE_',
        'atlas_generated_',
        '/Generated/',
        '\\Generated\\',
    ];

    public function __construct(
        private readonly AtlasLoopBacklogManifestService $manifest,
    ) {}

    /**
     * @param  array{evidence_path?:string|null,max_seconds?:int,write_backlog?:bool,manifest_path?:string|null,manifest_limit?:int,write_report?:bool,report_path?:string|null,receipt_root?:string|null,receipt_limit?:int}  $options
     * @return array<string,mixed>
     */
    public function report(array $options = []): array
    {
        $maxSeconds = max(1, (int) ($options['max_seconds'] ?? self::DEFAULT_MAX_SECONDS));
        $evidencePath = $this->stringOrNull($options['evidence_path'] ?? null);
        $evidence = $this->loadEvidence($evidencePath);
        $tasks = $this->normalizeTasks((array) ($evidence['tasks'] ?? []));
        $receiptAutopsy = $this->receiptAutopsy(
            $this->stringOrNull($options['receipt_root'] ?? null) ?? storage_path('atlas-dev/receipts'),
            max(1, min(500, (int) ($options['receipt_limit'] ?? self::DEFAULT_RECEIPT_LIMIT))),
        );

        $taskReports = [];
        foreach (self::REQUIRED_TASK_TYPES as $type) {
            $taskReports[] = $this->evaluateTask($type, $tasks[$type] ?? null, $maxSeconds);
        }

        $gaps = $this->buildGaps($taskReports);
        $actions = $this->backlogActions($gaps, [
            'write' => (bool) ($options['write_backlog'] ?? false),
            'manifest_path' => $this->stringOrNull($options['manifest_path'] ?? null) ?? $this->manifest->defaultPath(),
            'manifest_limit' => max(10, (int) ($options['manifest_limit'] ?? 200)),
        ]);

        $atlasPassed = count(array_filter(
            $taskReports,
            static fn (array $task): bool => ($task['status'] ?? null) === 'atlas_dev_passed',
        ));
        $atlasFailed = count(array_filter(
            $taskReports,
            static fn (array $task): bool => ($task['status'] ?? null) === 'atlas_dev_failed',
        ));
        $atlasMissing = count(array_filter(
            $taskReports,
            static fn (array $task): bool => in_array($task['status'] ?? null, ['missing_evidence', 'atlas_dev_not_run'], true),
        ));
        $comparable = count(array_filter(
            $taskReports,
            static fn (array $task): bool => (bool) data_get($task, 'baseline.executed', false),
        ));
        $atlasWins = count(array_filter(
            $taskReports,
            static fn (array $task): bool => data_get($task, 'comparison.beats_external') === true,
        ));
        $externalWins = count(array_filter(
            $taskReports,
            static fn (array $task): bool => data_get($task, 'comparison.beats_external') === false,
        ));
        $allComparable = $comparable === count(self::REQUIRED_TASK_TYPES);
        $allAtlasWon = $allComparable && $atlasWins === count(self::REQUIRED_TASK_TYPES);

        $status = $this->status($atlasPassed, $atlasFailed, $atlasMissing, $allComparable, $allAtlasWon);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'evidence' => [
                'schema_version' => $evidence['schema_version'] ?? null,
                'path' => $evidencePath,
                'status' => $evidence['status'] ?? 'loaded',
                'load_error' => $evidence['load_error'] ?? null,
            ],
            'fixed_criteria' => [
                'required_task_types' => self::REQUIRED_TASK_TYPES,
                'difficulty_required' => 'medium',
                'tests_must_pass' => true,
                'scope_must_pass' => true,
                'max_duration_seconds' => $maxSeconds,
            ],
            'summary' => [
                'task_count' => count($taskReports),
                'atlas_dev_passed_count' => $atlasPassed,
                'atlas_dev_failed_count' => $atlasFailed,
                'atlas_dev_missing_count' => $atlasMissing,
                'comparable_external_count' => $comparable,
                'atlas_win_count' => $atlasWins,
                'external_win_count' => $externalWins,
                'gap_count' => count($gaps),
            ],
            'tasks' => $taskReports,
            'claim_policy' => [
                'provider_dispatches_now' => false,
                'external_rival_provider_invoked_by_report' => false,
                'synthetic_scores_allowed' => false,
                'receipt_autopsy_auto_promotes_tasks' => false,
                'internal_atlas_dev_measurement_allowed' => $atlasPassed > 0,
                'external_comparison_claim_allowed' => $allComparable,
                'external_superiority_claim_allowed' => $allAtlasWon,
                'honest_operator_answer' => $this->operatorAnswer($status),
            ],
            'receipt_autopsy' => $receiptAutopsy,
            'gaps' => $gaps,
            'backlog' => [
                'writes_enabled' => (bool) ($options['write_backlog'] ?? false),
                'manifest_path' => $actions['manifest_path'],
                'actions' => $actions['actions'],
                'enqueued_count' => $actions['enqueued_count'],
                'dry_run_count' => $actions['dry_run_count'],
            ],
            'commands_next' => [
                'collect_real_atlas_dev_evidence' => 'Run three Atlas Dev tasks externally and save evidence as '.self::EVIDENCE_SCHEMA_VERSION,
                'score_evidence' => 'php artisan atlas:dev:beat-test --evidence=<path> --json',
                'enqueue_gaps' => 'php artisan atlas:dev:beat-test --evidence=<path> --enqueue-backlog-gaps --json',
            ],
        ];

        $reportPath = $this->stringOrNull($options['report_path'] ?? null);
        if ((bool) ($options['write_report'] ?? false)) {
            $reportPath ??= storage_path('app/atlas/dev-beat-test/latest-report.json');
            File::ensureDirectoryExists(dirname($reportPath));
            File::put($reportPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $payload['written_report_path'] = $reportPath;
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadEvidence(?string $path): array
    {
        if ($path === null) {
            return [
                'schema_version' => self::EVIDENCE_SCHEMA_VERSION,
                'status' => 'missing',
                'tasks' => [],
            ];
        }
        if (! is_file($path)) {
            return [
                'schema_version' => self::EVIDENCE_SCHEMA_VERSION,
                'status' => 'missing',
                'path' => $path,
                'tasks' => [],
            ];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [
                'schema_version' => self::EVIDENCE_SCHEMA_VERSION,
                'status' => 'invalid',
                'path' => $path,
                'load_error' => 'invalid_json',
                'tasks' => [],
            ];
        }

        return [
            ...$decoded,
            'status' => 'loaded',
            'path' => $path,
        ];
    }

    /**
     * @param  list<mixed>  $tasks
     * @return array<string,array<string,mixed>>
     */
    private function normalizeTasks(array $tasks): array
    {
        $normalized = [];
        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $type = $this->normalizeTaskType((string) ($task['task_type'] ?? $task['type'] ?? ''));
            if ($type === null || isset($normalized[$type])) {
                continue;
            }
            $normalized[$type] = $task;
        }

        return $normalized;
    }

    private function normalizeTaskType(string $type): ?string
    {
        $type = str_replace(['-', ' '], '_', strtolower(trim($type)));

        return match ($type) {
            'bug', 'bugfix', 'bug_fix', 'realistic_bugfix' => 'bug',
            'feature', 'backend_feature' => 'feature',
            'refactor', 'architecture_refactor' => 'refactor',
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>|null  $task
     * @return array<string,mixed>
     */
    private function evaluateTask(string $type, ?array $task, int $maxSeconds): array
    {
        if ($task === null) {
            return [
                'task_type' => $type,
                'status' => 'missing_evidence',
                'criteria' => $this->emptyCriteria($maxSeconds),
                'baseline' => ['executed' => false],
                'comparison' => ['beats_external' => null, 'reason' => 'task_evidence_missing'],
            ];
        }

        $atlas = (array) ($task['atlas_dev'] ?? []);
        $baseline = (array) ($task['baseline'] ?? $task['rival'] ?? []);
        $duration = $this->numberOrNull($atlas['duration_seconds'] ?? $atlas['duration_ms'] ?? null);
        if (is_int($duration) && array_key_exists('duration_ms', $atlas) && ! array_key_exists('duration_seconds', $atlas)) {
            $duration = (int) ceil($duration / 1000);
        }
        $difficulty = strtolower(trim((string) ($task['difficulty'] ?? $task['risk_band'] ?? '')));
        $executed = (bool) ($atlas['executed'] ?? false);
        $criteria = [
            'medium_task' => $difficulty === 'medium' || $difficulty === 'l3',
            'tests_passed' => (bool) ($atlas['tests_passed'] ?? data_get($atlas, 'validation.tests_passed', false)),
            'scope_passed' => (bool) ($atlas['scope_passed'] ?? data_get($atlas, 'scope.passed', false)),
            'time_passed' => is_int($duration) && $duration <= $maxSeconds,
            'duration_seconds' => $duration,
            'max_duration_seconds' => $maxSeconds,
        ];
        $passed = $executed
            && $criteria['medium_task']
            && $criteria['tests_passed']
            && $criteria['scope_passed']
            && $criteria['time_passed'];

        return [
            'task_type' => $type,
            'task_id' => (string) ($task['id'] ?? $task['case_id'] ?? $type),
            'title' => (string) ($task['title'] ?? $type),
            'target_path' => $this->stringOrNull($task['target_path'] ?? data_get($task, 'gap.target_path')),
            'status' => ! $executed ? 'atlas_dev_not_run' : ($passed ? 'atlas_dev_passed' : 'atlas_dev_failed'),
            'atlas_dev' => [
                'executed' => $executed,
                'provider' => $this->stringOrNull($atlas['provider'] ?? null),
                'model' => $this->stringOrNull($atlas['model'] ?? null),
                'duration_seconds' => $duration,
                'changed_files' => array_values(array_filter((array) ($atlas['changed_files'] ?? []), 'is_string')),
                'validation_commands' => array_values(array_filter((array) ($atlas['validation_commands'] ?? []), 'is_array')),
                'evidence_refs' => array_values(array_filter((array) ($atlas['evidence_refs'] ?? []), 'is_string')),
            ],
            'criteria' => $criteria,
            'baseline' => $this->baselineSummary($baseline, $maxSeconds),
            'comparison' => $this->comparison($passed, $duration, $baseline, $maxSeconds),
            'gap_hint' => is_array($task['gap'] ?? null) ? $task['gap'] : [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyCriteria(int $maxSeconds): array
    {
        return [
            'medium_task' => false,
            'tests_passed' => false,
            'scope_passed' => false,
            'time_passed' => false,
            'duration_seconds' => null,
            'max_duration_seconds' => $maxSeconds,
        ];
    }

    /**
     * @param  array<string,mixed>  $baseline
     * @return array<string,mixed>
     */
    private function baselineSummary(array $baseline, int $maxSeconds): array
    {
        $duration = $this->numberOrNull($baseline['duration_seconds'] ?? $baseline['duration_ms'] ?? null);
        if (is_int($duration) && array_key_exists('duration_ms', $baseline) && ! array_key_exists('duration_seconds', $baseline)) {
            $duration = (int) ceil($duration / 1000);
        }
        $tests = (bool) ($baseline['tests_passed'] ?? false);
        $scope = (bool) ($baseline['scope_passed'] ?? false);
        $rawExecuted = (bool) ($baseline['executed'] ?? false);
        $provider = $this->stringOrNull($baseline['provider'] ?? $baseline['runner'] ?? null);
        $model = $this->stringOrNull($baseline['model'] ?? null);
        $changedFiles = array_values(array_filter((array) ($baseline['changed_files'] ?? []), 'is_string'));
        $validationCommands = array_values(array_filter((array) ($baseline['validation_commands'] ?? []), 'is_array'));
        $evidenceRefs = array_values(array_filter((array) ($baseline['evidence_refs'] ?? []), 'is_string'));
        $evidenceBlockers = $this->baselineEvidenceBlockers(
            $rawExecuted,
            $provider,
            $model,
            $changedFiles,
            $validationCommands,
            $evidenceRefs,
            $baseline,
        );
        $evidenceComplete = $evidenceBlockers === [];

        return [
            'executed' => $rawExecuted && $evidenceComplete,
            'raw_executed' => $rawExecuted,
            'provider' => $provider,
            'model' => $model,
            'duration_seconds' => $duration,
            'changed_files' => $changedFiles,
            'validation_commands' => $validationCommands,
            'evidence_refs' => $evidenceRefs,
            'evidence_complete' => $evidenceComplete,
            'evidence_blockers' => $evidenceBlockers,
            'allowed_external_baseline_runners' => self::ALLOWED_EXTERNAL_BASELINE_RUNNERS,
            'passed_fixed_criteria' => $rawExecuted
                && $evidenceComplete
                && $tests
                && $scope
                && is_int($duration)
                && $duration <= $maxSeconds,
        ];
    }

    /**
     * @param  array<string,mixed>  $baseline
     * @return array<string,mixed>
     */
    private function comparison(bool $atlasPassed, ?int $atlasDuration, array $baseline, int $maxSeconds): array
    {
        $baselineSummary = $this->baselineSummary($baseline, $maxSeconds);
        if (! (bool) $baselineSummary['executed']) {
            return [
                'beats_external' => null,
                'reason' => (bool) ($baselineSummary['raw_executed'] ?? false)
                    ? 'external_baseline_evidence_incomplete'
                    : 'comparable_external_evidence_missing',
                'evidence_blockers' => (array) ($baselineSummary['evidence_blockers'] ?? []),
            ];
        }
        if (! $atlasPassed) {
            return ['beats_external' => false, 'reason' => 'atlas_dev_failed_fixed_criteria'];
        }
        if (! (bool) $baselineSummary['passed_fixed_criteria']) {
            return ['beats_external' => true, 'reason' => 'atlas_passed_external_failed_fixed_criteria'];
        }
        $baselineDuration = $baselineSummary['duration_seconds'];
        if (is_int($atlasDuration) && is_int($baselineDuration) && $atlasDuration < $baselineDuration) {
            return ['beats_external' => true, 'reason' => 'atlas_passed_and_was_faster'];
        }
        if (is_int($atlasDuration) && is_int($baselineDuration) && $atlasDuration > $baselineDuration) {
            return ['beats_external' => false, 'reason' => 'external_passed_and_was_faster'];
        }

        return ['beats_external' => null, 'reason' => 'fixed_criteria_tie'];
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  list<array<string,mixed>>  $validationCommands
     * @param  list<string>  $evidenceRefs
     * @param  array<string,mixed>  $baseline
     * @return list<string>
     */
    private function baselineEvidenceBlockers(
        bool $executed,
        ?string $provider,
        ?string $model,
        array $changedFiles,
        array $validationCommands,
        array $evidenceRefs,
        array $baseline,
    ): array {
        if (! $executed) {
            return ['external_baseline_not_executed'];
        }

        $blockers = [];
        if ($provider === null || ! in_array(strtolower($provider), self::ALLOWED_EXTERNAL_BASELINE_RUNNERS, true)) {
            $blockers[] = 'external_baseline_runner_not_allowed';
        }
        if ($model === null) {
            $blockers[] = 'external_baseline_model_missing';
        }
        if ($changedFiles === []) {
            $blockers[] = 'external_baseline_changed_files_missing';
        }
        if ($validationCommands === []) {
            $blockers[] = 'external_baseline_validation_commands_missing';
        }
        if ($evidenceRefs === []) {
            $blockers[] = 'external_baseline_evidence_refs_missing';
        }
        if ($this->hasTemplateOrPlaceholderMarker($baseline)) {
            $blockers[] = 'external_baseline_template_or_placeholder_evidence_not_allowed';
        }

        return $blockers;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hasTemplateOrPlaceholderMarker(array $payload): bool
    {
        if ((bool) ($payload['template_only'] ?? false) || (bool) ($payload['template'] ?? false) || (bool) ($payload['is_template'] ?? false)) {
            return true;
        }

        return $this->containsPlaceholderString($payload);
    }

    private function containsPlaceholderString(mixed $value): bool
    {
        if (is_string($value)) {
            return preg_match('/<[^<>]+>/', $value) === 1;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $child) {
            if ($this->containsPlaceholderString($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $taskReports
     * @return list<array<string,mixed>>
     */
    private function buildGaps(array $taskReports): array
    {
        $gaps = [];
        foreach ($taskReports as $task) {
            $type = (string) ($task['task_type'] ?? 'task');
            $status = (string) ($task['status'] ?? 'unknown');
            if (in_array($status, ['missing_evidence', 'atlas_dev_not_run'], true)) {
                $gaps[] = [
                    'task_type' => $type,
                    'path' => 'docs/engineering-knowledge-base/atlas-pre-benchmark-readiness-audit.md',
                    'objective' => sprintf(
                        'Coletar evidence real de Atlas Dev para a tarefa média %s antes de qualquer claim de beat-test.',
                        $type,
                    ),
                    'priority' => 0.68,
                    'reason' => 'atlas_dev_evidence_missing:'.$type,
                ];

                continue;
            }
            if ($status !== 'atlas_dev_passed') {
                $gaps[] = $this->gapForTask($task, 'atlas_dev_fixed_criteria_not_met');
            }
            if ((bool) data_get($task, 'baseline.executed', false) === false) {
                $gaps[] = [
                    'task_type' => $type,
                    'path' => 'docs/engineering-knowledge-base/atlas-pre-benchmark-readiness-audit.md',
                    'objective' => sprintf(
                        'Coletar evidência comparável autorizada para a tarefa média %s do beat-test sem admitir score sintético.',
                        $type,
                    ),
                    'priority' => 0.63,
                    'reason' => 'comparable_external_evidence_missing:'.$type,
                ];
            }
        }

        return $this->dedupeGaps($gaps);
    }

    /**
     * @param  array<string,mixed>  $task
     * @return array<string,mixed>
     */
    private function gapForTask(array $task, string $reason): array
    {
        $hint = is_array($task['gap_hint'] ?? null) ? $task['gap_hint'] : [];
        $type = (string) ($task['task_type'] ?? 'task');
        $path = $this->stringOrNull($hint['target_path'] ?? null)
            ?? $this->stringOrNull($task['target_path'] ?? null)
            ?? $this->defaultPathForType($type);

        return [
            'task_type' => $type,
            'path' => $path,
            'objective' => $this->stringOrNull($hint['objective'] ?? null)
                ?? sprintf('Fechar gap do beat-test Atlas Dev para tarefa média %s: %s.', $type, $reason),
            'priority' => max(0.0, min(1.0, (float) ($hint['priority'] ?? 0.72))),
            'reason' => $reason,
        ];
    }

    private function defaultPathForType(string $type): string
    {
        return match ($type) {
            'bug' => 'app/Services/Ai/Programming/ProgrammingRivalsReadinessService.php',
            'feature' => 'app/Services/Ai/Programming/BenchmarkReadiness/BenchmarkReadinessHarness.php',
            'refactor' => 'app/Services/Ai/Programming/AtlasDevBeatTestReportService.php',
            default => 'docs/engineering-knowledge-base/atlas-pre-benchmark-readiness-audit.md',
        };
    }

    /**
     * @param  list<array<string,mixed>>  $gaps
     * @return list<array<string,mixed>>
     */
    private function dedupeGaps(array $gaps): array
    {
        $seen = [];
        $deduped = [];
        foreach ($gaps as $gap) {
            $key = implode('|', [
                (string) ($gap['task_type'] ?? ''),
                (string) ($gap['path'] ?? ''),
                (string) ($gap['reason'] ?? ''),
            ]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $gap;
        }

        return $deduped;
    }

    /**
     * @param  list<array<string,mixed>>  $gaps
     * @param  array{write:bool,manifest_path:string,manifest_limit:int}  $options
     * @return array{manifest_path:string,actions:list<array<string,mixed>>,enqueued_count:int,dry_run_count:int}
     */
    private function backlogActions(array $gaps, array $options): array
    {
        $actions = [];
        foreach ($gaps as $gap) {
            $item = [
                'path' => (string) ($gap['path'] ?? ''),
                'objective' => (string) ($gap['objective'] ?? ''),
                'priority' => (float) ($gap['priority'] ?? 0.7),
                'source' => self::BACKLOG_SOURCE,
                'source_key' => hash('sha256', self::BACKLOG_SOURCE.'|'.(string) ($gap['task_type'] ?? '').'|'.(string) ($gap['path'] ?? '').'|'.(string) ($gap['reason'] ?? '')),
                'reason' => (string) ($gap['reason'] ?? 'dev_beat_test_gap'),
                'observed_at' => Carbon::now()->toIso8601String(),
            ];
            $actions[] = $this->manifest->append(
                $options['manifest_path'],
                $item,
                $options['manifest_limit'],
                $options['write'],
            );
        }

        return [
            'manifest_path' => $options['manifest_path'],
            'actions' => $actions,
            'enqueued_count' => count(array_filter($actions, static fn (array $a): bool => ($a['status'] ?? null) === 'enqueued')),
            'dry_run_count' => count(array_filter($actions, static fn (array $a): bool => ($a['status'] ?? null) === 'dry_run')),
        ];
    }

    private function status(int $atlasPassed, int $atlasFailed, int $atlasMissing, bool $allComparable, bool $allAtlasWon): string
    {
        if ($atlasMissing > 0) {
            return 'evidence_missing';
        }
        if ($atlasFailed > 0) {
            return 'atlas_dev_failed';
        }
        if ($atlasPassed === count(self::REQUIRED_TASK_TYPES) && ! $allComparable) {
            return 'external_claim_blocked';
        }
        if ($allAtlasWon) {
            return 'atlas_dev_beats_baseline';
        }

        return 'comparable_report_ready_no_superiority';
    }

    private function operatorAnswer(string $status): string
    {
        return match ($status) {
            'atlas_dev_beats_baseline' => 'Atlas Dev beat all three comparable medium tasks under the fixed criteria.',
            'comparable_report_ready_no_superiority' => 'Comparable evidence exists, but Atlas Dev did not beat every task; report the split honestly.',
            'external_claim_blocked' => 'Atlas Dev passed the internal medium tasks, but no external superiority claim is allowed without comparable rival evidence.',
            'atlas_dev_failed' => 'At least one Atlas Dev task failed fixed criteria; backlog gaps were emitted.',
            default => 'Required Atlas Dev evidence is missing or incomplete; no benchmark claim is allowed.',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function receiptAutopsy(string $root, int $limit): array
    {
        $dbCounts = $this->atlasDevTableCounts();
        if (! is_dir($root)) {
            return [
                'schema_version' => 'atlas.programming.dev_beat_test_receipt_autopsy.v1',
                'status' => 'receipt_root_missing',
                'receipt_root' => $root,
                'receipt_limit' => $limit,
                'database_counts' => $dbCounts,
                'summary' => [
                    'scanned_count' => 0,
                    'real_hermes_gpt_55_passed_count' => 0,
                    'candidate_repo_receipt_count' => 0,
                    'fixture_or_generated_rejection_count' => 0,
                ],
                'runs' => [],
                'rejection_counts' => [],
                'honesty_note' => 'Receipt autopsy is read-only and never dispatches providers or auto-promotes L4-9 benchmark claims.',
            ];
        }

        $directories = array_values(array_filter(File::directories($root), 'is_dir'));
        usort(
            $directories,
            static fn (string $left, string $right): int => (filemtime($right) ?: 0) <=> (filemtime($left) ?: 0),
        );
        $directories = array_slice($directories, 0, $limit);

        $runs = [];
        $rejectionCounts = [];
        foreach ($directories as $directory) {
            $run = $this->evaluateReceiptDirectory($directory);
            $runs[] = $run;
            foreach ((array) ($run['rejection_reasons'] ?? []) as $reason) {
                if (! is_string($reason) || $reason === '') {
                    continue;
                }
                $rejectionCounts[$reason] = ($rejectionCounts[$reason] ?? 0) + 1;
            }
        }

        ksort($rejectionCounts);
        $realHermesPassed = count(array_filter(
            $runs,
            static fn (array $run): bool => (bool) ($run['real_hermes_gpt_55_passed'] ?? false),
        ));
        $candidates = count(array_filter(
            $runs,
            static fn (array $run): bool => (bool) ($run['candidate_repo_receipt'] ?? false),
        ));
        $fixtureRejected = (int) ($rejectionCounts['fixture_or_generated_target'] ?? 0);

        return [
            'schema_version' => 'atlas.programming.dev_beat_test_receipt_autopsy.v1',
            'status' => $candidates > 0 ? 'candidate_repo_receipts_found_not_auto_promoted' : 'no_candidate_repo_receipts',
            'receipt_root' => $root,
            'receipt_limit' => $limit,
            'database_counts' => $dbCounts,
            'summary' => [
                'scanned_count' => count($runs),
                'real_hermes_gpt_55_passed_count' => $realHermesPassed,
                'candidate_repo_receipt_count' => $candidates,
                'fixture_or_generated_rejection_count' => $fixtureRejected,
            ],
            'runs' => $runs,
            'rejection_counts' => $rejectionCounts,
            'honesty_note' => 'Candidate receipts still require explicit task typing, medium-difficulty evidence, and comparable external baseline before L4-9 can turn green.',
        ];
    }

    /**
     * @return list<array{table:string,available:bool,count:int|null}>
     */
    private function atlasDevTableCounts(): array
    {
        $counts = [];
        foreach (self::ATLAS_DEV_TABLES as $table) {
            if (! DatabaseTableAvailability::has($table)) {
                $counts[] = [
                    'table' => $table,
                    'available' => false,
                    'count' => null,
                ];

                continue;
            }

            try {
                $count = (int) DB::table($table)->count();
            } catch (Throwable) {
                $count = null;
            }

            $counts[] = [
                'table' => $table,
                'available' => true,
                'count' => $count,
            ];
        }

        return $counts;
    }

    /**
     * @return array<string,mixed>
     */
    private function evaluateReceiptDirectory(string $directory): array
    {
        $runId = basename($directory);
        $execution = $this->readJsonFile($directory.'/senior_engineer_loop_execution.json');
        $verification = $this->readJsonFile($directory.'/verification_receipt.json');
        $scope = $this->readJsonFile($directory.'/scope_guard_receipt.json');
        $contract = $this->readJsonFile($directory.'/task_contract.json');
        $providerCall = $this->readJsonFile($directory.'/provider_call_result.json');
        $diff = $this->readJsonFile($directory.'/diff_parse_result.json');

        $provider = $this->stringOrNull(data_get($execution, 'run_summary.provider_call.provider'))
            ?? $this->stringOrNull(data_get($providerCall, 'actual_provider'))
            ?? $this->stringOrNull($verification['provider'] ?? null)
            ?? $this->stringOrNull(data_get($contract, 'provider_lock.provider'));
        $model = $this->stringOrNull(data_get($execution, 'run_summary.provider_call.model_family'))
            ?? $this->stringOrNull(data_get($providerCall, 'actual_model_family'))
            ?? $this->stringOrNull($verification['model'] ?? null)
            ?? $this->stringOrNull(data_get($contract, 'provider_lock.model_family'));
        $providerCalls = $this->numberOrNull(data_get($execution, 'run_summary.provider_call.provider_calls'))
            ?? $this->numberOrNull(data_get($verification, 'cost.provider_calls'));
        $durationMs = $this->numberOrNull(data_get($execution, 'run_summary.provider_call.duration_ms'))
            ?? $this->numberOrNull(data_get($providerCall, 'duration_ms'))
            ?? $this->numberOrNull(data_get($verification, 'cost.wall_time_ms'));
        $runStatus = $this->stringOrNull($execution['status'] ?? data_get($execution, 'run_summary.completion_state') ?? null);
        $verificationStatus = $this->stringOrNull(data_get($execution, 'run_summary.verification_status'))
            ?? $this->stringOrNull(data_get($verification, 'completion.status'));
        $scopeStatus = $this->stringOrNull(data_get($execution, 'run_summary.scope_guard_status'))
            ?? $this->stringOrNull($scope['status'] ?? null);
        $changedFiles = $this->stringList($verification['changed_files'] ?? data_get($scope, 'observed.changed_files') ?? $diff['changed_files'] ?? []);
        $testCount = count(array_filter((array) ($verification['tests'] ?? []), 'is_array'));
        $testsPassed = $testCount > 0 && count(array_filter(
            (array) ($verification['tests'] ?? []),
            static fn (mixed $test): bool => is_array($test) && (bool) ($test['ok'] ?? false) === true && (int) ($test['exit_code'] ?? 1) === 0,
        )) === $testCount;

        $reasons = [];
        if ($provider !== 'hermes_cli') {
            $reasons[] = 'provider_not_hermes_cli';
        }
        if ($model === null || ! str_contains(strtolower($model), 'gpt-5.5')) {
            $reasons[] = 'model_not_gpt_5_5';
        }
        if (! is_int($providerCalls) || $providerCalls < 1) {
            $reasons[] = 'no_real_provider_call';
        }
        if ($runStatus !== 'passed') {
            $reasons[] = 'run_not_passed';
        }
        if ($verificationStatus !== 'passed') {
            $reasons[] = 'verification_not_passed';
        }
        if ($scopeStatus !== 'passed') {
            $reasons[] = 'scope_guard_not_passed';
        }
        if ($changedFiles === []) {
            $reasons[] = 'no_changed_files';
        }
        if ($this->isFixtureOrGeneratedTarget($changedFiles)) {
            $reasons[] = 'fixture_or_generated_target';
        }
        if (! $testsPassed) {
            $reasons[] = 'tests_missing_or_failed';
        }

        $realHermesPassed = $provider === 'hermes_cli'
            && is_string($model)
            && str_contains(strtolower($model), 'gpt-5.5')
            && is_int($providerCalls)
            && $providerCalls > 0
            && $runStatus === 'passed'
            && $verificationStatus === 'passed'
            && $scopeStatus === 'passed';

        return [
            'run_id' => $runId,
            'path' => $directory,
            'status' => $reasons === [] ? 'candidate_repo_receipt' : 'rejected',
            'provider' => $provider,
            'model' => $model,
            'provider_calls' => $providerCalls,
            'duration_ms' => $durationMs,
            'run_status' => $runStatus,
            'verification_status' => $verificationStatus,
            'scope_guard_status' => $scopeStatus,
            'changed_files' => $changedFiles,
            'validation_commands' => $this->stringList($contract['validation_commands'] ?? []),
            'test_count' => $testCount,
            'tests_passed' => $testsPassed,
            'real_hermes_gpt_55_passed' => $realHermesPassed,
            'candidate_repo_receipt' => $reasons === [],
            'rejection_reasons' => $reasons,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readJsonFile(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        $values = is_array($value) ? $value : [];

        return array_values(array_filter($values, 'is_string'));
    }

    /**
     * @param  list<string>  $changedFiles
     */
    private function isFixtureOrGeneratedTarget(array $changedFiles): bool
    {
        if ($changedFiles === []) {
            return false;
        }

        foreach ($changedFiles as $path) {
            foreach (self::FIXTURE_OR_GENERATED_PATH_MARKERS as $marker) {
                if (str_contains($path, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function numberOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, (int) ceil((float) $value));
    }
}
