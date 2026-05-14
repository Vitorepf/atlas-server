<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Http\Controllers\AtlasCodeCheckpointController;
use App\Http\Controllers\AtlasCodeForgeExecutionController;
use App\Http\Controllers\AtlasCodeForgeReviewController;
use App\Http\Controllers\AtlasCodeProgrammingWorkItemController;
use App\Http\Controllers\AtlasCodeWorkController;
use App\Models\AtlasProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Product-level Atlas Code certification.
 *
 * This creates an ephemeral Obra + Forge workspace and drives the same
 * product endpoints/controllers the desktop uses. It proves the heavy
 * programming loop without paid/external providers.
 */
class AtlasCodeEnterpriseCertificationService
{
    public const SCHEMA_VERSION = 'atlas.code.enterprise_certification.v1';

    /**
     * @return array<string,mixed>|null
     */
    public function latest(): ?array
    {
        if (! Schema::hasTable('atlas_projects')) {
            return null;
        }

        $candidates = AtlasProject::query()
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();

        foreach ($candidates as $candidate) {
            if (! $this->isSystemCertificationProject($candidate)) {
                continue;
            }

            $certification = data_get($candidate->metadata, 'latest_atlas_code_enterprise_certification');
            if (is_array($certification)
                && (string) ($certification['schema_version'] ?? '') === self::SCHEMA_VERSION) {
                return $certification;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function certify(array $options = []): array
    {
        $certificationId = (string) Str::ulid();
        $workspace = storage_path('app/atlas-code-enterprise-certification/'.$certificationId.'/workspace');
        $targetFile = 'app/Http/Controllers/AtlasCodeEnterpriseCertificationFixture.php';
        $absoluteTarget = $workspace.'/'.$targetFile;
        $stages = [];
        $blockers = [];
        $createdProject = null;

        try {
            $preflight = $this->preflight();
            $stages[] = $preflight;
            if ($preflight['status'] !== 'passed') {
                return $this->finalize($certificationId, null, $workspace, $targetFile, $stages, (array) ($preflight['blockers'] ?? []), []);
            }

            File::ensureDirectoryExists(dirname($absoluteTarget));
            File::put($absoluteTarget, "<?php\n\nfinal class AtlasCodeEnterpriseCertificationFixture {}\n");
            $initialHash = hash_file('sha256', $absoluteTarget) ?: null;

            $project = $this->createProject($certificationId, $workspace);
            $createdProject = $project;
            $stages[] = [
                'name' => 'obra_workspace_fixture',
                'status' => 'passed',
                'obra_id' => (string) $project->getKey(),
                'workspace_path_hash' => hash('sha256', $workspace),
                'target_file' => $targetFile,
                'target_hash' => $initialHash,
                'requires_obra' => true,
            ];

            $binding = $this->call(AtlasCodeProgrammingWorkItemController::class, 'store', [
                'request' => $this->post('/atlas-code/works/'.$project->getKey().'/programming/work-items', [
                    'intent' => 'certificar Atlas Code enterprise pesado com Obra, Forge, review, promotion, rollback e checkpoint',
                    'mode' => 'structural',
                    'risk' => 'high',
                    'owner' => 'atlas-code-certification',
                ]),
                'project' => $project->refresh(),
            ]);
            $workItemId = (string) data_get($binding['json'], 'work_item.id', '');
            $workItemCode = (string) data_get($binding['json'], 'work_item.code', '');
            $stages[] = $this->stageFromResponse('work_item_binding', $binding, [
                'work_item_id' => $workItemId,
                'work_item_code' => $workItemCode,
                'binding_flow_id' => data_get($binding['json'], 'binding.flow_id'),
            ]);
            if ($workItemId === '' || $binding['ok'] !== true) {
                $blockers[] = 'work_item_binding_failed';

                return $this->finalize($certificationId, $createdProject, $workspace, $targetFile, $stages, $blockers, [
                    'initial_hash' => $initialHash,
                ]);
            }

            $compiled = $this->call(AtlasCodeProgrammingWorkItemController::class, 'compileSpecPlan', [
                'request' => $this->post('/atlas-code/works/'.$project->getKey().'/programming/work-items/'.$workItemId.'/spec', [
                    'likely_files' => [
                        $targetFile,
                        'docs/engineering-knowledge-base/atlas-code-forge-live-execution-surface-contract.md',
                        'tests/Feature/AtlasCodeContractTest.php',
                    ],
                    'validation_commands' => ['php -r "echo \'atlas-code-enterprise-certification-ok\';"'],
                    'acceptance_criteria' => [
                        'Atlas Code binds Obra to a governed WorkItem',
                        'Forge Live Execution runs with task contract and evidence',
                        'Human review promotes and rollback restores the workspace',
                    ],
                    'evidence_required' => ['forge_live_execution', 'forge_workspace_promotion', 'forge_workspace_rollback'],
                    'context_stack' => 'atlas_code_enterprise',
                ]),
                'project' => $project->refresh(),
                'workItem' => $workItemId,
            ]);
            $stages[] = $this->stageFromResponse('spec_plan_task_queue', $compiled, [
                'spec_hash' => data_get($compiled['json'], 'spec_hash'),
                'plan_hash' => data_get($compiled['json'], 'plan_hash'),
                'tasks_count' => data_get($compiled['json'], 'tasks_count'),
                'next_action' => data_get($compiled['json'], 'next_action'),
            ]);
            if ($compiled['ok'] !== true) {
                $blockers[] = 'spec_plan_task_queue_failed';

                return $this->finalize($certificationId, $createdProject, $workspace, $targetFile, $stages, $blockers, [
                    'initial_hash' => $initialHash,
                ]);
            }

            /** @var AtlasForgeLiveExecutionService $liveService */
            $liveService = app(AtlasForgeLiveExecutionService::class);
            /** @var AtlasCodeForgeExecutionController $executionController */
            $executionController = app(AtlasCodeForgeExecutionController::class);
            $live = $executionController->executeAndPersist($project->refresh(), $liveService, false);
            $governed = (array) data_get($live, 'snapshot.governed_execution', []);
            $stages[] = [
                'name' => 'forge_live_execution_governed',
                'status' => data_get($live, 'snapshot.status') === 'passed'
                    && data_get($governed, 'status') === 'passed'
                    && data_get($governed, 'live_workspace_mutated') === false
                        ? 'passed'
                        : 'blocked',
                'live_status' => data_get($live, 'snapshot.status'),
                'governed_status' => data_get($governed, 'status'),
                'execution_mode' => data_get($governed, 'execution_mode'),
                'promotion_status' => data_get($governed, 'promotion_status'),
                'changed_files' => (array) data_get($governed, 'changed_files', []),
                'live_workspace_mutated' => (bool) data_get($governed, 'live_workspace_mutated', false),
                'evidence_pack_hash' => data_get($live, 'snapshot.evidence_pack.integrity.evidence_pack_hash'),
            ];
            if (end($stages)['status'] !== 'passed') {
                $blockers[] = 'forge_live_execution_governed_failed';

                return $this->finalize($certificationId, $createdProject, $workspace, $targetFile, $stages, $blockers, [
                    'initial_hash' => $initialHash,
                ]);
            }
            $shadowDidNotMutate = is_file($absoluteTarget) && ! str_contains((string) File::get($absoluteTarget), 'atlas-forge-governed-execution:');

            $stateAfterRun = $this->state($project->refresh());
            $historyId = (string) data_get($stateAfterRun, 'forge_live_execution_history.entries.0.history_id', '');
            $stages[] = [
                'name' => 'state_history_after_run',
                'status' => $historyId !== '' && $shadowDidNotMutate ? 'passed' : 'blocked',
                'history_id' => $historyId,
                'forge_task_queue_source' => data_get($stateAfterRun, 'forge_task_queue.source_authority'),
                'shadow_did_not_mutate_live_workspace' => $shadowDidNotMutate,
            ];
            if ($historyId === '' || ! $shadowDidNotMutate) {
                $blockers[] = 'run_history_or_shadow_integrity_failed';

                return $this->finalize($certificationId, $createdProject, $workspace, $targetFile, $stages, $blockers, [
                    'initial_hash' => $initialHash,
                ]);
            }

            $replay = $this->call(AtlasCodeForgeExecutionController::class, 'showHistory', [
                'project' => $project->refresh(),
                'historyId' => $historyId,
            ]);
            $stages[] = $this->stageFromResponse('history_replay_read_only', $replay, [
                'history_id' => $historyId,
                'read_only' => data_get($replay['json'], 'replay.read_only'),
                'snapshot_available' => data_get($replay['json'], 'snapshot_available'),
                'external_provider_call' => data_get($replay['json'], 'replay.external_provider_call'),
            ], fn (array $json): bool => data_get($json, 'replay.read_only') === true
                && data_get($json, 'snapshot_available') === true
                && data_get($json, 'replay.external_provider_call') === false);

            $review = $this->call(AtlasCodeForgeReviewController::class, 'store', [
                'request' => $this->post('/atlas-code/works/'.$project->getKey().'/forge/reviews', [
                    'decision' => 'approved',
                    'history_id' => $historyId,
                    'comment' => 'Atlas Code enterprise certification promotion',
                ]),
                'project' => $project->refresh(),
            ]);
            $promotionId = (string) data_get($review['json'], 'review.promotion.promotion_id', '');
            $stages[] = $this->stageFromResponse('human_review_promotion', $review, [
                'review_status' => data_get($review['json'], 'review.status'),
                'promotion_id' => $promotionId,
                'promotion_status' => data_get($review['json'], 'review.promotion.promotion_status'),
                'live_workspace_mutated' => data_get($review['json'], 'review.promotion.live_workspace_mutated'),
            ], fn (array $json): bool => data_get($json, 'review.status') === 'approved'
                && data_get($json, 'review.promotion.promotion_status') === 'promoted_to_workspace'
                && data_get($json, 'review.promotion.live_workspace_mutated') === true);
            $promotedHash = is_file($absoluteTarget) ? hash_file('sha256', $absoluteTarget) : null;
            $promotionMutated = is_file($absoluteTarget) && str_contains((string) File::get($absoluteTarget), 'atlas-forge-governed-execution:');
            if ($promotionId === '' || ! $promotionMutated) {
                $blockers[] = 'human_review_promotion_failed';

                return $this->finalize($certificationId, $createdProject, $workspace, $targetFile, $stages, $blockers, [
                    'initial_hash' => $initialHash,
                    'promoted_hash' => $promotedHash,
                ]);
            }

            $rollback = $this->call(AtlasCodeForgeReviewController::class, 'rollback', [
                'request' => $this->post('/atlas-code/works/'.$project->getKey().'/forge/promotions/'.$promotionId.'/rollback', [
                    'comment' => 'Atlas Code enterprise certification rollback',
                ]),
                'project' => $project->refresh(),
                'promotionId' => $promotionId,
            ]);
            $rollbackHash = is_file($absoluteTarget) ? hash_file('sha256', $absoluteTarget) : null;
            $rollbackRestored = $rollbackHash === $initialHash
                && is_file($absoluteTarget)
                && ! str_contains((string) File::get($absoluteTarget), 'atlas-forge-governed-execution:');
            $stages[] = $this->stageFromResponse('governed_rollback', $rollback, [
                'rollback_status' => data_get($rollback['json'], 'rollback.status'),
                'promotion_status' => data_get($rollback['json'], 'rollback.promotion_status'),
                'target_hash_after_rollback' => $rollbackHash,
                'restored_initial_hash' => $rollbackRestored,
            ], fn (array $json): bool => data_get($json, 'status') === 'rolled_back'
                && data_get($json, 'rollback.promotion_status') === 'rolled_back'
                && $rollbackRestored);
            if (! $rollbackRestored) {
                $blockers[] = 'governed_rollback_failed';
            }

            $checkpoint = $this->call(AtlasCodeCheckpointController::class, 'store', [
                'request' => $this->post('/atlas-code/works/'.$project->getKey().'/checkpoints', [
                    'reason' => 'atlas_code_enterprise_certification',
                ]),
                'project' => $project->refresh(),
            ]);
            $stages[] = $this->stageFromResponse('checkpoint_resume', $checkpoint, [
                'checkpoint_id' => data_get($checkpoint['json'], 'checkpoint.checkpoint_id'),
                'resume_ready' => data_get($checkpoint['json'], 'checkpoint.resume.resume_ready'),
                'next_safe_action' => data_get($checkpoint['json'], 'checkpoint.resume.next_safe_action'),
                'evidence_persisted' => data_get($checkpoint['json'], 'persistence.engineering_evidence_persisted'),
            ], fn (array $json): bool => data_get($json, 'checkpoint.checkpoint_id') !== null
                && data_get($json, 'persistence.project_metadata_persisted') === true);

            $finalState = $this->state($project->refresh());
            $rollbackEvidence = collect((array) data_get($finalState, 'programming_governance.evidence_refs', []))
                ->first(fn (mixed $receipt): bool => is_array($receipt)
                    && ($receipt['evidence_type'] ?? null) === 'forge_workspace_rollback');
            $stages[] = [
                'name' => 'final_state_read_model',
                'status' => data_get($finalState, 'forge_review.status') === 'rolled_back'
                    && data_get($finalState, 'forge_live_execution.governed_execution.promotion_status') === 'rolled_back'
                    && is_array($rollbackEvidence)
                        ? 'passed'
                        : 'blocked',
                'forge_review_status' => data_get($finalState, 'forge_review.status'),
                'governed_promotion_status' => data_get($finalState, 'forge_live_execution.governed_execution.promotion_status'),
                'rollback_evidence_present' => is_array($rollbackEvidence),
                'checkpoint_status' => data_get($finalState, 'checkpoint.status'),
            ];

            $cleanup = $this->cleanup($workspace, (bool) ($options['keep_workspace'] ?? false));
            $stages[] = $cleanup;

            $blockers = array_values(array_unique(array_merge(
                $blockers,
                collect($stages)
                    ->filter(fn (array $stage): bool => ($stage['status'] ?? null) === 'blocked')
                    ->map(fn (array $stage): string => (string) (($stage['blocker'] ?? null) ?: $stage['name'].'_blocked'))
                    ->all(),
            )));

            return $this->finalize($certificationId, $createdProject, $workspace, $targetFile, $stages, $blockers, [
                'initial_hash' => $initialHash,
                'promoted_hash' => $promotedHash,
                'rollback_hash' => $rollbackHash,
                'history_id' => $historyId,
                'promotion_id' => $promotionId,
            ]);
        } catch (Throwable $e) {
            $stages[] = [
                'name' => 'certification_exception',
                'status' => 'blocked',
                'blocker' => 'certification_exception',
                'reason' => $e->getMessage(),
            ];
            $stages[] = $this->cleanup($workspace, (bool) ($options['keep_workspace'] ?? false));
            $blockers[] = 'certification_exception';

            return $this->finalize($certificationId, $createdProject, $workspace, $targetFile, $stages, $blockers, [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function preflight(): array
    {
        $requiredTables = [
            'atlas_projects',
            'atlas_programming_work_items',
            'atlas_programming_stage_receipts',
            'atlas_engineering_runs',
            'atlas_engineering_evidence',
        ];
        $missing = array_values(array_filter($requiredTables, fn (string $table): bool => ! Schema::hasTable($table)));

        return [
            'name' => 'preflight',
            'status' => $missing === [] ? 'passed' : 'blocked',
            'blocker' => $missing === [] ? null : 'required_tables_missing',
            'blockers' => array_map(fn (string $table): string => 'missing_table:'.$table, $missing),
            'required_tables' => $requiredTables,
            'external_provider_call' => false,
        ];
    }

    private function createProject(string $certificationId, string $workspace): AtlasProject
    {
        $project = new AtlasProject;
        $project->forceFill([
            'id' => (string) Str::uuid(),
            'title' => 'Atlas Code Enterprise Certification '.$certificationId,
            'description' => 'Ephemeral Obra proving Atlas Code enterprise heavy programming flow.',
            'status' => 'active',
            'domain' => $this->projectDomain(),
            'goal' => 'Certificar Atlas Code enterprise pesado end-to-end',
            'desired_outcome' => 'Atlas Code enterprise loop proves Obra, Forge, review, promotion, rollback and checkpoint',
            'priority' => 'high',
            'metadata' => [
                'origin' => 'atlas-code-enterprise-certification',
                'certification_id' => $certificationId,
                'workspace_path' => $workspace,
            ],
            'last_touched_at' => now(),
        ]);
        $project->save();

        return $project;
    }

    private function isSystemCertificationProject(AtlasProject $project): bool
    {
        return (string) data_get($project->metadata, 'origin', '') === 'atlas-code-enterprise-certification';
    }

    private function projectDomain(): string
    {
        if (! Schema::hasTable('atlas_domains') || ! Schema::hasColumn('atlas_domains', 'slug')) {
            return 'atlas';
        }

        $slugs = DB::table('atlas_domains')
            ->whereIn('slug', ['programming', 'atlas'])
            ->pluck('slug')
            ->map(fn (mixed $slug): string => (string) $slug)
            ->all();

        return in_array('programming', $slugs, true)
            ? 'programming'
            : 'atlas';
    }

    /**
     * @return array<string,mixed>
     */
    private function state(AtlasProject $project): array
    {
        $response = $this->call(AtlasCodeWorkController::class, 'state', [
            'project' => $project,
        ]);

        return (array) ($response['json'] ?? []);
    }

    private function post(string $path, array $payload): Request
    {
        return Request::create($path, 'POST', $payload);
    }

    /**
     * @param  class-string  $controller
     * @param  array<string,mixed>  $parameters
     * @return array{status_code:int,json:array<string,mixed>,ok:bool}
     */
    private function call(string $controller, string $method, array $parameters): array
    {
        /** @var JsonResponse $response */
        $response = app()->call([app($controller), $method], $parameters);
        $statusCode = $response->getStatusCode();
        $json = json_decode((string) $response->getContent(), true);

        return [
            'status_code' => $statusCode,
            'json' => is_array($json) ? $json : [],
            'ok' => $statusCode >= 200 && $statusCode < 300,
        ];
    }

    /**
     * @param  array{status_code:int,json:array<string,mixed>,ok:bool}  $response
     * @param  array<string,mixed>  $extra
     * @param  callable(array<string,mixed>):bool|null  $predicate
     * @return array<string,mixed>
     */
    private function stageFromResponse(string $name, array $response, array $extra = [], ?callable $predicate = null): array
    {
        $ok = $response['ok'] === true && ($predicate === null || $predicate($response['json']));

        return array_merge([
            'name' => $name,
            'status' => $ok ? 'passed' : 'blocked',
            'blocker' => $ok ? null : $name.'_failed',
            'status_code' => $response['status_code'],
        ], $extra);
    }

    /**
     * @return array<string,mixed>
     */
    private function cleanup(string $workspace, bool $keepWorkspace): array
    {
        if ($keepWorkspace) {
            return [
                'name' => 'workspace_cleanup',
                'status' => 'skipped_keep_workspace',
                'workspace_path_hash' => hash('sha256', $workspace),
            ];
        }

        File::deleteDirectory(dirname($workspace));

        return [
            'name' => 'workspace_cleanup',
            'status' => is_dir($workspace) ? 'blocked' : 'passed',
            'blocker' => is_dir($workspace) ? 'workspace_cleanup_failed' : null,
            'workspace_cleaned' => ! is_dir($workspace),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $stages
     * @param  array<int,string>  $blockers
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function finalize(
        string $certificationId,
        ?AtlasProject $project,
        string $workspace,
        string $targetFile,
        array $stages,
        array $blockers,
        array $evidence,
    ): array {
        $blockers = array_values(array_unique(array_filter($blockers)));
        $blockingStages = collect($stages)
            ->filter(fn (array $stage): bool => ($stage['status'] ?? null) === 'blocked')
            ->count();
        $passed = $blockers === [] && $blockingStages === 0;

        $report = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => now()->toJSON(),
            'certification_id' => $certificationId,
            'atlas_code_enterprise_status' => $passed ? 'passed' : 'blocked',
            'objective' => 'Certify Atlas Code enterprise heavy programming product loop without external provider calls.',
            'inputs' => [
                'obra_id' => $project ? (string) $project->getKey() : null,
                'requires_obra' => true,
                'workspace_path_hash' => hash('sha256', $workspace),
                'target_file' => $targetFile,
                'external_provider_call' => false,
            ],
            'prompt_to_artifact_checklist' => $this->checklist(),
            'stages' => array_values($stages),
            'stage_summary' => [
                'total' => count($stages),
                'passed' => collect($stages)->where('status', 'passed')->count(),
                'blocked' => $blockingStages,
                'skipped' => collect($stages)->filter(fn (array $stage): bool => str_starts_with((string) ($stage['status'] ?? ''), 'skipped'))->count(),
            ],
            'evidence' => $evidence,
            'remaining_blockers' => $blockers,
            'external_provider_call' => false,
            'commands' => [
                'self' => 'php artisan atlas:code:enterprise-certify --json --strict',
                'forge_live' => 'php artisan atlas:forge:live-execute --obra=<uuid> --json --strict',
                'forge_runtime' => 'php artisan atlas:forge:runtime-certify --obra=<uuid> --json --strict',
            ],
            'note' => 'Certificacao de produto Atlas Code. Nao substitui external_rivals_certification paga/autorizada.',
        ];

        if ($project !== null) {
            $this->rememberCertification($project, $report);
        }

        return $report;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function rememberCertification(AtlasProject $project, array $report): void
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $history = array_values((array) ($metadata['atlas_code_enterprise_certification_history'] ?? []));
        array_unshift($history, $report);
        $metadata['latest_atlas_code_enterprise_certification'] = $report;
        $metadata['atlas_code_enterprise_certification_history'] = array_slice($history, 0, 10);

        $project->forceFill([
            'metadata' => $metadata,
            'last_touched_at' => now(),
        ])->save();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function checklist(): array
    {
        return [
            [
                'requirement' => 'Atlas Code e Forge-only com Obra obrigatoria',
                'evidence' => ['obra_workspace_fixture.requires_obra', 'work_item_binding.binding_flow_id=programming.forge'],
            ],
            [
                'requirement' => 'Obra cria WorkItem governado e Spec/Plan/Tasks',
                'evidence' => ['work_item_binding.work_item_id', 'spec_plan_task_queue.spec_hash', 'spec_plan_task_queue.plan_hash'],
            ],
            [
                'requirement' => 'Forge Live Execution usa task contract real sem mutar workspace vivo antes do review',
                'evidence' => ['forge_live_execution_governed.execution_mode=governed_shadow_patch', 'state_history_after_run.shadow_did_not_mutate_live_workspace=true'],
            ],
            [
                'requirement' => 'Replay historico e read-only e nao chama provider externo',
                'evidence' => ['history_replay_read_only.read_only=true', 'history_replay_read_only.external_provider_call=false'],
            ],
            [
                'requirement' => 'Review humano promove patch governado com evidence',
                'evidence' => ['human_review_promotion.review_status=approved', 'human_review_promotion.promotion_status=promoted_to_workspace'],
            ],
            [
                'requirement' => 'Rollback restaura workspace e registra evidence propria',
                'evidence' => ['governed_rollback.restored_initial_hash=true', 'final_state_read_model.rollback_evidence_present=true'],
            ],
            [
                'requirement' => 'Checkpoint permite retomada auditavel da Obra',
                'evidence' => ['checkpoint_resume.checkpoint_id', 'final_state_read_model.checkpoint_status'],
            ],
        ];
    }
}
