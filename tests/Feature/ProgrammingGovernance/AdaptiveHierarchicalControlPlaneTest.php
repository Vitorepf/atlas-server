<?php

namespace Tests\Feature\ProgrammingGovernance;

use App\Models\AtlasProgrammingWorkItem;
use App\Models\AtlasProject;
use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use App\Services\Ai\Programming\AtlasForgeGovernedPromotionService;
use App\Services\Ai\Programming\Governance\ProgrammingAdaptiveHierarchicalControlPlaneService;
use App\Services\Ai\Programming\Governance\ProgrammingEvidenceLedger;
use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use App\Services\Ai\ExecutionAuthority\AwisExecutionGatePort;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringDocumentationHealthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasProgrammingGovernanceTables;
use Tests\TestCase;

class AdaptiveHierarchicalControlPlaneTest extends TestCase
{
    use CreatesAtlasProgrammingGovernanceTables;

    private string $workspace = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasProgrammingGovernanceTables();
        $this->createAtlasProjectsTable();
        $this->workspace = $this->makeProgrammingWorkspace([
            'app/Services/Ai/Programming/Governance/ProgrammingAdaptiveHierarchicalControlPlaneService.php',
            'app/Services/Ai/Programming/Governance/ProgrammingHierarchicalControlLoopService.php',
            'app/Services/Ai/Programming/Governance/Gates/ProgrammingHierarchicalControlGate.php',
        ]);
        $this->bindStubServices();
    }

    protected function tearDown(): void
    {
        $this->cleanupProgrammingWorkspaces();
        $this->dropAtlasProgrammingGovernanceTables();
        Schema::dropIfExists('atlas_projects');
        parent::tearDown();
    }

    public function test_v2_live_session_control_replans_missing_structural_spec(): void
    {
        $code = $this->intakeStructuralWorkItem('Criar controle adaptativo de sessão longa');

        $exit = Artisan::call('atlas:programming:adaptive-control-plane', [
            'work_item' => $code,
            '--level' => 'v2',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.programming.ahcl.live_session_control.v2', $payload['schema_version']);
        $this->assertSame('replan', data_get($payload, 'next_tick.action'));
        $this->assertSame('planning_control', data_get($payload, 'session_phase'));
        $this->assertTrue((bool) data_get($payload, 'next_tick.should_pause_provider'));
        $this->assertSame('expanded', data_get($payload, 'live_controls.context_budget.mode'));
    }

    public function test_all_levels_converge_after_approved_completion(): void
    {
        $code = $this->prepareReadyWorkItem();

        $exit = Artisan::call('atlas:programming:complete', [
            'work_item' => $code,
            '--review' => 'approved',
            '--summary' => 'V2/V3/V4 prontos para cert',
            '--decided-by' => 'vitor',
            '--strict' => true,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $exit = Artisan::call('atlas:programming:adaptive-control-plane', [
            'work_item' => $code,
            '--strict' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.programming.adaptive_hierarchical_control_plane.v1', $payload['schema_version']);
        $this->assertSame('submit_ready', data_get($payload, 'live_session_control_v2.status'));
        $this->assertSame('submit', data_get($payload, 'live_session_control_v2.next_tick.action'));
        $this->assertSame('release_ready', data_get($payload, 'forge_multi_agent_control_v3.status'));
        $this->assertSame('submit_now', data_get($payload, 'predictive_replay_learning_v4.recommended_path.path'));
        $this->assertSame('atlas.programming.ahcl.optimization_control_twin.v5', data_get($payload, 'optimization_control_twin_v5.schema_version'));
        $this->assertFalse((bool) data_get($payload, 'optimization_control_twin_v5.optimization_decision.auto_apply_allowed'));
        $this->assertGreaterThan(0, data_get($payload, 'predictive_replay_learning_v4.replay.event_count'));
        $this->assertNotEmpty($payload['plane_hash']);
    }

    public function test_v3_forge_multi_agent_control_blocks_overlapping_packets_without_serialization_consent(): void
    {
        $code = $this->prepareReadyWorkItem();
        $item = AtlasProgrammingWorkItem::query()->where('code', $code)->firstOrFail();
        $item->forceFill([
            'risk_level' => 'high',
            'tasks_json' => [
                [
                    'id' => 'packet-a',
                    'objective' => 'Alterar controlador',
                    'allowed_files' => ['app/Http/Controllers/AtlasCodeController.php'],
                    'validation_commands' => ['phpunit'],
                ],
                [
                    'id' => 'packet-b',
                    'objective' => 'Alterar o mesmo controlador',
                    'allowed_files' => ['app/Http/Controllers/AtlasCodeController.php'],
                    'validation_commands' => ['phpunit'],
                ],
            ],
        ])->save();

        $exit = Artisan::call('atlas:programming:adaptive-control-plane', [
            'work_item' => $code,
            '--level' => 'v3',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.programming.ahcl.forge_multi_agent_control.v3', $payload['schema_version']);
        $this->assertSame('blocked', data_get($payload, 'control_decision.status'));
        $this->assertSame('hold_for_collision_or_scheduler_repair', data_get($payload, 'control_decision.action'));
        $this->assertSame('blocked_by_ownership_conflict', data_get($payload, 'schedule.status'));
    }

    public function test_v4_predictive_replay_learning_emits_review_learning_without_autoapply(): void
    {
        $code = $this->prepareReadyWorkItem();

        $exit = Artisan::call('atlas:programming:complete', [
            'work_item' => $code,
            '--review' => 'changes_requested',
            '--summary' => 'Evidence nao prova rollback',
            '--decided-by' => 'qa',
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $exit = Artisan::call('atlas:programming:adaptive-control-plane', [
            'work_item' => $code,
            '--level' => 'v4',
            '--strict' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertSame('atlas.programming.ahcl.predictive_replay_learning.v4', $payload['schema_version']);
        $this->assertSame('repair_first', data_get($payload, 'recommended_path.path'));
        $this->assertSame('not_ready', data_get($payload, 'recommended_path.status'));
        $this->assertFalse((bool) data_get($payload, 'learning.auto_apply_allowed'));
        $this->assertGreaterThan(0, data_get($payload, 'learning.candidate_count'));
        $this->assertSame('review_feedback_learning', data_get($payload, 'learning.candidates.1.kind'));
    }

    public function test_v5_optimization_control_twin_calibrates_without_autoapplying_policy(): void
    {
        $code = $this->prepareReadyWorkItem();

        $exit = Artisan::call('atlas:programming:adaptive-control-plane', [
            'work_item' => $code,
            '--level' => 'v5',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.programming.ahcl.optimization_control_twin.v5', $payload['schema_version']);
        $this->assertSame('calibrating', $payload['status']);
        $this->assertSame('recommend_policy_adjustment', data_get($payload, 'optimization_decision.action'));
        $this->assertFalse((bool) data_get($payload, 'optimization_decision.auto_apply_allowed'));
        $this->assertGreaterThan(0, data_get($payload, 'calibration.sample_size'));
        $this->assertNotEmpty($payload['twin_hash']);
    }

    public function test_control_plane_can_persist_event_and_queue_learning_candidates(): void
    {
        $code = $this->prepareReadyWorkItem();

        $exit = Artisan::call('atlas:programming:complete', [
            'work_item' => $code,
            '--review' => 'changes_requested',
            '--summary' => 'Evidence precisa provar rollback antes de release',
            '--decided-by' => 'qa',
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $exit = Artisan::call('atlas:programming:adaptive-control-plane', [
            'work_item' => $code,
            '--persist-event' => true,
            '--emit-learning' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('persisted', data_get($payload, 'control_event_persistence.status'));
        $this->assertSame('queued_for_review', data_get($payload, 'learning_emission.status'));
        $this->assertGreaterThan(0, data_get($payload, 'learning_emission.queued_count'));
        $this->assertDatabaseCount('atlas_programming_learning_candidates', data_get($payload, 'learning_emission.queued_count'));

        $item = AtlasProgrammingWorkItem::query()->where('code', $code)->firstOrFail();
        $controlReceipt = collect((array) $item->evidence_refs_json)
            ->first(fn (array $receipt): bool => ($receipt['evidence_type'] ?? null) === 'adaptive_control_plane_event');

        $this->assertIsArray($controlReceipt);
        $this->assertSame('adaptive_hierarchical_control_plane', data_get($controlReceipt, 'execution_mode'));
        $this->assertSame(data_get($payload, 'plane_hash'), data_get($controlReceipt, 'plane_hash'));
    }

    public function test_forge_promotion_uses_adaptive_control_plane_guard_before_workspace_mutation(): void
    {
        $workspace = $this->makeProgrammingWorkspace(['target.php']);
        $before = (string) file_get_contents($workspace.'/target.php');
        $line = '// atlas adaptive guard should prevent this line';
        $after = rtrim($before, "\n")."\n".$line."\n";
        $artifactDir = storage_path('app/forge-governed-exec-artifacts/test-'.Str::lower(Str::random(8)));
        File::ensureDirectoryExists($artifactDir);
        $artifactPath = $artifactDir.'/patch.json';
        File::put($artifactPath, json_encode([
            'schema_version' => 'atlas.forge_governed_execution.patch_artifact.v1',
            'operation' => 'append_line',
            'target_file' => 'target.php',
            'expected_before_hash' => hash('sha256', $before),
            'expected_after_hash' => hash('sha256', $after),
            'line' => $line,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $project = AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => 'AAHCP Forge promotion guard',
            'description' => 'Promotion must consult AAHCP before mutation.',
            'status' => 'active',
            'domain' => 'programming',
            'goal' => 'Provar guard AAHCP na promocao Forge',
            'desired_outcome' => 'Bloquear colisao antes de alterar workspace',
            'priority' => 'high',
            'metadata' => ['workspace_path' => $workspace],
        ]);
        $workItem = AtlasProgrammingWorkItem::create([
            'id' => (string) Str::uuid(),
            'code' => 'AAHCP-PROMO-'.Str::upper(Str::random(6)),
            'intent_text' => 'Promover patch com colisao Forge',
            'intent_type' => 'forge',
            'scope_mode' => 'structural',
            'risk_level' => 'high',
            'workspace' => $workspace,
            'status' => 'ready',
            'current_stage' => 'review',
            'tasks_json' => [
                [
                    'id' => 'packet-a',
                    'objective' => 'Alterar target',
                    'allowed_files' => ['target.php'],
                    'validation_commands' => ['php -r "true;"'],
                ],
                [
                    'id' => 'packet-b',
                    'objective' => 'Alterar target tambem',
                    'allowed_files' => ['target.php'],
                    'validation_commands' => ['php -r "true;"'],
                ],
            ],
        ]);

        $service = new AtlasForgeGovernedPromotionService(
            app(ProgrammingEvidenceLedger::class),
            app(ProgrammingGovernanceService::class),
            app(ProgrammingAdaptiveHierarchicalControlPlaneService::class),
            new class implements AwisExecutionGatePort
            {
                public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
                {
                    return [
                        'schema_version' => 'atlas.workspace_intelligence.execution_gate.v1',
                        'allowed' => true,
                        'mode' => $mode,
                        'status' => 'passed',
                        'blockers' => [],
                    ];
                }
            },
        );

        $result = $service->promote(
            $project,
            ['history_id' => 'history-aahcp'],
            [
                'governed_execution' => [
                    'work_item_id' => (string) $workItem->id,
                    'promotion_artifact' => [
                        'path' => $artifactPath,
                        'sha256' => hash_file('sha256', $artifactPath),
                    ],
                ],
                'diff_scope' => [
                    'files' => [['path' => 'target.php', 'status' => 'in_scope']],
                    'completion_gate' => ['completion_claim_allowed' => true],
                ],
            ],
            ['review_id' => 'review-aahcp'],
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('adaptive_control_plane_blocked', $result['remaining_blockers']);
        $this->assertSame('atlas.forge_governed_promotion.aahcp_guard.v1', data_get($result, 'adaptive_control_plane_guard.schema_version'));
        $this->assertSame('blocked', data_get($result, 'adaptive_control_plane_guard.status'));
        $this->assertFalse((bool) data_get($result, 'adaptive_control_plane_guard.promotion_allowed'));
        $this->assertSame($before, file_get_contents($workspace.'/target.php'));
    }

    private function prepareReadyWorkItem(): string
    {
        $code = $this->intakeStructuralWorkItem('Implementar Atlas Adaptive Hierarchical Control Plane');

        $exit = Artisan::call('atlas:programming:spec', [
            'work_item' => $code,
            '--objective' => 'Criar control plane adaptativo com AHCL v2, v3 e v4',
            '--context' => 'Atlas Code e Forge precisam de controle vivo, multiagente e preditivo',
            '--expected-behavior' => 'Control plane emite live tick, forge schedule e replay learning',
            '--likely-file' => ['app/Services/Ai/Programming/Governance/ProgrammingAdaptiveHierarchicalControlPlaneService.php'],
            '--risk' => ['Gate adaptativo pode virar runtime paralelo se nao usar Programming Governance'],
            '--test' => ['php artisan test tests/Feature/ProgrammingGovernance/AdaptiveHierarchicalControlPlaneTest.php'],
            '--evidence-required' => ['adaptive_control_plane_json', 'phpunit_green'],
            '--rollback' => 'Remover command/service adaptativo e manter AHCL v1',
            '--completion-criterion' => ['v2 live control', 'v3 forge control', 'v4 predictive replay learning'],
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $exit = Artisan::call('atlas:programming:plan', [
            'work_item' => $code,
            '--allowed-files' => ['app/Services/Ai/Programming/Governance/ProgrammingAdaptiveHierarchicalControlPlaneService.php'],
            '--validation-command' => ['php artisan test tests/Feature/ProgrammingGovernance/AdaptiveHierarchicalControlPlaneTest.php'],
            '--acceptance' => ['v2/v3/v4 payloads generated'],
            '--cartography-required' => true,
            '--rollback' => 'git revert',
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $exit = Artisan::call('atlas:programming:receipt', [
            'work_item' => $code,
            '--command' => 'php artisan test tests/Feature/ProgrammingGovernance/AdaptiveHierarchicalControlPlaneTest.php',
            '--output' => 'PASS  Tests\\Feature\\ProgrammingGovernance\\AdaptiveHierarchicalControlPlaneTest',
            '--file' => ['app/Services/Ai/Programming/Governance/ProgrammingAdaptiveHierarchicalControlPlaneService.php'],
            '--test' => ['Tests\\Feature\\ProgrammingGovernance\\AdaptiveHierarchicalControlPlaneTest'],
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $exit = Artisan::call('atlas:programming:verify', [
            'work_item' => $code,
            '--strict' => true,
            '--json' => true,
        ]);
        $this->assertSame(1, $exit, 'AHCL blocks until review exists.');

        return $code;
    }

    private function intakeStructuralWorkItem(string $intent): string
    {
        $exit = Artisan::call('atlas:programming:intake', [
            'intent' => $intent,
            '--owner' => 'vitor',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $payload = json_decode(Artisan::output(), true);

        return (string) $payload['code'];
    }

    private function bindStubServices(): void
    {
        $this->app->bind(AtlasFeaturePlacementService::class, function (): AtlasFeaturePlacementService {
            return new class extends AtlasFeaturePlacementService
            {
                public function __construct() {}

                public function place(string $intent, array $hints = []): array
                {
                    return ['status' => 'ok', 'placement' => ['layer' => 'domain', 'domain' => 'programming'], 'owner_docs' => []];
                }
            };
        });

        $this->app->bind(EngineeringCodeIntelligenceService::class, function (): EngineeringCodeIntelligenceService {
            return new class extends EngineeringCodeIntelligenceService
            {
                public function __construct() {}

                public function summary(array $options = []): array
                {
                    return [
                        'status' => 'ready',
                        'table_exists' => true,
                        'module_count' => 23,
                        'symbol_count' => 39419,
                        'doc_link_count' => 61791,
                    ];
                }
            };
        });

        $this->app->bind(EngineeringDocumentationHealthService::class, function (): EngineeringDocumentationHealthService {
            return new class extends EngineeringDocumentationHealthService
            {
                public function __construct() {}

                public function report(): array
                {
                    return ['status' => 'ok', 'required_missing' => [], 'oversized' => []];
                }
            };
        });
    }

    private function createAtlasProjectsTable(): void
    {
        Schema::dropIfExists('atlas_projects');

        Schema::create('atlas_projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->string('domain')->default('atlas');
            $table->uuid('source_capture_id')->nullable();
            $table->text('goal')->nullable();
            $table->text('next_action')->nullable();
            $table->string('project_type')->nullable();
            $table->text('desired_outcome')->nullable();
            $table->text('minimum_viable_outcome')->nullable();
            $table->text('definition_of_done')->nullable();
            $table->text('why_now')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->string('deadline_kind')->nullable();
            $table->string('priority')->default('medium');
            $table->string('energy_profile')->nullable();
            $table->text('avoidance_reason')->nullable();
            $table->uuid('active_next_task_id')->nullable();
            $table->uuid('current_step_id')->nullable();
            $table->timestamp('last_touched_at')->nullable();
            $table->timestamp('next_review_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('paused_until')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
