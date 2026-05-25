<?php

namespace Tests\Feature\Ai\Programming\Forge;

use App\Models\AiForgeIntake;
use App\Models\AiForgeMilestone;
use App\Models\AiForgeWorkPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationPacket;
use App\Services\Ai\Programming\Forge\ForgeIntakeCanon;
use App\Services\Ai\Programming\Forge\ForgeIntakeException;
use App\Services\Ai\Programming\Forge\ForgeIntakeService;
use Tests\Concerns\CreatesForgeIntakeTables;
use Tests\TestCase;

class ForgeIntakeServiceTest extends TestCase
{
    use CreatesForgeIntakeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createForgeIntakeTables();
    }

    protected function tearDown(): void
    {
        $this->dropForgeIntakeTables();
        parent::tearDown();
    }

    public function test_direct_intake_creates_obra_with_work_packets_and_canonical_milestones(): void
    {
        $service = app(ForgeIntakeService::class);

        $intake = $service->intakeFromPrompt(
            'Implementar refactor do provider router em multi-modulo com sdd; depois migrar billing engine; por fim adicionar suite de tests de regressao.',
            [
                'workspace_slug' => 'atlas',
                'workspace_execution_gate' => $this->allowedWorkspaceExecutionGate(),
                'risk_band' => ForgeIntakeCanon::RISK_BAND_HIGH,
                'recommended_forge_mode' => EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE,
            ],
        );

        $this->assertSame(ForgeIntakeCanon::ORIGIN_DIRECT, $intake->origin);
        $this->assertSame(ForgeIntakeCanon::STATUS_READY, $intake->status);
        $this->assertSame(EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE, $intake->recommended_forge_mode);
        $this->assertSame(ForgeIntakeCanon::RISK_BAND_HIGH, $intake->risk_band);
        $this->assertSame('atlas', $intake->workspace_slug);
        $this->assertSame(64, strlen($intake->intake_hash));
        $this->assertNull($intake->blocker_reason);
        $this->assertSame('atlas.context_intelligence.operations_runtime.v1', data_get($intake->context_operations, 'schema_version'));
        $this->assertSame('atlas_forge', data_get($intake->context_operations, 'flow_binding.flow_id'));
        $this->assertTrue((bool) data_get($intake->context_operations, 'integration_policy.verified_compaction_required'));
        $this->assertSame('forge_intake', data_get($intake->context_operations, 'handoff_packet.role'));
        $this->assertSame(data_get($intake->context_operations, 'operations_runtime_hash'), $intake->context_operations_hash);
        $this->assertSame('atlas.persistent_context.runtime.v1', data_get($intake->persistent_context, 'schema_version'));
        $this->assertSame('atlas_forge', data_get($intake->persistent_context, 'scope.flow_id'));
        $this->assertIsString($intake->persistent_context_hash);
        $this->assertSame(data_get($intake->persistent_context, 'persistent_context_hash'), $intake->persistent_context_hash);

        // 5 canonical milestones present, in canonical order.
        $milestones = $intake->milestones()->orderBy('position')->get();
        $this->assertCount(5, $milestones, '5 canonical Forge milestones must be planned at intake time');
        $this->assertSame(ForgeIntakeCanon::CANONICAL_MILESTONES, $milestones->pluck('milestone_id')->all());
        foreach ($milestones as $ms) {
            $this->assertNotEmpty($ms->expected_artifacts, "milestone {$ms->milestone_id} must declare expected_artifacts");
            $this->assertNotEmpty($ms->required_gates, "milestone {$ms->milestone_id} must declare required_gates");
            $this->assertSame(64, strlen($ms->milestone_hash));
            $this->assertSame('pending', $ms->status);
        }

        // Heuristic packet generation produced >= 2 packets from the 3-clause prompt.
        $packets = $intake->workPackets()->orderBy('packet_position')->get();
        $this->assertGreaterThanOrEqual(2, $packets->count(), 'heuristic decomposition should produce multiple packets from a 3-clause prompt');
        foreach ($packets as $packet) {
            $this->assertNotEmpty($packet->acceptance_criteria);
            $this->assertNotEmpty($packet->required_evidence);
            $this->assertSame(ForgeIntakeCanon::PACKET_STATUS_PROPOSED, $packet->status);
            $this->assertSame(64, strlen($packet->packet_hash));
            $this->assertNotEmpty($packet->packet_id);
        }

        // DoD + required_evidence carry the canonical defaults.
        $this->assertSame(ForgeIntakeCanon::defaultDefinitionOfDone(), $intake->definition_of_done);
        $this->assertSame(ForgeIntakeCanon::defaultRequiredEvidence(), $intake->required_evidence);
    }

    public function test_escalation_packet_intake_consumes_dev_to_forge_packet_and_creates_obra(): void
    {
        $packet = $this->makeEscalationPacket(
            originalUserIntent: 'Reescrever provider router com sdd, multi-agent e fallback governado',
            suggestedWorkPackets: [
                ['id' => 'wp-001', 'title' => 'Design provider router architecture', 'objective' => 'Define new architecture', 'acceptance_criteria' => ['ADR merged']],
                ['id' => 'wp-002', 'title' => 'Implement provider router core', 'objective' => 'Implement core logic', 'expected_files' => ['app/Services/Ai/Provider/Router.php']],
                ['id' => 'wp-003', 'title' => 'Add multi-provider tests', 'objective' => 'Coverage >= 90%', 'suggested_tests' => ['ProviderRouterTest::test_multi_provider_fallback']],
            ],
            recommendedForgeMode: EscalationPacket::RECOMMENDED_FORGE_MODE_OBRA_INTAKE,
            definitionOfDone: ['ADR merged', 'tests green', 'docs updated'],
            requiredEvidence: ['plan', 'work_packet_receipts', 'verification_receipt', 'evidence_pack'],
        );

        $intake = app(ForgeIntakeService::class)->intakeFromEscalationPacket($packet, [
            'workspace_slug' => 'atlas',
            'workspace_execution_gate' => $this->allowedWorkspaceExecutionGate(),
            'mission_id' => $this->fakeUuid(),
        ]);

        $this->assertSame(ForgeIntakeCanon::ORIGIN_ESCALATION_PACKET, $intake->origin);
        $this->assertSame(ForgeIntakeCanon::STATUS_READY, $intake->status);
        $this->assertSame(EscalationPacket::RECOMMENDED_FORGE_MODE_OBRA_INTAKE, $intake->recommended_forge_mode);
        $this->assertSame($packet->packetId, $intake->escalation_packet_id);
        $this->assertSame($packet->packetHash, $intake->escalation_packet_hash);
        $this->assertSame('dev_to_forge_handoff', $intake->actor_type);
        $this->assertSame(['ADR merged', 'tests green', 'docs updated'], $intake->definition_of_done);

        // Packets came verbatim from the escalation packet (3 suggestions).
        $packets = $intake->workPackets()->orderBy('packet_position')->get();
        $this->assertCount(3, $packets);
        $this->assertSame(['wp-001', 'wp-002', 'wp-003'], $packets->pluck('packet_id')->all());
        $this->assertSame('Implement provider router core', $packets->where('packet_id', 'wp-002')->first()->title);
        $this->assertContains('app/Services/Ai/Provider/Router.php', (array) $packets->where('packet_id', 'wp-002')->first()->expected_files);
        $this->assertContains('ProviderRouterTest::test_multi_provider_fallback', (array) $packets->where('packet_id', 'wp-003')->first()->suggested_tests);

        // Milestones still 5 + canonical even in escalation path.
        $this->assertSame(
            ForgeIntakeCanon::CANONICAL_MILESTONES,
            $intake->milestones()->orderBy('position')->pluck('milestone_id')->all(),
        );
    }

    public function test_forge_intake_persists_awis_execution_gate_for_workspace_bound_obra(): void
    {
        $intake = app(ForgeIntakeService::class)->intakeFromPrompt(
            'Criar ecommerce com milestones, testes e plano de rollback',
            [
                'workspace_slug' => 'atlas',
                'workspace_execution_gate' => $this->allowedWorkspaceExecutionGate(),
                'risk_band' => ForgeIntakeCanon::RISK_BAND_HIGH,
                'expected_files' => ['app/Services/Ai/Programming/Forge/ForgeIntakeService.php'],
            ],
        );

        $this->assertIsArray($intake->workspace_execution_gate);
        $this->assertSame('atlas.workspace_intelligence.execution_gate.v1', data_get($intake->workspace_execution_gate, 'schema_version'));
        $this->assertSame('forge', data_get($intake->workspace_execution_gate, 'mode'));
        $this->assertTrue((bool) data_get($intake->workspace_execution_gate, 'allowed'));
        $this->assertSame('atlas', data_get($intake->workspace_execution_gate, 'workspace_id'));
        $this->assertTrue((bool) data_get($intake->workspace_execution_gate, 'required_contracts.awco_execution_readiness'));
        $inventoryHash = data_get($intake->workspace_execution_gate, 'execution_context.context_loading_plan.repository_inventory_hash');
        $workingSetHash = data_get($intake->workspace_execution_gate, 'execution_context.context_loading_plan.working_set_hash');
        $contextDeltaPlanHash = data_get($intake->workspace_execution_gate, 'execution_context.context_loading_plan.context_delta_plan_hash');
        $outcomeCommandMemoryHash = data_get($intake->workspace_execution_gate, 'execution_context.context_loading_plan.outcome_command_memory_hash');
        $performanceHistogramHash = data_get($intake->workspace_execution_gate, 'execution_context.context_loading_plan.command_performance_histogram_hash');
        $areaPerformanceIndexHash = data_get($intake->workspace_execution_gate, 'execution_context.context_loading_plan.area_performance_index_hash');
        $stackPerformanceIndexHash = data_get($intake->workspace_execution_gate, 'execution_context.context_loading_plan.stack_performance_index_hash');
        $learningSnapshotHash = data_get($intake->workspace_execution_gate, 'execution_context.context_loading_plan.learning_snapshot_hash');
        $executionOptimizationPolicyHash = data_get($intake->workspace_execution_gate, 'execution_context.context_loading_plan.execution_optimization_policy_hash');
        $executionPolicyEffectivenessIndexHash = data_get($intake->workspace_execution_gate, 'execution_context.context_loading_plan.execution_policy_effectiveness_index_hash');
        $executionRouteEffectivenessIndexHash = data_get($intake->workspace_execution_gate, 'execution_context.context_loading_plan.execution_route_effectiveness_index_hash');
        $this->assertSame(64, strlen((string) $inventoryHash));
        $this->assertSame(64, strlen((string) $workingSetHash));
        $this->assertSame(64, strlen((string) $contextDeltaPlanHash));
        $this->assertSame(64, strlen((string) $outcomeCommandMemoryHash));
        $this->assertSame(64, strlen((string) $performanceHistogramHash));
        $this->assertSame(64, strlen((string) $areaPerformanceIndexHash));
        $this->assertSame(64, strlen((string) $stackPerformanceIndexHash));
        $this->assertSame(64, strlen((string) $learningSnapshotHash));
        $this->assertSame(64, strlen((string) $executionOptimizationPolicyHash));
        $this->assertSame(64, strlen((string) $executionPolicyEffectivenessIndexHash));
        $this->assertSame(64, strlen((string) $executionRouteEffectivenessIndexHash));
        $this->assertContains('awis_cache:repository_inventory:'.$inventoryHash, $intake->context_refs);
        $this->assertContains('awis_cache:workspace_working_set:'.$workingSetHash, $intake->context_refs);
        $this->assertContains('awis_cache:context_delta_plan:'.$contextDeltaPlanHash, $intake->context_refs);
        $this->assertContains('awis_cache:outcome_command_memory:'.$outcomeCommandMemoryHash, $intake->context_refs);
        $this->assertContains('awis_cache:command_performance_histogram:'.$performanceHistogramHash, $intake->context_refs);
        $this->assertContains('awis_cache:area_performance_index:'.$areaPerformanceIndexHash, $intake->context_refs);
        $this->assertContains('awis_cache:stack_performance_index:'.$stackPerformanceIndexHash, $intake->context_refs);
        $this->assertContains('awis_cache:workspace_learning_snapshot:'.$learningSnapshotHash, $intake->context_refs);
        $this->assertContains('awis_cache:execution_optimization_policy:'.$executionOptimizationPolicyHash, $intake->context_refs);
        $this->assertContains('awis_cache:execution_policy_effectiveness:'.$executionPolicyEffectivenessIndexHash, $intake->context_refs);
        $this->assertContains('awis_cache:execution_route_effectiveness:'.$executionRouteEffectivenessIndexHash, $intake->context_refs);
        $this->assertContains('awis_validation_tier:standard', $intake->context_refs);
        $this->assertContains('awis_validation_route:area:'.hash('sha256', 'app/Services/Ai/Programming/Forge'), $intake->context_refs);
        $this->assertSame(
            'standard',
            data_get($intake->workspace_execution_gate, 'execution_context.context_loading_plan.execution_optimization_policy.validation_tier_routing.default_tier'),
        );
        $this->assertContains(
            'awis_execution_route_command:'.hash('sha256', 'php artisan test tests/Feature/Ai/Programming/Forge/ScopedForgeRouteTest.php').':area:'.hash('sha256', 'app/Services/Ai/Programming/Forge'),
            $intake->context_refs,
        );
        $this->assertContains('awis_repo:atlas-server', $intake->context_refs);
        $this->assertContains('awis_manifest:atlas-server:composer.json', $intake->context_refs);
        $packet = $intake->workPackets()->firstOrFail();
        $this->assertSame('atlas.awis.validation_depth_decision.v1', data_get($intake->workspace_execution_gate, 'execution_context.validation_depth_decision.schema_version'));
        $this->assertSame('standard', data_get($intake->workspace_execution_gate, 'execution_context.validation_depth_decision.tier'));
        $this->assertSame('risk_band_upgraded_instant_to_standard', data_get($intake->workspace_execution_gate, 'execution_context.validation_depth_decision.reason'));
        $this->assertSame('area', data_get($intake->workspace_execution_gate, 'execution_context.validation_depth_decision.route_kind'));
        $this->assertFalse(data_get($intake->workspace_execution_gate, 'execution_context.validation_depth_decision.raw_logs_returned'));
        $this->assertSame('php artisan test tests/Feature/Ai/Programming/Forge/ScopedForgeRouteTest.php', $packet->suggested_tests[0]);
        $this->assertContains('php artisan test tests/Feature/Ai/Programming/Forge/ScopedForgeRouteTest.php', $packet->suggested_tests);
        $this->assertContains('php artisan test tests/Feature/Ai/Programming/Forge/ForgeIntakeServiceTest.php', $packet->suggested_tests);
        $this->assertContains('php artisan test tests/Feature/Ai/Programming/Forge/OutcomeRankedTest.php', $packet->suggested_tests);
    }

    public function test_forge_intake_blocks_without_awis_workspace(): void
    {
        $intake = app(ForgeIntakeService::class)->intakeFromPrompt(
            'Criar ecommerce com milestones, testes e plano de rollback',
            ['risk_band' => ForgeIntakeCanon::RISK_BAND_HIGH],
        );

        $this->assertSame(ForgeIntakeCanon::STATUS_BLOCKED, $intake->status);
        $this->assertSame('awis_workspace_required_for_forge_intake', $intake->blocker_reason);
        $this->assertNull($intake->workspace_execution_gate);
        $this->assertSame(0, $intake->workPackets()->count(), 'AWIS-blocked intake must not produce executable work packets.');
        $this->assertCount(5, $intake->milestones()->get(), 'Blocked intake still keeps milestone audit visibility.');
    }

    public function test_forge_intake_falls_back_to_stack_route_when_area_route_does_not_match(): void
    {
        $intake = app(ForgeIntakeService::class)->intakeFromPrompt(
            'Criar service novo com testes de integração e plano de rollback',
            [
                'workspace_slug' => 'atlas',
                'workspace_execution_gate' => $this->allowedWorkspaceExecutionGate(),
                'risk_band' => ForgeIntakeCanon::RISK_BAND_HIGH,
                'expected_files' => ['app/Services/Ai/Programming/NewForgeWorker.php'],
            ],
        );

        $packet = $intake->workPackets()->firstOrFail();
        $this->assertContains(
            'awis_execution_route_command:'.hash('sha256', 'php artisan test tests/Feature/Ai/Programming/Forge/StackLaravelRouteTest.php').':stack:'.hash('sha256', 'laravel'),
            $intake->context_refs,
        );
        $this->assertContains('awis_validation_route:stack:'.hash('sha256', 'laravel'), $intake->context_refs);
        $this->assertSame('php artisan test tests/Feature/Ai/Programming/Forge/StackLaravelRouteTest.php', $packet->suggested_tests[0]);
        $this->assertContains('php artisan test tests/Feature/Ai/Programming/Forge/StackLaravelRouteTest.php', $packet->suggested_tests);
        $this->assertContains('php artisan test tests/Feature/Ai/Programming/Forge/ForgeIntakeServiceTest.php', $packet->suggested_tests);
    }

    public function test_direct_intake_persists_canonical_rich_input_payload_without_raw_text(): void
    {
        $intake = app(ForgeIntakeService::class)->intakeFromPrompt(
            'Implementar Obra longa usando briefing multimodal com anexos',
            [
                'context_refs' => ['manual:operator-note'],
                'rich_input_payload' => [
                    'schema_version' => 'atlas.rich_input.payload.v1',
                    'uploaded_image_ids' => ['img_forge_1'],
                    'uploaded_document_ids' => ['doc_forge_1'],
                    'url_attachments' => [[
                        'url' => 'https://youtu.be/dQw4w9WgXcQ',
                        'kind' => 'youtube',
                        'ref_id' => 'dQw4w9WgXcQ',
                    ]],
                    'text_blocks' => [[
                        'file_name' => 'brief.md',
                        'mime_type' => 'text/markdown',
                        'language' => 'markdown',
                        'content' => 'conteudo bruto que nao deve persistir no intake Forge',
                    ]],
                    'source_manifest' => [[
                        'id' => 'url-1',
                        'kind' => 'url',
                        'file_name' => 'https://youtu.be/dQw4w9WgXcQ',
                        'mime_type' => 'text/uri-list',
                        'source' => 'paste',
                    ]],
                ],
            ],
        );

        $this->assertSame('atlas.rich_input.payload.v1', $intake->rich_input_schema_version);
        $this->assertSame('atlas.rich_input.payload.v1', $intake->rich_input_payload['schema_version']);
        $this->assertSame(['img_forge_1'], $intake->rich_input_payload['uploaded_image_ids']);
        $this->assertSame(['doc_forge_1'], $intake->rich_input_payload['uploaded_document_ids']);
        $this->assertSame('youtube', $intake->rich_input_payload['url_attachments'][0]['kind']);
        $this->assertArrayHasKey('content_hash', $intake->rich_input_payload['text_blocks'][0]);
        $this->assertArrayNotHasKey('content', $intake->rich_input_payload['text_blocks'][0]);
        $this->assertArrayHasKey('manifest_hash', $intake->rich_input_payload['source_manifest'][0]);
        $this->assertContains('manual:operator-note', $intake->context_refs);
        $this->assertContains('image_asset:img_forge_1', $intake->context_refs);
        $this->assertContains('document_asset:doc_forge_1', $intake->context_refs);
        $this->assertTrue(
            collect($intake->context_refs)->contains(fn (string $ref): bool => str_starts_with($ref, 'source_manifest:url:')),
            'Forge intake must expose source_manifest provenance for audit.',
        );
    }

    public function test_dod_and_evidence_requirements_are_attached_at_intake_time(): void
    {
        $intake = app(ForgeIntakeService::class)->intakeFromPrompt(
            'Implementar nova pipeline de deploy com governance multi-stage',
        );

        $this->assertNotEmpty($intake->definition_of_done);
        $this->assertNotEmpty($intake->required_evidence);
        $this->assertContains('certification_passed', $intake->definition_of_done);
        $this->assertContains('certification', $intake->required_evidence);
    }

    public function test_canonical_milestones_present_in_canonical_order(): void
    {
        $intake = app(ForgeIntakeService::class)->intakeFromPrompt(
            'Refatorar sistema de billing com migration multi-tenant',
        );

        $ids = $intake->milestones()->orderBy('position')->pluck('milestone_id')->all();
        $this->assertSame(
            [
                'design_context',
                'implementation',
                'verification',
                'docs',
                'certification',
            ],
            $ids,
        );
    }

    public function test_insufficient_prompt_creates_blocked_intake_with_explicit_reason_and_no_packets(): void
    {
        // No action verb, no object marker, > 12 chars (passes length but not signal).
        $intake = app(ForgeIntakeService::class)->intakeFromPrompt(
            'aleatorio palavra random nonsense',
            ['workspace_slug' => 'atlas', 'workspace_execution_gate' => $this->allowedWorkspaceExecutionGate()],
        );

        $this->assertSame(ForgeIntakeCanon::STATUS_BLOCKED, $intake->status);
        $this->assertNotNull($intake->blocker_reason);
        $this->assertStringContainsString('insufficient_prompt_signal', $intake->blocker_reason);
        // Blocked intake -> 0 packets (executor must not pick this up).
        $this->assertSame(0, $intake->workPackets()->count(), 'blocked intake must NOT produce work packets');
        // BUT milestones are still planned for audit visibility.
        $this->assertCount(5, $intake->milestones()->get());
    }

    public function test_too_short_prompt_creates_blocked_intake(): void
    {
        $intake = app(ForgeIntakeService::class)->intakeFromPrompt('curto', [
            'workspace_slug' => 'atlas',
            'workspace_execution_gate' => $this->allowedWorkspaceExecutionGate(),
        ]);

        $this->assertSame(ForgeIntakeCanon::STATUS_BLOCKED, $intake->status);
        $this->assertStringContainsString('prompt_too_short', (string) $intake->blocker_reason);
    }

    public function test_empty_prompt_throws_forge_intake_exception(): void
    {
        $this->expectException(ForgeIntakeException::class);
        app(ForgeIntakeService::class)->intakeFromPrompt('   ');
    }

    public function test_invalid_recommended_forge_mode_throws(): void
    {
        $this->expectException(ForgeIntakeException::class);
        app(ForgeIntakeService::class)->intakeFromPrompt(
            'Implementar feature',
            ['recommended_forge_mode' => 'ultra_mode'],
        );
    }

    public function test_invalid_risk_band_throws(): void
    {
        $this->expectException(ForgeIntakeException::class);
        app(ForgeIntakeService::class)->intakeFromPrompt(
            'Implementar feature',
            ['risk_band' => 'apocalyptic'],
        );
    }

    public function test_intake_hash_is_deterministic_for_equivalent_inputs(): void
    {
        $service = app(ForgeIntakeService::class);

        $intake1 = $service->intakeFromPrompt(
            'Implementar nova feature de auth com testes',
            ['obra_title' => 'auth-feature', 'workspace_slug' => 'atlas', 'workspace_execution_gate' => $this->allowedWorkspaceExecutionGate()],
        );
        $intake2 = $service->intakeFromPrompt(
            'Implementar nova feature de auth com testes',
            ['obra_title' => 'auth-feature', 'workspace_slug' => 'atlas', 'workspace_execution_gate' => $this->allowedWorkspaceExecutionGate()],
        );

        // Hashes differ because uuid is part of the payload (intake_hash is
        // identity-bearing, not content-only). Asserting they differ proves
        // it isn't a constant.
        $this->assertNotSame($intake1->intake_hash, $intake2->intake_hash);
        // BUT recomputing the hash from the persisted shape reproduces the
        // stored value — proving determinism vs content drift.
        $recomputed = MissionCanonicalHash::sha256($this->intakeHashPayload($intake1));
        $this->assertSame($intake1->intake_hash, $recomputed);
    }

    public function test_intake_record_serializes_to_stable_json(): void
    {
        $intake = app(ForgeIntakeService::class)->intakeFromPrompt(
            'Implementar refactor multi-modulo do provider router',
            ['workspace_slug' => 'atlas', 'workspace_execution_gate' => $this->allowedWorkspaceExecutionGate()],
        );
        /** @var AiForgeIntake $intake */
        $packets = $intake->workPackets()->orderBy('packet_position')->get();
        $milestones = $intake->milestones()->orderBy('position')->get();

        $envelope = [
            'schema' => $intake->schema_version,
            'intake_id' => $intake->id,
            'origin' => $intake->origin,
            'status' => $intake->status,
            'intake_hash' => $intake->intake_hash,
            'packets' => $packets->map(fn (AiForgeWorkPacket $p): array => $p->toCanonicalArray())->all(),
            'milestones' => $milestones->map(static fn (AiForgeMilestone $m): array => [
                'milestone_id' => $m->milestone_id,
                'position' => (int) $m->position,
                'status' => $m->status,
                'milestone_hash' => $m->milestone_hash,
            ])->all(),
        ];

        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json, 'intake envelope must be JSON encodable');
        $decoded = json_decode((string) $json, true);
        $this->assertSame('atlas.forge.intake.v1', $decoded['schema']);
        $this->assertCount(5, $decoded['milestones']);
        $this->assertGreaterThanOrEqual(1, count($decoded['packets']));
    }

    public function test_forge_intake_namespace_does_not_import_atlas_dev_runtime(): void
    {
        // Forge MUST be independent from Atlas Dev runtime: it can consume
        // the canonical DTO (EscalationPacket) from AtlasDev/Schemas but it
        // must NEVER reach into Dev pipeline / runtime / orchestrator code.
        $files = glob(app_path('Services/Ai/Programming/Forge/*.php'));
        $this->assertNotEmpty($files, 'Forge namespace must exist with PHP files');

        $forbidden = [
            'AtlasDev\\Pipeline\\',
            'AtlasDev\\Runtime\\',
            'AtlasDev\\Repair\\',
            'AtlasDev\\Gate\\',
            'AtlasDev\\Escalation\\',
            'AtlasDev\\Discovery\\',
            'AtlasDev\\SeniorLoop\\',
            'AtlasDev\\Surface\\',
            'AtlasDev\\Persistence\\',
            'AtlasDev\\Provider\\',
        ];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    sprintf('Forge namespace must not depend on Dev runtime — found "%s" in %s', $needle, basename($file)),
                );
            }
        }
    }

    /**
     * @param  list<array<string,mixed>>  $suggestedWorkPackets
     * @param  list<string>  $definitionOfDone
     * @param  list<string>  $requiredEvidence
     */
    private function makeEscalationPacket(
        string $originalUserIntent,
        array $suggestedWorkPackets,
        string $recommendedForgeMode = EscalationPacket::RECOMMENDED_FORGE_MODE_OBRA_INTAKE,
        array $definitionOfDone = ['certification_passed'],
        array $requiredEvidence = ['plan', 'evidence_pack'],
    ): EscalationPacket {
        return EscalationPacket::issue(
            packetId: 'ep-'.bin2hex(random_bytes(8)),
            originalUserIntent: $originalUserIntent,
            normalizedIntent: strtolower($originalUserIntent),
            promotionReason: 'scope expanded mid-run to obra multi-module',
            promotionTriggers: [
                EscalationPacket::TRIGGER_SCOPE_TOO_LARGE,
                EscalationPacket::TRIGGER_SDD_REQUIRED,
            ],
            scopeAssessment: 'scope spans 6 modules and a public contract',
            riskAssessment: 'high risk: provider routing surface',
            ambiguityAssessment: 'low ambiguity after Dev triage',
            currentDevFindings: ['provider router has 3 hot spots'],
            completedDevActions: ['drafted initial decomposition'],
            incompleteDevActions: ['ADR pending', 'tests pending'],
            recommendedForgeMode: $recommendedForgeMode,
            suggestedWorkPackets: $suggestedWorkPackets,
            definitionOfDone: $definitionOfDone,
            requiredEvidence: $requiredEvidence,
            evidenceRefs: EscalationPacket::emptyEvidenceRefs(),
            contextRefs: ['app/Services/Ai/Provider/Router.php'],
            contextPackHash: null,
            constraints: ['no breaking changes to public DTOs'],
            nonGoals: ['rewrite billing engine'],
            createdAt: now()->toISOString(),
        );
    }

    private function fakeUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xFFFF), random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0x0FFF) | 0x4000,
            random_int(0, 0x3FFF) | 0x8000,
            random_int(0, 0xFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFFF),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function allowedWorkspaceExecutionGate(): array
    {
        return [
            'schema_version' => 'atlas.workspace_intelligence.execution_gate.v1',
            'mode' => 'forge',
            'workspace_id' => 'atlas',
            'allowed' => true,
            'status' => 'passed',
            'blockers' => [],
            'required_contracts' => [
                'awco_execution_readiness' => true,
            ],
            'execution_context' => [
                'schema_version' => 'atlas.workspace_intelligence.execution_context.v1',
                'provider_safe' => true,
                'focused_repositories' => [[
                    'repo_key' => 'atlas-server',
                    'score' => 8,
                    'reasons' => ['test_fixture'],
                    'stack' => ['laravel', 'php'],
                ]],
                'execution_priority' => [[
                    'command' => 'php artisan test tests/Feature/Ai/Programming/Forge/ForgeIntakeServiceTest.php',
                    'why' => 'focused_forge_intake_regression',
                    'requires_operator_approval' => true,
                ]],
                'context_loading_plan' => [
                    'schema_version' => 'atlas.awis.context_loading_plan.v1',
                    'repository_inventory_hash' => str_repeat('b', 64),
                    'working_set_hash' => str_repeat('6', 64),
                    'workspace_working_set' => [
                        'schema_version' => 'atlas.awis.workspace_working_set.v1',
                        'working_set_hash' => str_repeat('6', 64),
                        'mode' => 'provider_safe_hot_context_set',
                        'hot_areas' => ['app/Services/Ai/Programming/Forge'],
                        'hot_commands' => ['php artisan test tests/Feature/Ai/Programming/Forge/ScopedForgeRouteTest.php'],
                        'source_policy' => [
                            'raw_file_content_returned' => false,
                            'raw_diff_returned' => false,
                        ],
                    ],
                    'context_delta_plan_hash' => str_repeat('5', 64),
                    'context_delta_plan' => [
                        'schema_version' => 'atlas.awis.context_delta_plan.v1',
                        'delta_plan_hash' => str_repeat('5', 64),
                        'mode' => 'hash_based_incremental_context_resume',
                        'decision' => 'partial_refresh_hot_overlap',
                        'reuse_refs' => ['awis_cache:workspace_working_set:'.str_repeat('6', 64)],
                        'refresh_refs' => ['workspace_change_hash:'.str_repeat('4', 64)],
                        'source_policy' => [
                            'raw_file_content_returned' => false,
                            'raw_diff_returned' => false,
                        ],
                    ],
                    'stack_tags' => ['laravel', 'php'],
                    'focused_manifest_refs' => [[
                        'repo_key' => 'atlas-server',
                        'manifest_files' => ['composer.json', 'artisan'],
                        'stack' => ['laravel', 'php'],
                        'script_names' => ['composer:test'],
                    ]],
                    'command_hints' => ['php artisan test tests/Feature/Ai/Programming/Forge/ForgeIntakeServiceTest.php'],
                    'outcome_ranked_commands' => ['php artisan test tests/Feature/Ai/Programming/Forge/OutcomeRankedTest.php'],
                    'avoid_commands' => ['php artisan test tests/Feature/Ai/Programming/Forge/FlakyTest.php'],
                    'outcome_command_memory_hash' => str_repeat('c', 64),
                    'command_performance_histogram_hash' => str_repeat('d', 64),
                    'area_performance_index_hash' => str_repeat('e', 64),
                    'stack_performance_index_hash' => str_repeat('f', 64),
                    'learning_snapshot_hash' => str_repeat('a', 64),
                    'execution_optimization_policy_hash' => str_repeat('9', 64),
                    'execution_policy_effectiveness_index_hash' => str_repeat('8', 64),
                    'execution_route_effectiveness_index_hash' => str_repeat('7', 64),
                    'cache_keys' => [
                        'workspace_working_set_hash' => str_repeat('6', 64),
                        'context_delta_plan_hash' => str_repeat('5', 64),
                        'workspace_learning_snapshot_hash' => str_repeat('a', 64),
                        'execution_optimization_policy_hash' => str_repeat('9', 64),
                        'execution_policy_effectiveness_index_hash' => str_repeat('8', 64),
                        'execution_route_effectiveness_index_hash' => str_repeat('7', 64),
                    ],
                    'execution_optimization_policy' => [
                        'schema_version' => 'atlas.awis.execution_optimization_policy.v1',
                        'preferred_commands' => ['php artisan test tests/Feature/Ai/Programming/Forge/ForgeIntakeServiceTest.php'],
                        'standard_commands' => ['php artisan test tests/Feature/Ai/Programming/Forge/OutcomeRankedTest.php'],
                        'blocked_commands' => ['php artisan test tests/Feature/Ai/Programming/Forge/FlakyTest.php'],
                        'scope_routing' => [
                            'schema_version' => 'atlas.awis.execution_scope_routing.v1',
                            'area_routes' => [[
                                'key' => 'app/Services/Ai/Programming/Forge',
                                'route_ref' => 'area:'.hash('sha256', 'app/Services/Ai/Programming/Forge'),
                                'preferred_commands' => ['php artisan test tests/Feature/Ai/Programming/Forge/ScopedForgeRouteTest.php'],
                                'deferred_commands' => [],
                                'blocked_commands' => [],
                                'route_mode' => 'prefer_scope_commands',
                                'recommended_validation_tier' => 'instant',
                                'validation_reason' => 'effective_fast_scope_route',
                                'feedback_effectiveness' => 'effective',
                            ]],
                            'stack_routes' => [[
                                'key' => 'laravel',
                                'route_ref' => 'stack:'.hash('sha256', 'laravel'),
                                'preferred_commands' => ['php artisan test tests/Feature/Ai/Programming/Forge/StackLaravelRouteTest.php'],
                                'deferred_commands' => [],
                                'blocked_commands' => [],
                                'route_mode' => 'prefer_scope_commands',
                                'recommended_validation_tier' => 'instant',
                                'validation_reason' => 'effective_fast_scope_route',
                                'feedback_effectiveness' => 'effective',
                            ]],
                            'route_count' => 2,
                        ],
                        'validation_tier_routing' => [
                            'schema_version' => 'atlas.awis.validation_tier_routing.v1',
                            'default_tier' => 'standard',
                            'raw_logs_returned' => false,
                        ],
                    ],
                    'command_performance_histogram' => [
                        'bucket_counts' => ['under_10s' => 1, '5m_to_15m' => 0],
                        'fast_commands' => ['php artisan test tests/Feature/Ai/Programming/Forge/ForgeIntakeServiceTest.php'],
                        'heavy_commands' => [],
                        'slow_commands' => [],
                        'raw_logs_returned' => false,
                    ],
                    'provider_policy' => [
                        'raw_manifest_returned' => false,
                        'script_bodies_returned' => false,
                        'absolute_workspace_path_returned' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * Mirrors the hash payload built inside ForgeIntakeService::persistIntake.
     *
     * @return array<string,mixed>
     */
    private function intakeHashPayload(AiForgeIntake $intake): array
    {
        return [
            'schema' => ForgeIntakeCanon::INTAKE_SCHEMA_VERSION,
            'uuid' => $intake->uuid,
            'origin' => $intake->origin,
            'recommended_forge_mode' => $intake->recommended_forge_mode,
            'obra_title' => $intake->obra_title,
            'workspace_slug' => $intake->workspace_slug,
            'original_user_intent' => $intake->original_user_intent,
            'normalized_intent' => $intake->normalized_intent,
            'scope_assessment' => $intake->scope_assessment,
            'risk_assessment' => $intake->risk_assessment,
            'ambiguity_assessment' => $intake->ambiguity_assessment,
            'risk_band' => $intake->risk_band,
            'mission_id' => $intake->mission_id,
            'escalation_packet_id' => $intake->escalation_packet_id,
            'escalation_packet_hash' => $intake->escalation_packet_hash,
            'promotion_reason' => $intake->promotion_reason,
            'promotion_triggers' => $intake->promotion_triggers,
            'definition_of_done' => $intake->definition_of_done,
            'required_evidence' => $intake->required_evidence,
            'evidence_refs' => $intake->evidence_refs,
            'context_refs' => $intake->context_refs,
            'context_pack_hash' => $intake->context_pack_hash,
            'rich_input_payload' => $intake->rich_input_payload,
            'rich_input_schema_version' => $intake->rich_input_schema_version,
            'context_operations_hash' => $intake->context_operations_hash,
            'persistent_context_hash' => $intake->persistent_context_hash,
            'workspace_execution_gate' => $intake->workspace_execution_gate,
            'constraints' => $intake->constraints,
            'non_goals' => $intake->non_goals,
            'sdd_spec' => $intake->sdd_spec,
            'current_dev_findings' => $intake->current_dev_findings,
            'completed_dev_actions' => $intake->completed_dev_actions,
            'incomplete_dev_actions' => $intake->incomplete_dev_actions,
            'status' => $intake->status,
            'blocker_reason' => $intake->blocker_reason,
            'actor_type' => $intake->actor_type,
        ];
    }
}
