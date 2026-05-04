<?php

namespace App\Console\Commands;

use App\Models\AtlasEngineeringRun;
use App\Services\Engineering\EngineeringBenchmarkService;
use Illuminate\Console\Command;

class AtlasEngineeringBenchmarkSeedCommand extends Command
{
    protected $signature = 'atlas:engineering:benchmark:seed
        {--suite=atlas-core-smoke : Suite slug to create or update}
        {--name= : Suite display name}
        {--description= : Suite description}
        {--workspace= : Workspace path used to scope/promote runs}
        {--from-run=* : Promote one or more engineering run ids into benchmark cases}
        {--from-recent-runs=0 : Promote the latest eligible resolved runs}
        {--fair-claude-corpus : Seed the versioned Fair Claude medium/hard benchmark corpus skeleton}
        {--min-source-score=85 : Minimum score for recent source runs}
        {--decision=resolved : Source decision filter for recent runs}
        {--expected-decision= : Expected benchmark decision for promoted cases}
        {--min-score= : Minimum benchmark score for promoted cases}
        {--tier=smoke : Corpus tier for promoted cases}
        {--domain= : Corpus domain slug for promoted cases}
        {--risk= : Risk profile: low, medium, high or critical}
        {--curation-status=curated : Curation status for promoted cases}
        {--tag=* : Extra tags applied to promoted cases}
        {--status=active : Case status for promoted cases}
        {--refresh-manifest : Refresh and persist the suite corpus manifest}
        {--json : Print machine-readable JSON}';

    protected $description = 'Create the default Atlas-Bench suite and promote real Harness runs into reusable benchmark cases.';

