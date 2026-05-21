<?php

namespace Tests\Feature\Ai\RouterRuntime;

use App\Http\Resources\AiTraceResource;
use App\Models\AiAtlasDecisionReceipt;
use App\Models\AiAtlasIntentClassification;
use App\Models\AiAtlasRouterDecision;
use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AutonomousWorkExecution\AtlasAutonomousWorkExecutionService;
use App\Services\Ai\RouterRuntime\AtlasHyperflowEntryService;
use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\RuntimeEfficiency\AtlasRuntimeEfficiencyGovernorService;
use App\Services\Ai\VerifiedExecution\AtlasVerifiedExecutionRuntimeService;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Concerns\CreatesRouterRuntimeTables;
use Tests\Concerns\CreatesRuntimeEfficiencyTables;
use Tests\TestCase;

/**
 * Feature test for the Atlas AI Desktop entry path:
 *
 *   POST /ai/interactions  → AtlasHyperflowEntryService.run()
 *                         → atlas.ai.hyperflow_runtime.v1 envelope on payload
 *                         → trace.metadata.hyperflow_runtime
 *                         → AiTraceResource.hyperflow_runtime
 *
 * Mocks `AiGatewayService::enqueueInteraction` so we can capture the
 * canonical envelope without booting the full provider stack. The 5
 * RouterRuntime tables are created via {@see CreatesRouterRuntimeTables}.
 *
 * Asserts the same surface across 4 different intents:
 *   1. programming + workspace → atlas_dev with programming handoff.
 *   2. research               → atlas_research, evidence required.
 *   3. finance                → atlas_plan, policy + evidence required.
 *   4. marketing              → atlas_plan, policy required.
 */
class AtlasAiInteractionHyperflowEntryTest extends TestCase
{
    use CreatesRouterRuntimeTables;
    use CreatesRuntimeEfficiencyTables;

    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRouterRuntimeTables();
        $this->createRuntimeEfficiencyTables();
        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    protected function tearDown(): void
    {
        $this->dropRuntimeEfficiencyTables();
        $this->dropRouterRuntimeTables();
        parent::tearDown();
    }

