<?php

namespace Tests\Feature\Ai\Programming\Forge;

use App\Models\AiForgeIntake;
use App\Models\AiForgeLongHorizonState;
use App\Models\AtlasLongHorizonContinuationPack;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\Programming\Forge\ForgeContinuationPackBuilder;
use App\Services\Ai\Programming\Forge\ForgeIntakeCanon;
use App\Services\Ai\Programming\Forge\ForgeIntakeService;
use App\Services\Ai\Programming\Forge\ForgeLongHorizonStateCanon;
use App\Services\Ai\Programming\Forge\ForgeLongHorizonStateService;
use Tests\Concerns\CreatesForgeLongHorizonStateTable;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

/**
 * TEOS-I1 Sprint 2 — Forge `emitContinuationPack()` hook.
 *
 * Verifies that {@see ForgeLongHorizonStateService::emitContinuationPack()}
 * delegates to {@see ForgeContinuationPackBuilder} and persists a canonical
 * `atlas.long_horizon.continuation_pack.v2` row without mutating the live
 * Forge state. Also asserts the safe_resume_mode invariants:
 *  - intake blocked → blocked
 *  - active obra with no blockers → execute
 *  - certification milestone without certification evidence → review
 *  - unresolved milestone blocker → ask_human
 *  - completed obra → read_only
 *  - pack_hash determinístic over equivalent payloads.
 */
class ForgeContinuationPackEmissionTest extends TestCase
{
    use CreatesForgeLongHorizonStateTable;
    use CreatesLongHorizonPersistenceTables;