    public function handle(EngineeringBenchmarkService $benchmarks): int
    {
        $suite = $benchmarks->ensureDefaultSuite([
            'slug' => $this->option('suite'),
            'name' => $this->option('name'),
            'description' => $this->option('description'),
            'status' => 'active',
        ]);

        $promoted = collect();
        foreach ((array) $this->option('from-run') as $runId) {
            if (! is_string($runId) || trim($runId) === '') {
                continue;
            }

            $run = AtlasEngineeringRun::query()->find($runId);
            if (! $run) {
                $this->error("Engineering run nao encontrado: {$runId}");

                return self::FAILURE;
            }

            $promoted->push($benchmarks->promoteRunToCase($run, $suite, $this->caseOptions()));
        }

        $recentLimit = max(0, (int) $this->option('from-recent-runs'));
        if ($recentLimit > 0) {
            $promoted = $promoted->merge($benchmarks->promoteRecentRuns($suite, array_merge($this->caseOptions(), [
                'limit' => $recentLimit,
                'min_source_score' => (int) $this->option('min-source-score'),
                'decision' => $this->option('decision'),
            ])));
        }
        if ((bool) $this->option('fair-claude-corpus')) {
            foreach ($this->fairClaudeCorpusCases() as $case) {
                $promoted->push($benchmarks->registerCase($suite, array_replace_recursive($case, [
                    'workspace' => $this->workspace(),
                    'status' => $this->option('status') ?: 'active',
                ])));
            }
        }

        $promoted = $promoted
            ->unique('id')
            ->values();
        $manifest = ((bool) $this->option('refresh-manifest') || $promoted->isNotEmpty())
            ? $benchmarks->refreshCorpusManifest($suite->refresh())
            : (array) data_get($suite->metadata, 'corpus_manifest', []);
        $payload = [
            'suite' => $benchmarks->suitePayload($suite->refresh())['suite'] ?? null,
            'corpus_manifest' => $manifest,
            'promoted_count' => $promoted->count(),
            'promoted_cases' => $promoted
                ->map(fn ($case): array => $benchmarks->casePayload($case))
                ->values()
                ->all(),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->render($payload);

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function caseOptions(): array
    {
        return [
            'workspace' => $this->workspace(),
            'expected_decision' => is_string($this->option('expected-decision')) && trim((string) $this->option('expected-decision')) !== ''
                ? $this->option('expected-decision')
                : null,
            'min_score' => is_numeric($this->option('min-score')) ? (int) $this->option('min-score') : null,
            'corpus_tier' => is_string($this->option('tier')) ? $this->option('tier') : 'smoke',
            'domain_slug' => is_string($this->option('domain')) ? $this->option('domain') : null,
            'risk_profile' => is_string($this->option('risk')) ? $this->option('risk') : null,
            'curation_status' => is_string($this->option('curation-status')) ? $this->option('curation-status') : 'curated',
            'tags' => (array) $this->option('tag'),
            'status' => is_string($this->option('status')) ? $this->option('status') : 'active',
        ];
    }

    private function workspace(): ?string
    {
        $workspace = $this->option('workspace');
        if (! is_string($workspace) || trim($workspace) === '') {
            return null;
        }

        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fairClaudeCorpusCases(): array
    {
        return [
            [
                'case_code' => 'fair_cli_provider_lock_drift',
                'title' => 'Fair Claude provider lock drift',
                'description' => 'Implement a CLI/provider change without allowing Codex, Gemini, council or fallback to enter Fair Claude mode.',
                'task_contract' => [
                    'schema_version' => 1,
                    'difficulty' => 'medium',
                    'class' => 'cli_command',
                    'task_prompt' => 'Add or modify a CLI pathway while preserving Fair Claude provider/model lock invariants.',
                    'acceptance_criteria' => [
                        'Fair mode keeps provider locked to claude_cli.',
                        'Fair mode keeps model locked to Claude Opus.',
                        'Normal Atlas provider selection remains unchanged outside fair mode.',
                    ],
                    'allowed_files' => ['app/Console/Commands/**', 'app/Services/Ai/**', 'bin/atlas', 'tests/**'],
                    'forbidden_files' => ['config/atlas.php'],
                    'test_commands' => [
                        '/opt/homebrew/bin/php artisan test tests/Unit/FairClaudePolicyTest.php tests/Feature/AtlasCliDevCommandTest.php --filter=claude',
                    ],
                    'timeout_seconds' => 1800,
                    'judge_notes' => ['Reject solutions that change global default provider/model.'],
                ],
                'expected_decision' => 'resolved',
                'min_score' => 90,
                'corpus_tier' => 'release',
                'domain_slug' => 'cli',
                'risk_profile' => 'medium',
                'curation_status' => 'curated',
                'tags' => ['fair_claude', 'medium', 'cli_command', 'provider_lock'],
                'metadata' => [
                    'source' => 'fair_claude_seed_v1',
                    'fair_claude_official' => true,
                ],
            ],
            [
                'case_code' => 'fair_repair_capsule_gate_failure',
                'title' => 'Fair Claude repair capsule from deterministic gate failure',
                'description' => 'Repair a failing deterministic validation using a structured repair capsule while preserving the same Claude Opus invocation contract.',
                'task_contract' => [
                    'schema_version' => 1,
                    'difficulty' => 'hard',
                    'class' => 'first_patch_broken',
                    'task_prompt' => 'Fix a deliberately failing test through the Atlas repair loop without relying on Claude self-evaluation.',
                    'acceptance_criteria' => [
                        'Repair prompt includes command, exit code and primary error.',
                        'Provider/model remain claude_cli + Opus for every repair attempt.',
                        'Unverified output never counts as pass_without_human.',
                    ],
                    'allowed_files' => ['app/Services/Ai/Cli/**', 'app/Services/Engineering/**', 'tests/**'],
                    'forbidden_files' => ['app/Services/Ai/CodexCliProvider.php', 'app/Services/Ai/GeminiCliProvider.php'],
                    'test_commands' => [
                        '/opt/homebrew/bin/php artisan test tests/Unit/AtlasCliDevWorkflowServiceTest.php --filter=fair',
                    ],
                    'timeout_seconds' => 2400,
                    'judge_notes' => ['Reject if pass_without_human can be true without deterministic gates.'],
                ],
                'expected_decision' => 'resolved',
                'min_score' => 90,
                'corpus_tier' => 'release',
                'domain_slug' => 'repair_loop',
                'risk_profile' => 'high',
                'curation_status' => 'curated',
                'tags' => ['fair_claude', 'hard', 'repair_capsule', 'deterministic_gate'],
                'metadata' => [
                    'source' => 'fair_claude_seed_v1',
                    'fair_claude_official' => true,
                ],
            ],
            [
                'case_code' => 'fair_benchmark_replay_packet_integrity',
                'title' => 'Fair Claude benchmark replay packet integrity',
                'description' => 'Extend benchmark replay/reporting while keeping replay artifacts provider-safe, hashed and independently verifiable.',
                'task_contract' => [
                    'schema_version' => 1,
                    'difficulty' => 'medium',
                    'class' => 'observability_replay',
                    'task_prompt' => 'Change benchmark replay/report output and keep manifest integrity, replay command and final packet accurate.',
                    'acceptance_criteria' => [
                        'Replay manifest has a stable hash and packet hashes.',
                        'Artifact path is redacted from API/CLI JSON responses.',
                        'Final packet does not depend on Claude self-evaluation.',
                    ],
                    'allowed_files' => ['app/Services/Engineering/**', 'app/Console/Commands/AtlasEngineeringBenchmark*', 'tests/**'],
                    'forbidden_files' => ['app/Services/Ai/CodexCliProvider.php', 'app/Services/Ai/GeminiCliProvider.php'],
                    'test_commands' => [
                        '/opt/homebrew/bin/php artisan test tests/Feature/EngineeringHarnessRunnerTest.php --filter=replay',
                    ],
                    'timeout_seconds' => 1800,
                    'judge_notes' => ['Reject if persisted artifact integrity is not checked before replay output.'],
                ],
                'expected_decision' => 'resolved',
                'min_score' => 90,
                'corpus_tier' => 'release',
                'domain_slug' => 'benchmark',
                'risk_profile' => 'medium',
                'curation_status' => 'curated',
                'tags' => ['fair_claude', 'medium', 'replay', 'scorecard'],
                'metadata' => [
                    'source' => 'fair_claude_seed_v1',
                    'fair_claude_official' => true,
                ],
            ],
            [
                'case_code' => 'fair_dirty_workspace_scope_block',
                'title' => 'Fair Claude dirty workspace scope block',
                'description' => 'Handle dirty workspace and forbidden scope safely so automatic success is blocked when changes overlap unsafely.',
                'task_contract' => [
                    'schema_version' => 1,
                    'difficulty' => 'hard',
                    'class' => 'dirty_workspace',
                    'task_prompt' => 'Implement a scoped change in a dirty workspace and make the harness block automatic success on unsafe overlap.',
                    'acceptance_criteria' => [
                        'Dirty overlap is surfaced as a blocking or invalid condition.',
                        'Untracked files are captured in artifacts or warnings.',
                        'pass_without_human remains false when scope safety is unverified.',
                    ],
                    'allowed_files' => ['app/Services/Engineering/**', 'tests/**'],
                    'forbidden_files' => ['.env', 'composer.lock'],
                    'test_commands' => [
                        '/opt/homebrew/bin/php artisan test tests/Feature/EngineeringHarnessRunnerTest.php --filter=scope',
                    ],
                    'timeout_seconds' => 2400,
                    'judge_notes' => ['Reject if dirty workspace warnings are informational only in fair mode.'],
                ],
                'expected_decision' => 'resolved',
                'min_score' => 90,
                'corpus_tier' => 'release',
                'domain_slug' => 'workspace_safety',
                'risk_profile' => 'high',
                'curation_status' => 'candidate',
                'tags' => ['fair_claude', 'hard', 'dirty_workspace', 'scope'],
                'metadata' => [
                    'source' => 'fair_claude_seed_v1',
                    'fair_claude_official' => true,
                ],
            ],
            [
                'case_code' => 'fair_context_pack_budget_pressure',
                'title' => 'Fair Claude context pack under budget pressure',
                'description' => 'Prepare a compact, auditable context pack for a medium/difficult implementation without switching away from Claude Opus.',
                'task_contract' => [
                    'schema_version' => 1,
                    'difficulty' => 'hard',
                    'class' => 'context_engineering',
                    'task_prompt' => 'Improve task context selection so the provider receives the smallest useful audited context pack for a multi-file change.',
                    'acceptance_criteria' => [
                        'Context pack includes task contract, relevant files and deterministic validation commands.',
                        'Context budget truncation is explicit and replayable.',
                        'Fair mode metadata still locks provider/model to claude_cli + Opus.',
                    ],
                    'allowed_files' => ['app/Services/Ai/Cli/**', 'app/Services/Engineering/**', 'docs/**', 'tests/**'],
                    'forbidden_files' => ['config/atlas.php'],
                    'test_commands' => [
                        '/opt/homebrew/bin/php artisan test tests/Unit/AtlasCliDevWorkflowServiceTest.php --filter=context',
                    ],
                    'timeout_seconds' => 2400,
                    'judge_notes' => ['Reject if context compaction silently drops validation or fair-mode metadata.'],
                ],
                'expected_decision' => 'resolved',
                'min_score' => 90,
                'corpus_tier' => 'release',
                'domain_slug' => 'context_pack',
                'risk_profile' => 'high',
                'curation_status' => 'curated',
                'tags' => ['fair_claude', 'hard', 'context_pack', 'budget'],
                'metadata' => [
                    'source' => 'fair_claude_seed_v1',
                    'fair_claude_official' => true,
                ],
            ],
            [
                'case_code' => 'fair_prompt_contract_acceptance_matrix',
                'title' => 'Fair Claude prompt contract acceptance matrix',
                'description' => 'Create or update the provider prompt contract so acceptance criteria, file scope and deterministic gates are all machine-readable.',
                'task_contract' => [
                    'schema_version' => 1,
                    'difficulty' => 'medium',
                    'class' => 'prompt_contract',
                    'task_prompt' => 'Extend the Fair Claude task prompt contract while preserving replay and provider lock metadata.',
                    'acceptance_criteria' => [
                        'Prompt contract has explicit acceptance criteria and forbidden fallback/providers.',
                        'Contract fields are persisted in replay artifacts.',
                        'Plan-only output exposes the fair metadata without invoking another provider.',
                    ],
                    'allowed_files' => ['app/Services/Ai/**', 'app/Services/Engineering/**', 'tests/**'],
                    'forbidden_files' => ['app/Services/Ai/CodexCliProvider.php', 'app/Services/Ai/GeminiCliProvider.php'],
                    'test_commands' => [
                        '/opt/homebrew/bin/php artisan test tests/Feature/AtlasCliDevCommandTest.php --filter=plan_only',
                    ],
                    'timeout_seconds' => 1800,
                    'judge_notes' => ['Reject if acceptance criteria are only prose and cannot be replayed.'],
                ],
                'expected_decision' => 'resolved',
                'min_score' => 90,
                'corpus_tier' => 'release',
                'domain_slug' => 'prompt_contract',
                'risk_profile' => 'medium',
                'curation_status' => 'curated',
                'tags' => ['fair_claude', 'medium', 'prompt_contract', 'acceptance_matrix'],
                'metadata' => [
                    'source' => 'fair_claude_seed_v1',
                    'fair_claude_official' => true,
                ],
            ],
            [
                'case_code' => 'fair_final_packet_no_self_judge',
                'title' => 'Fair Claude final packet without self-judging',
                'description' => 'Emit a final packet that summarizes gates, attempts and replay data without allowing Claude self-evaluation to mark success.',
                'task_contract' => [
                    'schema_version' => 1,
                    'difficulty' => 'medium',
                    'class' => 'final_packet',
                    'task_prompt' => 'Add final packet fields for a completed Fair Claude run and ensure success comes only from deterministic gates.',
                    'acceptance_criteria' => [
                        'Final packet includes replay command, model/provider lock and gate status.',
                        'Claude self-assessment is never a passing gate.',
                        'Unverified packets are marked unverified or invalid, not passed.',
                    ],
                    'allowed_files' => ['app/Console/Commands/AtlasEngineeringBenchmark*', 'app/Services/Engineering/**', 'tests/**'],
                    'forbidden_files' => ['app/Services/Ai/CodexCliProvider.php', 'app/Services/Ai/GeminiCliProvider.php'],
                    'test_commands' => [
                        '/opt/homebrew/bin/php artisan test tests/Feature/EngineeringHarnessRunnerTest.php --filter=final_packet',
                    ],
                    'timeout_seconds' => 1800,
                    'judge_notes' => ['Reject if final packet claims passed when deterministic gate packets are missing.'],
                ],
                'expected_decision' => 'resolved',
                'min_score' => 90,
                'corpus_tier' => 'release',
                'domain_slug' => 'final_packet',
                'risk_profile' => 'medium',
                'curation_status' => 'curated',
                'tags' => ['fair_claude', 'medium', 'final_packet', 'deterministic_gate'],
                'metadata' => [
                    'source' => 'fair_claude_seed_v1',
                    'fair_claude_official' => true,
                ],
            ],
            [
                'case_code' => 'fair_paired_baseline_workspace_isolation',
                'title' => 'Fair Claude paired baseline workspace isolation',
                'description' => 'Run Atlas and Claude Code baseline on isolated workspaces so neither arm contaminates the other.',
                'task_contract' => [
                    'schema_version' => 1,
                    'difficulty' => 'hard',
                    'class' => 'paired_worktree',
                    'task_prompt' => 'Improve paired benchmark workspace isolation and prove both arms validate independently.',
                    'acceptance_criteria' => [
                        'Atlas arm and Claude Code arm use separate workspace paths.',
                        'Baseline replay packet does not expose raw local workspace path in API/report JSON.',
                        'Deterministic validation runs separately for each arm.',
                    ],
                    'allowed_files' => ['app/Services/Engineering/**', 'app/Console/Commands/AtlasEngineeringBenchmark*', 'tests/**'],
                    'forbidden_files' => ['.env', 'composer.lock'],
                    'test_commands' => [
                        '/opt/homebrew/bin/php artisan test tests/Unit/EngineeringBenchmarkFairClaudeScorecardTest.php --filter=baseline',
                    ],
                    'timeout_seconds' => 2400,
                    'judge_notes' => ['Reject if the baseline can pass by reusing Atlas arm artifacts.'],
                ],
                'expected_decision' => 'resolved',
                'min_score' => 90,
                'corpus_tier' => 'release',
                'domain_slug' => 'workspace_isolation',
                'risk_profile' => 'high',
                'curation_status' => 'candidate',
                'tags' => ['fair_claude', 'hard', 'baseline', 'workspace_isolation'],
                'metadata' => [
                    'source' => 'fair_claude_seed_v1',
                    'fair_claude_official' => true,
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): void
    {
        $suite = (array) ($payload['suite'] ?? []);
        $cases = collect((array) ($payload['promoted_cases'] ?? []));

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas-Bench Corpus</>', (string) ($suite['slug'] ?? '-'));
        $this->components->twoColumnDetail('Suite', (string) ($suite['name'] ?? '-'));
        $this->components->twoColumnDetail('Promoted cases', (string) ($payload['promoted_count'] ?? 0));
        $this->components->twoColumnDetail('Active corpus cases', (string) data_get($payload, 'corpus_manifest.active_cases', '-'));

        if ($cases->isNotEmpty()) {
            $this->newLine();
            $this->table(
                ['case', 'tier', 'risk', 'expected', 'min score', 'tags'],
                $cases
                    ->map(fn (array $case): array => [
                        $case['case_code'] ?? '-',
                        $case['corpus_tier'] ?? '-',
                        $case['risk_profile'] ?? '-',
                        $case['expected_decision'] ?? 'resolved',
                        $case['min_score'] ?? '-',
                        implode(', ', array_slice((array) ($case['tags'] ?? []), 0, 5)),
                    ])
                    ->all(),
            );
        }
    }
}