    public function test_programming_intent_with_workspace_emits_atlas_dev_hyperflow_envelope(): void
    {
        $captured = $this->postInteraction(
            input: 'corrija o bug do provider router e atualize os testes em /repos/atlas',
            payload: [
                'app_surface' => 'atlas_app',
                'workspace' => '/repos/atlas',
            ],
        );

        $envelope = data_get($captured, 'payload.hyperflow_runtime');
        $this->assertIsArray($envelope, 'orchestrator must always attach an envelope before gateway call');
        $this->assertSame(AtlasHyperflowEntryService::SCHEMA_VERSION, $envelope['schema_version']);
        $this->assertSame('ready', $envelope['status']);
        $this->assertSame('programming', $envelope['primary_domain']);
        $this->assertContains($envelope['flow_id'], ['atlas_dev', 'atlas_debug']);
        $this->assertContains(
            $envelope['intent']['type'],
            [RouterRuntimeCanon::INTENT_PROGRAMMING, RouterRuntimeCanon::INTENT_DEBUG],
            'programming-shaped prompt must classify as programming OR debug',
        );
        $this->assertIsString($envelope['router_decision']['uuid']);
        $this->assertIsString($envelope['flow_route']['uuid']);
        $this->assertIsString($envelope['dispatch']['uuid']);
        $this->assertSame(64, strlen((string) $envelope['router_decision']['receipt_hash']));
        $this->assertSame(64, strlen((string) $envelope['dispatch']['receipt_hash']));
        $this->assertNotNull($envelope['handoff_target'], 'programming flow must declare hand-off');
        $this->assertSame('atlas_dev', $envelope['handoff_target']['kind']);
        $this->assertSame('atlas.context_intelligence.context_certification.v1', data_get($envelope, 'context_intelligence.schema_version'));
        $this->assertContains(data_get($envelope, 'context_intelligence.status'), ['ready', 'degraded']);
        $this->assertIsString(data_get($envelope, 'context_intelligence.context_certification_hash'));
        $this->assertFalse(data_get($envelope, 'context_intelligence.claim_policy.provider_calls_made'));
        $this->assertSame('atlas.conversation_ops.health_report.v1', data_get($envelope, 'conversation_ops.schema_version'));
        $this->assertIsString(data_get($envelope, 'conversation_ops.conversation_health_hash'));
        $this->assertFalse(data_get($envelope, 'conversation_ops.claim_policy.provider_calls_made'));
        $this->assertSame('atlas.context_intelligence.operations_runtime.v1', data_get($envelope, 'context_operations.schema_version'));
        $this->assertSame('atlas.conversation_ops.handoff_packet.v1', data_get($envelope, 'context_handoff_packet.schema_version'));
        $this->assertTrue(data_get($envelope, 'context_operations.integration_policy.handoff_required'));
        $this->assertFalse(data_get($envelope, 'context_operations.claim_policy.provider_calls_made'));
        $this->assertSame('atlas.persistent_context.runtime.v1', data_get($envelope, 'persistent_context.schema_version'));
        $this->assertIsString(data_get($envelope, 'persistent_context.persistent_context_hash'));
        $this->assertSame('atlas.persistent_context.provider_handoff.v1', data_get($envelope, 'persistent_context.provider_handoff.schema_version'));
        $this->assertFalse(data_get($envelope, 'persistent_context.claim_policy.provider_calls_made'));
        $this->assertSame(AtlasRuntimeEfficiencyGovernorService::SCHEMA_VERSION, data_get($envelope, 'runtime_efficiency.schema_version'));
        $this->assertContains(data_get($envelope, 'runtime_efficiency.path'), ['standard_path', 'deep_path']);
        $this->assertSame('atlas.context_minimum_pack.v1', data_get($envelope, 'runtime_efficiency.context_minimum_pack.schema_version'));
        $this->assertSame(false, data_get($envelope, 'runtime_efficiency.claim_policy.provider_invoked'));
        $this->assertSame(AtlasAutonomousWorkExecutionService::EXECUTION_SCHEMA, data_get($captured, 'payload.autonomous_work_execution.schema_version'));
        $this->assertSame(AtlasAutonomousWorkExecutionService::LEVEL_MAX, data_get($captured, 'payload.autonomous_work_execution.maturity_level'));
        $this->assertSame(AtlasVerifiedExecutionRuntimeService::EXECUTION_SCHEMA, data_get($captured, 'payload.autonomous_work_execution.verified_execution.schema_version'));
        $this->assertSame(AtlasVerifiedExecutionRuntimeService::LEVEL_MAX, data_get($captured, 'payload.autonomous_work_execution.verified_execution.maturity_level'));
        $this->assertFalse(data_get($captured, 'payload.autonomous_work_execution.claim_policy.provider_invoked_directly'));
        $this->assertArrayNotHasKey('strategic_reality', $envelope, 'programming handoff must not invoke ASRE by default');

        // Canonical rows persisted.
        $this->assertGreaterThan(0, AiAtlasIntentClassification::query()->count());
        $this->assertGreaterThan(0, AiAtlasRouterDecision::query()->count());
        $this->assertSame(
            2,
            AiAtlasDecisionReceipt::query()->count(),
            'one router_decision receipt + one runtime_dispatch receipt per call',
        );
    }