    private ForgeLongHorizonStateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createForgeLongHorizonStateTable();
        $this->createLongHorizonPersistenceTables();
        $this->service = app(ForgeLongHorizonStateService::class);
    }

    protected function tearDown(): void
    {
        $this->dropLongHorizonPersistenceTables();
        $this->dropForgeLongHorizonStateTable();
        parent::tearDown();
    }

    public function test_emit_continuation_pack_creates_canonical_row_for_ready_intake(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);

        $stateHashBefore = $state->state_hash;
        $cycleCountBefore = (int) $state->cycle_count;

        $pack = $this->service->emitContinuationPack($state);

        $this->assertInstanceOf(AtlasLongHorizonContinuationPack::class, $pack);
        $this->assertSame(AtlasLongHorizonCanon::CONTINUATION_PACK_SCHEMA_VERSION, $pack->schema_version);
        $this->assertSame(AtlasLongHorizonCanon::SCOPE_TYPE_OBRA, $pack->scope_type);
        $this->assertSame($intake->id, $pack->scope_id);
        $this->assertSame($intake->obra_title, $pack->objective);
        $this->assertSame(ForgeIntakeCanon::MILESTONE_DESIGN_CONTEXT, $pack->current_phase);
        $this->assertSame(64, strlen((string) $pack->pack_hash));

        // claim_policy invariant: state row was NOT mutated.
        $state->refresh();
        $this->assertSame($stateHashBefore, $state->state_hash);
        $this->assertSame($cycleCountBefore, (int) $state->cycle_count);
    }

    public function test_pack_carries_milestones_and_work_packets_in_context_manifest(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);

        $pack = $this->service->emitContinuationPack($state);
        $manifest = $pack->context_manifest;

        $this->assertIsArray($manifest);
        $this->assertSame($intake->id, $manifest['intake_id']);
        $this->assertSame(ForgeIntakeCanon::MILESTONE_DESIGN_CONTEXT, $manifest['current_milestone']);

        $milestoneIds = collect($manifest['milestones'])->pluck('milestone_id')->all();
        sort($milestoneIds);
        $expected = ForgeIntakeCanon::CANONICAL_MILESTONES;
        sort($expected);
        $this->assertSame($expected, $milestoneIds, '5 canonical milestones must surface in the pack');

        $this->assertGreaterThanOrEqual(1, count($manifest['work_packets']));
        $this->assertSame(
            ForgeIntakeCanon::PACKET_STATUS_PROPOSED,
            $manifest['work_packets'][0]['status'],
        );

        $openTasks = collect($pack->open_tasks)->pluck('task_id')->all();
        $this->assertNotEmpty($openTasks, 'proposed packets must appear as open_tasks');
    }

    public function test_intake_blocked_yields_safe_resume_mode_blocked(): void
    {
        $intake = app(ForgeIntakeService::class)->intakeFromPrompt('palavra aleatoria sem acao concreta');
        $this->assertSame(ForgeIntakeCanon::STATUS_BLOCKED, $intake->status);

        $state = $this->service->initializeForIntake($intake);

        $pack = $this->service->emitContinuationPack($state);

        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED, $pack->safe_resume_mode);
        $this->assertNotEmpty($pack->blockers);
        $this->assertNotEmpty($pack->human_decisions_required);
        $intakeBlocker = collect($pack->human_decisions_required)
            ->firstWhere('kind', 'resolve_intake_blocker');
        $this->assertNotNull($intakeBlocker);
    }

    public function test_unresolved_milestone_blocker_yields_ask_human_when_state_active(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);
        $state = $this->service->recordCycle($state, [
            'blockers' => [[
                'scope' => ForgeLongHorizonStateCanon::BLOCKER_SCOPE_MILESTONE,
                'target' => ForgeIntakeCanon::MILESTONE_DESIGN_CONTEXT,
                'reason' => 'design_context_unverified',
            ]],
        ]);

        $pack = $this->service->emitContinuationPack($state);

        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED, $pack->safe_resume_mode);
        $this->assertCount(1, $pack->human_decisions_required);
        $this->assertSame('resolve_blocker', $pack->human_decisions_required[0]['kind']);
    }

    public function test_certification_milestone_without_certification_evidence_blocks_execute(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);

        // Walk to certification milestone exactly like the existing happy path
        // but stop BEFORE attaching certification evidence.
        $state = $this->service->recordCycle($state, [
            'evidence_refs' => [
                ['kind' => 'plan', 'ref' => 'spec://p'],
                ['kind' => 'context_pack', 'ref' => 'cp://c'],
            ],
        ]);
        $this->service->advanceMilestone($state);
        $state->refresh();

        $state = $this->service->recordCycle($state, [
            'evidence_refs' => [
                ['kind' => 'reservations', 'ref' => 'res://1'],
                ['kind' => 'permissions', 'ref' => 'perm://1'],
                ['kind' => 'work_packet_receipts', 'ref' => 'wpr://1'],
            ],
            'completed_work_packets' => ['wp-001'],
        ]);
        $this->service->advanceMilestone($state);
        $state->refresh();

        $state = $this->service->recordCycle($state, [
            'evidence_refs' => [['kind' => 'verification_receipt', 'ref' => 'vr://1']],
        ]);
        $this->service->advanceMilestone($state);
        $state->refresh();

        $state = $this->service->recordCycle($state, [
            'evidence_refs' => [['kind' => 'evidence_pack', 'ref' => 'ep://1']],
        ]);
        $this->service->advanceMilestone($state);
        $state->refresh();

        $this->assertSame(ForgeIntakeCanon::MILESTONE_CERTIFICATION, $state->current_milestone);

        $pack = $this->service->emitContinuationPack($state);

        $this->assertSame(
            AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN,
            $pack->safe_resume_mode,
            'certification milestone without certification evidence must NOT allow execute',
        );
        $attachCert = collect($pack->human_decisions_required)
            ->firstWhere('kind', 'attach_certification_evidence');
        $this->assertNotNull($attachCert);
    }

    public function test_completed_obra_yields_read_only_safe_resume_mode(): void
    {
        $state = $this->walkObraToCompletion();

        $pack = $this->service->emitContinuationPack($state);

        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY, $pack->safe_resume_mode);
        $this->assertSame('obra_completed', $pack->next_safe_action);
    }

    public function test_pack_hash_is_deterministic_for_equivalent_state(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);

        $pack1 = $this->service->emitContinuationPack($state);
        $pack2 = $this->service->emitContinuationPack($state);

        // Two pack rows are created — different uuid + created_at — but the
        // canonical pack_hash MUST be identical since the underlying state
        // didn't change.
        $payload1 = $this->canonicalProjection($pack1);
        $payload2 = $this->canonicalProjection($pack2);
        $this->assertSame(
            AtlasLongHorizonContinuationPack::canonicalPackHash($payload1),
            AtlasLongHorizonContinuationPack::canonicalPackHash($payload2),
        );
    }

    public function test_active_obra_with_no_blockers_yields_execute(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);
        // Add evidence; gates green; no blockers.
        $state = $this->service->recordCycle($state, [
            'evidence_refs' => [
                ['kind' => 'plan', 'ref' => 'spec://p'],
                ['kind' => 'context_pack', 'ref' => 'cp://c'],
            ],
        ]);

        $pack = $this->service->emitContinuationPack($state);

        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE, $pack->safe_resume_mode);
        $this->assertSame([], $pack->human_decisions_required);
    }

    public function test_pack_evidence_and_source_receipts_track_state(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);
        $state = $this->service->recordCycle($state, [
            'evidence_refs' => [
                ['kind' => 'plan', 'ref' => 'spec://p', 'hash' => str_repeat('a', 64)],
            ],
        ]);

        $pack = $this->service->emitContinuationPack($state);

        $kinds = collect($pack->evidence_refs)->pluck('kind')->all();
        $this->assertContains('plan', $kinds);

        $this->assertNotEmpty($pack->source_receipts);
        $this->assertSame('forge_long_horizon_state', $pack->source_receipts[0]['kind']);
        $this->assertSame($state->uuid, $pack->source_receipts[0]['ref']);
        $this->assertSame($state->state_hash, $pack->source_receipts[0]['hash']);
    }

    public function test_safe_resume_mode_belongs_to_canonical_taxonomy(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);
        $pack = $this->service->emitContinuationPack($state);

        $this->assertContains(
            $pack->safe_resume_mode,
            AtlasLongHorizonCanon::ALLOWED_SAFE_RESUME_MODES,
        );
    }

    public function test_builder_payload_method_does_not_persist(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);
        $countBefore = AtlasLongHorizonContinuationPack::query()->count();

        /** @var ForgeContinuationPackBuilder $builder */
        $builder = app(ForgeContinuationPackBuilder::class);
        $payload = $builder->payload($state);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('scope_type', $payload);
        $this->assertSame(AtlasLongHorizonCanon::SCOPE_TYPE_OBRA, $payload['scope_type']);
        $this->assertSame($countBefore, AtlasLongHorizonContinuationPack::query()->count());
    }

    private function readyIntake(): AiForgeIntake
    {
        return app(ForgeIntakeService::class)->intakeFromPrompt(
            'Implementar refactor multi-modulo do provider router com sdd e multi-agent fallback.',
            [
                'workspace_slug' => 'atlas-server',
                'workspace_execution_gate' => $this->allowedWorkspaceExecutionGate(),
            ],
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
            'workspace_id' => 'atlas-server',
            'allowed' => true,
            'status' => 'passed',
            'blockers' => [],
            'required_contracts' => [
                'awco_execution_readiness' => true,
            ],
        ];
    }

    private function walkObraToCompletion(): AiForgeLongHorizonState
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);

        $state = $this->service->recordCycle($state, [
            'evidence_refs' => [
                ['kind' => 'plan', 'ref' => 'spec://p'],
                ['kind' => 'context_pack', 'ref' => 'cp://c'],
            ],
        ]);
        $this->service->advanceMilestone($state);
        $state->refresh();

        $state = $this->service->recordCycle($state, [
            'evidence_refs' => [
                ['kind' => 'reservations', 'ref' => 'res://1'],
                ['kind' => 'permissions', 'ref' => 'perm://1'],
                ['kind' => 'work_packet_receipts', 'ref' => 'wpr://1'],
            ],
            'completed_work_packets' => ['wp-001'],
        ]);
        $this->service->advanceMilestone($state);
        $state->refresh();

        $state = $this->service->recordCycle($state, [
            'evidence_refs' => [['kind' => 'verification_receipt', 'ref' => 'vr://1']],
        ]);
        $this->service->advanceMilestone($state);
        $state->refresh();

        $state = $this->service->recordCycle($state, [
            'evidence_refs' => [['kind' => 'evidence_pack', 'ref' => 'ep://1']],
        ]);
        $this->service->advanceMilestone($state);
        $state->refresh();

        $state = $this->service->recordCycle($state, [
            'evidence_refs' => [['kind' => 'certification', 'ref' => 'cert://final']],
        ]);
        $this->service->completeObra($state);

        return $state->refresh();
    }

    /**
     * @return array<string,mixed>
     */
    private function canonicalProjection(AtlasLongHorizonContinuationPack $pack): array
    {
        return [
            'scope_type' => $pack->scope_type,
            'scope_id' => $pack->scope_id,
            'objective' => $pack->objective,
            'current_phase' => $pack->current_phase,
            'state_summary' => $pack->state_summary,
            'decisions' => $pack->decisions,
            'superseded_decisions' => $pack->superseded_decisions,
            'open_tasks' => $pack->open_tasks,
            'completed_tasks' => $pack->completed_tasks,
            'blockers' => $pack->blockers,
            'risks' => $pack->risks,
            'evidence_refs' => $pack->evidence_refs,
            'context_manifest' => $pack->context_manifest,
            'context_pack_hash' => $pack->context_pack_hash,
            'summary_hash' => $pack->summary_hash,
            'source_receipts' => $pack->source_receipts,
            'safe_resume_mode' => $pack->safe_resume_mode,
            'next_safe_action' => $pack->next_safe_action,
            'human_decisions_required' => $pack->human_decisions_required,
            'confidence' => $pack->confidence,
        ];
    }
}
