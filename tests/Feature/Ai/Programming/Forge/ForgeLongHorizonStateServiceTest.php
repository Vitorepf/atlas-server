<?php

namespace Tests\Feature\Ai\Programming\Forge;

use App\Models\AiForgeIntake;
use App\Models\AiForgeLongHorizonState;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Forge\ForgeIntakeCanon;
use App\Services\Ai\Programming\Forge\ForgeIntakeService;
use App\Services\Ai\Programming\Forge\ForgeLongHorizonException;
use App\Services\Ai\Programming\Forge\ForgeLongHorizonStateCanon;
use App\Services\Ai\Programming\Forge\ForgeLongHorizonStateService;
use App\Services\Ai\Programming\Forge\ForgeMilestoneGateRunner;
use Tests\Concerns\CreatesForgeLongHorizonStateTable;
use Tests\TestCase;

class ForgeLongHorizonStateServiceTest extends TestCase
{
    use CreatesForgeLongHorizonStateTable;

    private ForgeLongHorizonStateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createForgeLongHorizonStateTable();
        $this->service = app(ForgeLongHorizonStateService::class);
    }

    protected function tearDown(): void
    {
        $this->dropForgeLongHorizonStateTable();
        parent::tearDown();
    }

    public function test_initialize_creates_canonical_long_horizon_state_for_ready_intake(): void
    {
        $intake = $this->readyIntake();

        $state = $this->service->initializeForIntake($intake);

        $this->assertSame(ForgeLongHorizonStateCanon::SCHEMA_VERSION, $state->schema_version);
        $this->assertSame(ForgeLongHorizonStateCanon::STATUS_ACTIVE, $state->status);
        $this->assertSame(ForgeIntakeCanon::MILESTONE_DESIGN_CONTEXT, $state->current_milestone);
        $this->assertSame(0, $state->cycle_count);
        $this->assertSame([], $state->active_work_packets);
        $this->assertSame([], $state->completed_work_packets);
        $this->assertSame([], $state->blockers);
        $this->assertSame(64, strlen((string) $state->state_hash));
        $this->assertSame($intake->obra_title, $state->obra_title);
        $this->assertNotEmpty($state->milestone_progress);

        // 5 canonical milestones present in the progress projection.
        $progressIds = array_keys((array) $state->milestone_progress);
        sort($progressIds);
        $expected = ForgeIntakeCanon::CANONICAL_MILESTONES;
        sort($expected);
        $this->assertSame($expected, $progressIds);

        // First milestone is the active one.
        $designProgress = $state->milestone_progress[ForgeIntakeCanon::MILESTONE_DESIGN_CONTEXT];
        $this->assertSame(ForgeLongHorizonStateCanon::MILESTONE_STATUS_ACTIVE, $designProgress['status']);

        // Next action points at evidence gathering for the active milestone.
        $this->assertSame(ForgeLongHorizonStateCanon::NEXT_ACTION_ATTACH_EVIDENCE, $state->next_action['kind']);
        $this->assertSame(ForgeIntakeCanon::MILESTONE_DESIGN_CONTEXT, $state->next_action['target']);
    }

    public function test_initialize_refuses_second_state_for_same_intake(): void
    {
        $intake = $this->readyIntake();
        $this->service->initializeForIntake($intake);

        $this->expectException(ForgeLongHorizonException::class);
        $this->service->initializeForIntake($intake);
    }

    public function test_blocked_intake_yields_blocked_state_with_intake_blocker_and_resolve_next_action(): void
    {
        $intake = app(ForgeIntakeService::class)->intakeFromPrompt('palavra aleatoria sem acao concreta');
        $this->assertSame(ForgeIntakeCanon::STATUS_BLOCKED, $intake->status, 'sanity: intake must be blocked');

        $state = $this->service->initializeForIntake($intake);

        $this->assertSame(ForgeLongHorizonStateCanon::STATUS_BLOCKED, $state->status);
        $this->assertNull($state->current_milestone);
        $this->assertCount(1, $state->blockers);
        $this->assertSame(ForgeLongHorizonStateCanon::BLOCKER_SCOPE_INTAKE, $state->blockers[0]['scope']);
        $this->assertSame($intake->id, $state->blockers[0]['target']);
        $this->assertFalse($state->blockers[0]['resolved']);
        $this->assertSame(ForgeLongHorizonStateCanon::NEXT_ACTION_RESOLVE_INTAKE_BLOCKER, $state->next_action['kind']);
    }

    public function test_record_cycle_attaches_evidence_and_updates_next_action(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);

        $cycle = [
            'cycle_id' => 'cyc-1',
            'evidence_refs' => [
                ['kind' => 'plan', 'ref' => 'spec://plan-1', 'hash' => str_repeat('a', 64)],
            ],
            'notes' => 'attached canonical plan',
        ];

        $state = $this->service->recordCycle($state, $cycle);

        $this->assertSame(1, $state->cycle_count);
        $this->assertCount(1, $state->evidence_refs);
        $this->assertSame('plan', $state->evidence_refs[0]['kind']);
        $this->assertNotNull($state->last_cycle_summary);
        $this->assertSame('cyc-1', $state->last_cycle_summary['cycle_id']);

        // Plan is present; context_pack still missing for design_context milestone,
        // so next action points at attach_evidence:context_pack.
        $this->assertSame(ForgeLongHorizonStateCanon::NEXT_ACTION_ATTACH_EVIDENCE, $state->next_action['kind']);
        $this->assertSame('context_pack', $state->next_action['evidence_kind']);
    }

    public function test_advance_milestone_without_evidence_blocks_with_explicit_reason(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);

        $result = $this->service->advanceMilestone($state);

        $this->assertFalse($result['advanced']);
        $this->assertSame(ForgeIntakeCanon::MILESTONE_DESIGN_CONTEXT, $result['from']);
        $this->assertNull($result['to']);
        $this->assertStringContainsString('milestone_gate_failed:'.ForgeIntakeCanon::MILESTONE_DESIGN_CONTEXT, (string) $result['blocker_reason']);

        $state->refresh();
        $this->assertSame(ForgeLongHorizonStateCanon::STATUS_BLOCKED, $state->status);
        $this->assertSame(ForgeIntakeCanon::MILESTONE_DESIGN_CONTEXT, $state->current_milestone);
        $this->assertCount(1, $state->blockers);
        $this->assertSame(ForgeLongHorizonStateCanon::BLOCKER_SCOPE_MILESTONE, $state->blockers[0]['scope']);
        $this->assertSame(ForgeIntakeCanon::MILESTONE_DESIGN_CONTEXT, $state->blockers[0]['target']);
        $this->assertSame(ForgeLongHorizonStateCanon::NEXT_ACTION_RESOLVE_BLOCKER, $state->next_action['kind']);
    }

    public function test_advance_milestone_when_evidence_complete_moves_forward(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);

        $state = $this->service->recordCycle($state, [
            'cycle_id' => 'cyc-evidence',
            'evidence_refs' => [
                ['kind' => 'plan', 'ref' => 'spec://plan-1', 'hash' => str_repeat('a', 64)],
                ['kind' => 'context_pack', 'ref' => 'cp://1', 'hash' => str_repeat('b', 64)],
            ],
        ]);

        $result = $this->service->advanceMilestone($state);

        $this->assertTrue($result['advanced']);
        $this->assertSame(ForgeIntakeCanon::MILESTONE_DESIGN_CONTEXT, $result['from']);
        $this->assertSame(ForgeIntakeCanon::MILESTONE_IMPLEMENTATION, $result['to']);

        $state->refresh();
        $this->assertSame(ForgeIntakeCanon::MILESTONE_IMPLEMENTATION, $state->current_milestone);
        $this->assertSame(
            ForgeLongHorizonStateCanon::MILESTONE_STATUS_COMPLETED,
            $state->milestone_progress[ForgeIntakeCanon::MILESTONE_DESIGN_CONTEXT]['status'],
        );
        $this->assertSame(
            ForgeLongHorizonStateCanon::MILESTONE_STATUS_ACTIVE,
            $state->milestone_progress[ForgeIntakeCanon::MILESTONE_IMPLEMENTATION]['status'],
        );
    }

    public function test_complete_obra_without_any_progress_throws_long_horizon_exception(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);

        $this->expectException(ForgeLongHorizonException::class);
        // Either the "milestones not completed" guard OR the "missing
        // certification evidence" guard MUST fire — we accept both messages,
        // we just refuse silent completion.
        $this->service->completeObra($state);
    }

    public function test_complete_obra_after_walking_milestones_but_without_certification_evidence_throws(): void
    {
        // Walk through design_context, implementation, verification, docs but
        // STOP before attaching certification evidence. We're already inside
        // the certification milestone — the only thing missing is the
        // certification evidence ref. completeObra() must refuse with the
        // specific "without certification evidence" guard.
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

        $this->assertSame(ForgeIntakeCanon::MILESTONE_CERTIFICATION, $state->current_milestone);

        $this->expectException(ForgeLongHorizonException::class);
        $this->expectExceptionMessage('without certification evidence');
        $this->service->completeObra($state);
    }

    public function test_complete_obra_refuses_when_called_before_advancing_through_all_milestones(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);

        $state = $this->service->recordCycle($state, [
            'cycle_id' => 'cyc-evidence-only-cert',
            'evidence_refs' => [
                ['kind' => 'certification', 'ref' => 'cert://x', 'hash' => str_repeat('c', 64)],
            ],
        ]);

        // certification evidence present but design_context, implementation,
        // verification and docs milestones were never advanced (their gates
        // were never evaluated green). completeObra must refuse — the DoD slot
        // `all_canonical_milestones_completed` is not satisfied.
        $this->expectException(ForgeLongHorizonException::class);
        $this->service->completeObra($state);
    }

    public function test_complete_obra_succeeds_after_walking_through_every_milestone(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);

        // design_context
        $state = $this->service->recordCycle($state, [
            'evidence_refs' => [
                ['kind' => 'plan', 'ref' => 'spec://p'],
                ['kind' => 'context_pack', 'ref' => 'cp://c'],
            ],
        ]);
        $result = $this->service->advanceMilestone($state);
        $this->assertTrue($result['advanced']);
        $state = $state->refresh();
        $this->assertSame(ForgeIntakeCanon::MILESTONE_IMPLEMENTATION, $state->current_milestone);

        // implementation
        $state = $this->service->recordCycle($state, [
            'evidence_refs' => [
                ['kind' => 'reservations', 'ref' => 'res://1'],
                ['kind' => 'permissions', 'ref' => 'perm://1'],
                ['kind' => 'work_packet_receipts', 'ref' => 'wpr://1'],
            ],
            'completed_work_packets' => ['wp-001'],
        ]);
        $result = $this->service->advanceMilestone($state);
        $this->assertTrue($result['advanced'], 'implementation gate should pass with reservations+permissions evidence');
        $state = $state->refresh();
        $this->assertSame(ForgeIntakeCanon::MILESTONE_VERIFICATION, $state->current_milestone);

        // verification
        $state = $this->service->recordCycle($state, [
            'evidence_refs' => [
                ['kind' => 'verification_receipt', 'ref' => 'vr://1'],
            ],
        ]);
        $result = $this->service->advanceMilestone($state);
        $this->assertTrue($result['advanced']);
        $state = $state->refresh();
        $this->assertSame(ForgeIntakeCanon::MILESTONE_DOCS, $state->current_milestone);

        // docs
        $state = $this->service->recordCycle($state, [
            'evidence_refs' => [
                ['kind' => 'evidence_pack', 'ref' => 'ep://1'],
            ],
        ]);
        $result = $this->service->advanceMilestone($state);
        $this->assertTrue($result['advanced']);
        $state = $state->refresh();
        $this->assertSame(ForgeIntakeCanon::MILESTONE_CERTIFICATION, $state->current_milestone);

        // certification
        $state = $this->service->recordCycle($state, [
            'evidence_refs' => [
                ['kind' => 'certification', 'ref' => 'cert://final'],
            ],
        ]);
        $this->assertSame(
            ForgeLongHorizonStateCanon::NEXT_ACTION_COMPLETE_OBRA,
            $state->next_action['kind'],
            'next action must point at complete_obra once certification evidence is present',
        );

        $completed = $this->service->completeObra($state);
        $this->assertSame(ForgeLongHorizonStateCanon::STATUS_COMPLETED, $completed->status);
        $this->assertNull($completed->current_milestone);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame(
            ForgeLongHorizonStateCanon::NEXT_ACTION_OBRA_COMPLETED,
            $completed->next_action['kind'],
        );
        $this->assertSame(
            ForgeLongHorizonStateCanon::MILESTONE_STATUS_COMPLETED,
            $completed->milestone_progress[ForgeIntakeCanon::MILESTONE_CERTIFICATION]['status'],
        );
    }

    public function test_complete_obra_twice_throws(): void
    {
        $state = $this->walkAllMilestonesToCertification();
        $this->service->completeObra($state);

        $this->expectException(ForgeLongHorizonException::class);
        $this->expectExceptionMessage('already completed');
        $this->service->completeObra($state->refresh());
    }

    public function test_blocker_registration_and_resolution_round_trip(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);

        $state = $this->service->recordCycle($state, [
            'cycle_id' => 'cyc-block',
            'blockers' => [
                [
                    'scope' => ForgeLongHorizonStateCanon::BLOCKER_SCOPE_PACKET,
                    'target' => 'wp-001',
                    'reason' => 'provider_timeout',
                ],
            ],
        ]);

        $this->assertSame(ForgeLongHorizonStateCanon::STATUS_BLOCKED, $state->status);
        $this->assertCount(1, $state->blockers);
        $this->assertSame('provider_timeout', $state->blockers[0]['reason']);
        $this->assertFalse($state->blockers[0]['resolved']);
        $this->assertSame(ForgeLongHorizonStateCanon::NEXT_ACTION_RESOLVE_BLOCKER, $state->next_action['kind']);

        // Resolve the blocker
        $state = $this->service->recordCycle($state, [
            'cycle_id' => 'cyc-unblock',
            'resolve_blockers' => ['provider_timeout'],
        ]);

        $this->assertSame(ForgeLongHorizonStateCanon::STATUS_ACTIVE, $state->status);
        $resolved = collect($state->blockers)->where('reason', 'provider_timeout')->first();
        $this->assertTrue($resolved['resolved']);
    }

    public function test_next_action_advances_milestone_when_design_context_gates_green(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);

        $state = $this->service->recordCycle($state, [
            'evidence_refs' => [
                ['kind' => 'plan', 'ref' => 'spec://plan-1'],
                ['kind' => 'context_pack', 'ref' => 'cp://1'],
            ],
        ]);

        $this->assertSame(ForgeLongHorizonStateCanon::NEXT_ACTION_ADVANCE_MILESTONE, $state->next_action['kind']);
        $this->assertSame(ForgeIntakeCanon::MILESTONE_DESIGN_CONTEXT, $state->next_action['target']);
    }

    public function test_work_packet_transitions_active_to_completed(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);

        $state = $this->service->recordCycle($state, [
            'active_work_packets' => ['wp-001', 'wp-002'],
        ]);
        $this->assertSame(['wp-001', 'wp-002'], $state->active_work_packets);

        $state = $this->service->recordCycle($state, [
            'completed_work_packets' => ['wp-001'],
        ]);

        $this->assertSame(['wp-002'], $state->active_work_packets);
        $this->assertSame(['wp-001'], $state->completed_work_packets);
    }

    public function test_continuation_context_hash_is_persisted(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);

        $hash = str_repeat('d', 64);
        $state = $this->service->recordCycle($state, [
            'continuation_context_hash' => $hash,
        ]);

        $this->assertSame($hash, $state->continuation_context_hash);
    }

    public function test_canonical_state_projection_serializes_to_stable_json(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);
        $state = $this->service->recordCycle($state, [
            'evidence_refs' => [
                ['kind' => 'plan', 'ref' => 'spec://plan-x'],
            ],
        ]);

        $projection = $state->toCanonicalArray();
        $json = json_encode($projection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json, 'canonical projection must be json encodable');
        $decoded = json_decode((string) $json, true);
        $this->assertSame(ForgeLongHorizonStateCanon::SCHEMA_VERSION, $decoded['schema']);
        $this->assertSame($state->intake_id, $decoded['obra_id']);
        $this->assertSame($state->state_hash, $decoded['state_hash']);
        // Recomputing the state_hash from canonical payload yields the stored
        // value (determinism).
        $recomputed = MissionCanonicalHash::sha256([
            'schema' => ForgeLongHorizonStateCanon::SCHEMA_VERSION,
            'uuid' => $state->uuid,
            'intake_id' => $state->intake_id,
            'status' => $state->status,
            'current_milestone' => $state->current_milestone,
            'milestone_progress' => (array) $state->milestone_progress,
            'active_work_packets' => array_values((array) $state->active_work_packets),
            'completed_work_packets' => array_values((array) $state->completed_work_packets),
            'blockers' => array_values((array) $state->blockers),
            'evidence_refs' => array_values((array) $state->evidence_refs),
            'next_action' => (array) $state->next_action,
            'cycle_count' => (int) $state->cycle_count,
            'continuation_context_hash' => $state->continuation_context_hash,
            'blocker_reason' => $state->blocker_reason,
            'completed_at' => $state->completed_at?->toISOString(),
        ]);
        $this->assertSame($state->state_hash, $recomputed);
    }

    public function test_gate_runner_returns_structured_result_for_design_context(): void
    {
        $intake = $this->readyIntake();
        $state = $this->service->initializeForIntake($intake);
        $design = $intake->milestones()
            ->where('milestone_id', ForgeIntakeCanon::MILESTONE_DESIGN_CONTEXT)
            ->firstOrFail();

        /** @var ForgeMilestoneGateRunner $runner */
        $runner = app(ForgeMilestoneGateRunner::class);
        $result = $runner->evaluate($intake, $design, $state);

        $this->assertSame(ForgeIntakeCanon::MILESTONE_DESIGN_CONTEXT, $result['milestone_id']);
        $this->assertFalse($result['all_passed'], 'no evidence yet -> gates cannot all pass');
        $this->assertContains('plan', $result['evidence_missing']);
        $this->assertContains('context_pack', $result['evidence_missing']);
    }

    private function readyIntake(): AiForgeIntake
    {
        return app(ForgeIntakeService::class)->intakeFromPrompt(
            'Implementar refactor multi-modulo do provider router com sdd e multi-agent fallback.',
            ['workspace_slug' => 'atlas-server'],
        );
    }

    private function walkAllMilestonesToCertification(): AiForgeLongHorizonState
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

        return $this->service->recordCycle($state, [
            'evidence_refs' => [['kind' => 'certification', 'ref' => 'cert://final']],
        ]);
    }
}