    public function test_research_intent_emits_atlas_research_with_evidence_required(): void
    {
        $captured = $this->postInteraction(
            input: 'pesquise oportunidades no mercado de trabalho de software engineering',
            payload: ['app_surface' => 'atlas_app'],
        );

        $envelope = data_get($captured, 'payload.hyperflow_runtime');
        $this->assertIsArray($envelope);
        $this->assertSame('research', $envelope['primary_domain']);
        $this->assertSame(RouterRuntimeCanon::INTENT_RESEARCH, $envelope['intent']['type']);
        $this->assertSame('atlas_research', $envelope['flow_id']);
        $this->assertSame(RouterRuntimeCanon::MODE_DEEP, $envelope['runtime_mode']);
        $this->assertTrue($envelope['evidence_required'], 'research demands evidence');
        $this->assertNull($envelope['handoff_target'], 'non-programming intent must not declare programming hand-off');
        $this->assertContains('evidence.gate', $envelope['required_gates']);
        $this->assertSame('atlas.context_intelligence.context_certification.v1', data_get($envelope, 'context_intelligence.schema_version'));
        $this->assertSame('atlas.conversation_ops.health_report.v1', data_get($envelope, 'conversation_ops.schema_version'));
        $this->assertIsString(data_get($envelope, 'context_intelligence.context_certification_hash'));
        $this->assertSame('atlas.context_intelligence.operations_runtime.v1', data_get($envelope, 'context_operations.schema_version'));
        $this->assertFalse(data_get($envelope, 'context_operations.integration_policy.handoff_required'));
    }

    public function test_finance_intent_emits_atlas_plan_with_policy_and_evidence_required(): void
    {
        $captured = $this->postInteraction(
            input: 'analise minha carteira de investimentos para o trimestre',
            payload: ['app_surface' => 'atlas_app'],
        );

        $envelope = data_get($captured, 'payload.hyperflow_runtime');
        $this->assertIsArray($envelope);
        $this->assertSame('finance', $envelope['primary_domain']);
        $this->assertSame(RouterRuntimeCanon::INTENT_FINANCE, $envelope['intent']['type']);
        // Canon emits the dedicated specialist flow `atlas_finance` (not the
        // legacy `atlas_plan` fallback) since the multi-domain refactor.
        $this->assertSame(RouterRuntimeCanon::FLOW_FINANCE, $envelope['flow_id']);
        $this->assertSame(RouterRuntimeCanon::MODE_DEEP, $envelope['runtime_mode']);
        $this->assertTrue($envelope['policy_required'], 'finance demands policy gate');
        $this->assertTrue($envelope['evidence_required']);
        $this->assertNull($envelope['handoff_target']);
        $this->assertContains('policy.gate', $envelope['required_gates']);
        $this->assertContains('evidence.gate', $envelope['required_gates']);
        $this->assertSame('atlas.strategic_reality.decision.v1', data_get($envelope, 'strategic_reality.schema_version'));
        $this->assertSame(false, data_get($envelope, 'strategic_reality.claim_policy.external_execution_performed'));
    }

    public function test_strategy_intent_attaches_asre_without_changing_router_decision(): void
    {
        $captured = $this->postInteraction(
            input: 'qual e a melhor estrategia e proxima decisao para o Atlas agora?',
            payload: ['app_surface' => 'atlas_app'],
        );

        $envelope = data_get($captured, 'payload.hyperflow_runtime');
        $this->assertIsArray($envelope);
        $this->assertSame('strategy', $envelope['primary_domain']);
        $this->assertSame(RouterRuntimeCanon::INTENT_STRATEGY, $envelope['intent']['type']);
        $this->assertSame(RouterRuntimeCanon::FLOW_STRATEGY, $envelope['flow_id']);
        $this->assertSame('atlas.strategic_reality.decision.v1', data_get($envelope, 'strategic_reality.schema_version'));
        $this->assertContains(data_get($envelope, 'strategic_reality.status'), ['ready', 'watch']);
        $this->assertIsString(data_get($envelope, 'strategic_reality.decision_hash'));
        $this->assertNotEmpty(data_get($envelope, 'strategic_reality.context_signals'));
        $this->assertContains('persistent_context', collect(data_get($envelope, 'strategic_reality.context_signals', []))->pluck('source')->all());
        $this->assertContains('aemor', collect(data_get($envelope, 'strategic_reality.context_signals', []))->pluck('source')->all());
        $this->assertContains('intelligence_factory', collect(data_get($envelope, 'strategic_reality.context_signals', []))->pluck('source')->all());
        $this->assertSame(false, data_get($envelope, 'strategic_reality.claim_policy.provider_invoked'));
        $this->assertSame(false, data_get($envelope, 'strategic_reality.claim_policy.external_execution_performed'));
        $this->assertSame('atlas_strategy', $envelope['flow_id'], 'ASRE sidecar must not rewrite RouterRuntime flow');
    }

    public function test_financial_external_action_asre_blocks_execution_sidecar_only(): void
    {
        $captured = $this->postInteraction(
            input: 'devo comprar e vender automaticamente ativos em day trade agora?',
            payload: ['app_surface' => 'atlas_app'],
        );

        $envelope = data_get($captured, 'payload.hyperflow_runtime');
        $this->assertIsArray($envelope);
        $this->assertSame('finance', $envelope['primary_domain']);
        $this->assertSame(RouterRuntimeCanon::FLOW_FINANCE, $envelope['flow_id']);
        $this->assertSame('blocked', data_get($envelope, 'strategic_reality.status'));
        $this->assertSame(false, data_get($envelope, 'strategic_reality.claim_policy.external_execution_performed'));
        $this->assertContains('request_operator_approval', data_get($envelope, 'strategic_reality.next_actions', []));
    }

    public function test_marketing_intent_emits_atlas_plan_with_policy_required(): void
    {
        $captured = $this->postInteraction(
            input: 'crie uma campanha de marketing focada em retenção',
            payload: ['app_surface' => 'atlas_app'],
        );

        $envelope = data_get($captured, 'payload.hyperflow_runtime');
        $this->assertIsArray($envelope);
        $this->assertSame('marketing', $envelope['primary_domain']);
        $this->assertSame(RouterRuntimeCanon::INTENT_MARKETING, $envelope['intent']['type']);
        // Canon emits the dedicated specialist flow `atlas_marketing` (not
        // the legacy `atlas_plan` fallback) since the multi-domain refactor.
        $this->assertSame(RouterRuntimeCanon::FLOW_MARKETING, $envelope['flow_id']);
        $this->assertTrue($envelope['policy_required'], 'marketing is high-risk domain');
        $this->assertContains('policy.gate', $envelope['required_gates']);
        $this->assertNull($envelope['handoff_target']);
    }

    public function test_rich_input_payload_is_preserved_for_hyperflow_gateway_options(): void
    {
        $clientId = (string) Str::uuid();
        $captured = null;

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($clientId, &$captured): void {
            $mock->shouldReceive('enqueueInteraction')
                ->once()
                ->andReturnUsing(function (string $input, array $options) use ($clientId, &$captured): AiTrace {
                    $captured = $options;

                    return $this->trace($clientId);
                });
        });

        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions', [
                'input_text' => 'analise este video https://youtu.be/dQw4w9WgXcQ',
                'client_id' => $clientId,
                'new_thread' => true,
                'agent_slug' => 'orquestrador',
                'provider' => 'codex_cli',
                'source_type' => 'app',
                'payload' => ['app_surface' => 'atlas_mobile_ai', 'surface_id' => 'atlas_mobile_ai'],
                'rich_input_payload' => [
                    'schema_version' => 'atlas.rich_input.payload.v1',
                    'uploaded_image_ids' => [],
                    'uploaded_document_ids' => [],
                    'text_blocks' => [],
                    'url_attachments' => [[
                        'url' => 'https://youtu.be/dQw4w9WgXcQ',
                        'kind' => 'youtube',
                        'title' => null,
                        'author' => null,
                        'duration_sec' => null,
                        'thumbnail_url' => 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
                        'ref_id' => 'dQw4w9WgXcQ',
                    ]],
                    'source_manifest' => [[
                        'id' => 'url-test',
                        'kind' => 'url',
                        'file_name' => 'https://youtu.be/dQw4w9WgXcQ',
                        'mime_type' => 'text/uri-list',
                        'size' => 0,
                        'uploaded_id' => null,
                        'source_hash' => null,
                        'source' => 'paste',
                    ]],
                ],
            ])
            ->assertAccepted();

        $this->assertIsArray($captured, 'gateway must have been called with options');
        $this->assertSame('atlas.rich_input.payload.v1', data_get($captured, 'payload.rich_input_payload.schema_version'));
        $this->assertSame('youtube', data_get($captured, 'payload.rich_input_payload.url_attachments.0.kind'));
        $this->assertSame('dQw4w9WgXcQ', data_get($captured, 'payload.rich_input_payload.url_attachments.0.ref_id'));
        $this->assertSame('url', data_get($captured, 'payload.rich_input_payload.source_manifest.0.kind'));
        $this->assertIsArray(data_get($captured, 'payload.hyperflow_runtime'), 'Hyperflow must still run after rich input normalization');
        $this->assertContains(
            'context:rich_input:url:url-test',
            data_get($captured, 'payload.hyperflow_runtime.context_intelligence.evidence_refs', []),
        );
        $this->assertSame(
            'atlas.context_intelligence.operations_runtime.v1',
            data_get($captured, 'payload.hyperflow_runtime.context_operations.schema_version'),
        );
    }

    public function test_atlas_code_obra_ambiguous_prompt_uses_surface_contract_for_forge_hyperflow(): void
    {
        $captured = $this->postInteraction(
            input: 'arruma isso',
            payload: [
                'app_surface' => 'atlas_code',
                'surface_id' => 'atlas_code',
                'requires_obra' => true,
                'atlas_mode' => 'forge',
                'current_mode' => 'forge',
                'atlas_workflow_mode' => 'forge',
                'domain_id' => 'programming',
                'flow_id' => 'programming.forge',
                'routing_domain' => 'programming',
                'routing_task' => 'forge',
                'operator_composer_hints' => [
                    'schema_version' => 'atlas.unified_composer.hints.v1',
                    'mode' => 'auto',
                    'task' => 'auto',
                    'provider' => 'auto',
                    'preserves_surface_flow' => true,
                    'surface_flow' => 'programming.forge',
                    'provider_selection_effect' => 'atlas_decide',
                ],
            ],
        );

        $envelope = data_get($captured, 'payload.hyperflow_runtime');
        $this->assertIsArray($envelope);
        $this->assertSame('ready', $envelope['status']);
        $this->assertSame('programming', $envelope['primary_domain']);
        $this->assertSame(RouterRuntimeCanon::INTENT_PROGRAMMING, $envelope['intent']['type']);
        $this->assertSame('atlas_forge', $envelope['flow_id']);
        $this->assertSame(RouterRuntimeCanon::MODE_FORGE, $envelope['runtime_mode']);
        $this->assertSame('atlas_forge', $envelope['handoff_target']['kind']);
        $this->assertSame('hyperflow_programming_flow_handoff', $envelope['handoff_target']['reason']);
        $this->assertSame('atlas.conversation_ops.handoff_packet.v1', data_get($envelope, 'context_handoff_packet.schema_version'));
        $this->assertTrue(data_get($envelope, 'context_operations.integration_policy.verified_compaction_required'));
        $this->assertContains('surface:atlas_code', $envelope['intent']['matched_keywords']);
        $this->assertContains('tool_plan.gate', $envelope['required_gates']);
        $this->assertTrue($envelope['evidence_required']);
        $this->assertTrue($envelope['tool_plan_required']);

        $intent = AiAtlasIntentClassification::query()->latest('id')->first();
        $this->assertSame(
            'atlas_code_surface_contract_requires_forge',
            data_get($intent?->signals, 'intent_override.reason'),
        );
        $this->assertSame(
            RouterRuntimeCanon::MODE_FORGE,
            data_get($intent?->signals, 'surface_contract.routing_mode'),
        );
        $this->assertTrue((bool) data_get($intent?->signals, 'surface_contract.atlas_decide_authority'));
    }

    public function test_desktop_ai_explicit_programming_bad_prompt_routes_to_programming_handoff(): void
    {
        $captured = $this->postInteraction(
            input: 'não funciona',
            payload: [
                'app_surface' => 'atlas_desktop_ai',
                'surface_id' => 'atlas_desktop_ai',
                'atlas_mode' => 'programming',
                'atlas_focus' => 'programming',
                'atlas_workflow_mode' => 'debug',
                'routing_task' => 'debug',
                'flow_id' => 'programming.repair',
                'domain_id' => 'programming',
                'workspace' => '/tmp/atlas-workspace',
            ],
        );

        $envelope = data_get($captured, 'payload.hyperflow_runtime');
        $this->assertIsArray($envelope);
        $this->assertSame('programming', $envelope['primary_domain']);
        $this->assertSame(RouterRuntimeCanon::INTENT_DEBUG, $envelope['intent']['type']);
        $this->assertSame('atlas_debug', $envelope['flow_id']);
        $this->assertSame('atlas_dev', $envelope['handoff_target']['kind']);
        $this->assertContains('composer_mode:programming', $envelope['intent']['matched_keywords']);

        $intent = AiAtlasIntentClassification::query()->latest('id')->first();
        $this->assertSame(
            'explicit_programming_composer_contract',
            data_get($intent?->signals, 'intent_override.reason'),
        );
        $this->assertSame('atlas_desktop_ai', data_get($intent?->signals, 'surface_contract.surface_id'));
        $this->assertSame('debug', data_get($intent?->signals, 'surface_contract.task'));
    }

    public function test_envelope_is_idempotent_when_already_present(): void
    {
        $clientId = (string) Str::uuid();
        $captured = null;

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($clientId, &$captured): void {
            $mock->shouldReceive('enqueueInteraction')
                ->once()
                ->andReturnUsing(function (string $input, array $options) use ($clientId, &$captured): AiTrace {
                    $captured = $options;

                    return $this->trace($clientId);
                });
        });

        $this->withHeaders($this->headers)->postJson('/ai/interactions', [
            'input_text' => 'pesquise oportunidades de mercado',
            'client_id' => $clientId,
            'new_thread' => true,
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'source_type' => 'app',
            'payload' => [
                'app_surface' => 'atlas_app',
                'hyperflow_runtime' => [
                    'schema_version' => AtlasHyperflowEntryService::SCHEMA_VERSION,
                    'status' => 'externally_supplied',
                    'primary_domain' => 'external_test',
                ],
            ],
        ])->assertAccepted();

        // Idempotency: the orchestrator must NOT overwrite a pre-existing envelope.
        $this->assertSame('externally_supplied', data_get($captured, 'payload.hyperflow_runtime.status'));
        $this->assertSame('external_test', data_get($captured, 'payload.hyperflow_runtime.primary_domain'));
        $this->assertSame(0, AiAtlasIntentClassification::query()->count(), 'short-circuit must not persist new rows');
    }

    public function test_trace_resource_exposes_flat_hyperflow_alongside_rich_envelope(): void
    {
        // The Desktop reads `trace.hyperflow` (flat) — we make sure the
        // resource derives the flat shape from the rich envelope and that
        // every key the front consumes is present and well-typed. The rich
        // `trace.hyperflow_runtime` MUST keep being emitted too (audit).
        $envelopeSeed = [
            'schema_version' => AtlasHyperflowEntryService::SCHEMA_VERSION,
            'status' => 'ready',
            'intent' => [
                'uuid' => 'intent-uuid',
                'type' => RouterRuntimeCanon::INTENT_RESEARCH,
                'confidence' => 0.72,
                'ambiguity_score' => 0.18,
                'matched_keywords' => ['pesquise'],
                'normalized_intent' => 'pesquise oportunidades',
            ],
            'primary_domain' => 'research',
            'secondary_domains' => [],
            'flow_id' => 'atlas_research',
            'flow_profile' => 'research.deep',
            'runtime_mode' => RouterRuntimeCanon::MODE_DEEP,
            'routing_confidence' => 0.72,
            'policy_required' => false,
            'evidence_required' => true,
            'tool_plan_required' => false,
            'required_gates' => ['evidence.gate'],
            'expected_capabilities' => ['source.fetch'],
            'fallback_flows' => [],
            'router_decision' => [
                'uuid' => 'rd-uuid',
                'id' => 'rd-id',
                'status' => 'routed',
                'reason' => ['reasons' => ['intent_type:research', 'primary_domain:research']],
                'receipt_hash' => str_repeat('a', 64),
            ],
            'flow_route' => ['uuid' => 'fr-uuid', 'id' => 'fr-id', 'status' => 'ready'],
            'dispatch' => [
                'uuid' => 'd-uuid',
                'id' => 'd-id',
                'dispatch_target' => 'atlas_research',
                'dispatch_status' => RouterRuntimeCanon::DISPATCH_PLANNED,
                'blockers' => [],
                'receipt_hash' => str_repeat('b', 64),
            ],
            'dispatch_status' => RouterRuntimeCanon::DISPATCH_PLANNED,
            'handoff_target' => null,
            'decision_receipt' => [
                'router_decision_receipt' => ['id' => 'rr-id', 'uuid' => 'rr-uuid', 'receipt_type' => 'router_decision', 'receipt_hash' => str_repeat('c', 64)],
                'runtime_dispatch_receipt' => ['id' => 'dr-id', 'uuid' => 'dr-uuid', 'receipt_type' => 'runtime_dispatch', 'receipt_hash' => str_repeat('d', 64)],
            ],
        ];

        $trace = (new AiTrace)->forceFill([
            'id' => (string) Str::uuid(),
            'trace_key' => 'trace_hyperflow_flat',
            'status' => 'queued',
            'operator_input' => 'pesquise oportunidades',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'skill_versions' => [],
            'context_refs' => [],
            'metadata' => ['hyperflow_runtime' => $envelopeSeed],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = (new AiTraceResource($trace))->toArray(request());

        // Rich envelope still exposed for audit.
        $this->assertIsArray($payload['hyperflow_runtime']);
        $this->assertSame('research', $payload['hyperflow_runtime']['primary_domain']);

        // Flat projection — the contract the Desktop already speaks.
        $this->assertIsArray($payload['hyperflow']);
        $this->assertSame(RouterRuntimeCanon::INTENT_RESEARCH, $payload['hyperflow']['intent']);
        $this->assertSame('research', $payload['hyperflow']['domain_id']);
        $this->assertSame('atlas_research', $payload['hyperflow']['flow_id']);
        $this->assertSame(RouterRuntimeCanon::MODE_DEEP, $payload['hyperflow']['runtime_mode']);
        $this->assertSame(0.72, $payload['hyperflow']['confidence']);
        $this->assertSame([], $payload['hyperflow']['policy_refs'], 'no policy gate on this research case');
        $this->assertSame(['evidence.gate'], $payload['hyperflow']['evidence_refs']);
        $this->assertSame('dr-id', $payload['hyperflow']['decision_receipt_id']);
        $this->assertSame(str_repeat('d', 64), $payload['hyperflow']['decision_receipt_hash']);
        $this->assertSame(RouterRuntimeCanon::DISPATCH_PLANNED, $payload['hyperflow']['dispatch_status']);
        $this->assertNull($payload['hyperflow']['handoff_target'], 'research must not declare programming hand-off');
        $this->assertContains('intent_type:research', $payload['hyperflow']['reasons']);
    }

    public function test_flat_hyperflow_carries_handoff_target_for_programming_flows(): void
    {
        $envelopeSeed = [
            'schema_version' => AtlasHyperflowEntryService::SCHEMA_VERSION,
            'status' => 'ready',
            'intent' => ['type' => RouterRuntimeCanon::INTENT_PROGRAMMING, 'confidence' => 0.8],
            'primary_domain' => 'programming',
            'flow_id' => 'atlas_dev',
            'runtime_mode' => RouterRuntimeCanon::MODE_STANDARD,
            'routing_confidence' => 0.8,
            'policy_required' => false,
            'evidence_required' => true,
            'required_gates' => ['evidence.gate'],
            'router_decision' => ['receipt_hash' => str_repeat('a', 64), 'reason' => ['reasons' => []]],
            'dispatch' => ['receipt_hash' => str_repeat('b', 64), 'dispatch_status' => RouterRuntimeCanon::DISPATCH_SIMULATED],
            'dispatch_status' => RouterRuntimeCanon::DISPATCH_SIMULATED,
            'handoff_target' => [
                'kind' => 'atlas_dev',
                'flow_id' => 'atlas_dev',
                'reason' => 'hyperflow_programming_flow_handoff',
                'routing_mode' => RouterRuntimeCanon::MODE_STANDARD,
            ],
            'decision_receipt' => [
                'router_decision_receipt' => ['id' => 'rr', 'receipt_hash' => str_repeat('c', 64)],
                'runtime_dispatch_receipt' => ['id' => 'dr', 'receipt_hash' => str_repeat('d', 64)],
            ],
        ];

        $trace = (new AiTrace)->forceFill([
            'id' => (string) Str::uuid(),
            'trace_key' => 'trace_hyperflow_dev_handoff',
            'status' => 'queued',
            'operator_input' => 'corrija o bug',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'skill_versions' => [],
            'context_refs' => [],
            'metadata' => ['hyperflow_runtime' => $envelopeSeed],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = (new AiTraceResource($trace))->toArray(request());

        $this->assertSame('atlas_dev', $payload['hyperflow']['handoff_target']);
        $this->assertSame('hyperflow_programming_flow_handoff', $payload['hyperflow']['handoff_reason']);
        $this->assertSame('programming', $payload['hyperflow']['domain_id']);
        $this->assertSame('atlas_dev', $payload['hyperflow']['flow_id']);
    }

    public function test_trace_resource_exposes_hyperflow_runtime_from_metadata(): void
    {
        // Direct resource shape test: build a non-persisted AiTrace carrying
        // a hyperflow_runtime envelope on its metadata and assert the
        // resource surfaces it as a top-level field. This exercises the
        // gateway → metadata → resource contract without depending on the
        // ai_traces table being present in the in-memory fixture set.
        $envelopeSeed = [
            'schema_version' => AtlasHyperflowEntryService::SCHEMA_VERSION,
            'primary_domain' => 'research',
            'flow_id' => 'atlas_research',
            'runtime_mode' => RouterRuntimeCanon::MODE_DEEP,
            'status' => 'ready',
            'dispatch_status' => RouterRuntimeCanon::DISPATCH_PLANNED,
        ];

        $trace = (new AiTrace)->forceFill([
            'id' => (string) Str::uuid(),
            'trace_key' => 'trace_hyperflow_smoke',
            'status' => 'queued',
            'operator_input' => 'pesquise mercado de software',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'skill_versions' => [],
            'context_refs' => [],
            'metadata' => ['hyperflow_runtime' => $envelopeSeed],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = (new AiTraceResource($trace))->toArray(request());

        $this->assertIsArray($payload['hyperflow_runtime']);
        $this->assertSame('research', $payload['hyperflow_runtime']['primary_domain']);
        $this->assertSame('atlas_research', $payload['hyperflow_runtime']['flow_id']);
        $this->assertSame(
            AtlasHyperflowEntryService::SCHEMA_VERSION,
            $payload['hyperflow_runtime']['schema_version'],
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed> captured gateway options
     */
    private function postInteraction(string $input, array $payload): array
    {
        $clientId = (string) Str::uuid();
        $captured = null;

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($clientId, $input, &$captured): void {
            $mock->shouldReceive('enqueueInteraction')
                ->once()
                ->with($input, \Mockery::on(function (array $options) use ($clientId, &$captured): bool {
                    $captured = $options;

                    return ($options['client_id'] ?? null) === $clientId;
                }))
                ->andReturn($this->trace($clientId));
        });

        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions', [
                'input_text' => $input,
                'client_id' => $clientId,
                'new_thread' => true,
                'agent_slug' => 'orquestrador',
                'provider' => 'codex_cli',
                'source_type' => 'app',
                'payload' => $payload,
            ])
            ->assertAccepted();

        $this->assertIsArray($captured, 'gateway must have been called with options');

        return (array) $captured;
    }

    private function trace(string $clientId): AiTrace
    {
        return tap(new AiTrace, fn (AiTrace $trace) => $trace->forceFill([
            'id' => (string) Str::uuid(),
            'trace_key' => 'hyperflow_'.$clientId,
            'status' => 'queued',
            'operator_input' => 'fixture',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'skill_versions' => [],
            'context_refs' => [],
            'metadata' => ['client_id' => $clientId],
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }
}
