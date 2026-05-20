<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\LongHorizon;

use App\Models\AiForgeIntake;
use App\Models\AiForgeMilestone;
use App\Models\AiForgeWorkPacket;
use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\LongHorizon\ObraReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesForgeLongHorizonStateTable;
use Tests\TestCase;

class ObraReviewServiceTest extends TestCase
{
    use CreatesForgeLongHorizonStateTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createForgeLongHorizonStateTable();
    }

    public function test_recent_obra_emits_weekly_synthesis(): void
    {
        $intake = $this->intake(CarbonImmutable::parse('2026-05-10T00:00:00Z'));
        $this->workPacket($intake, 'wp-1', 'in_progress');

        $payload = (new ObraReviewService)->review([
            'intake' => $intake->uuid,
            'now' => CarbonImmutable::parse('2026-05-19T00:00:00Z'),
        ]);

        $this->assertSame(AtlasLongHorizonCanon::OBRA_REVIEW_RECEIPT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(ObraReviewService::STATUS_READY, $payload['status']);
        $this->assertSame(AtlasLongHorizonCanon::OBRA_REVIEW_WEEKLY_SYNTHESIS, $payload['review_kind']);
        $this->assertFalse($payload['monthly_architecture_review']['required']);
        $this->assertTrue($payload['claim_policy']['advisory_only']);
    }

    public function test_old_obra_requires_monthly_architecture_review(): void
    {
        $intake = $this->intake(CarbonImmutable::parse('2026-04-01T00:00:00Z'));
        $this->milestone($intake, 'm1', 'blocked');
        $this->workPacket($intake, 'wp-1', 'in_progress');

        $payload = (new ObraReviewService)->review([
            'intake' => $intake->uuid,
            'now' => CarbonImmutable::parse('2026-05-19T00:00:00Z'),
        ]);

        $this->assertSame(AtlasLongHorizonCanon::OBRA_REVIEW_MONTHLY_ARCHITECTURE, $payload['review_kind']);
        $this->assertTrue($payload['monthly_architecture_review']['required']);
        $this->assertTrue($payload['monthly_architecture_review']['operator_decision_required']);
        $this->assertSame(AtlasLongHorizonCanon::OBRA_REVIEW_DECISION_PAUSE, $payload['monthly_architecture_review']['recommended_decision']);
        $this->assertSame(1, $payload['summary']['blockers_count']);
    }

    public function test_completed_cycle_without_evidence_becomes_stale_risk(): void
    {
        $intake = $this->intake(CarbonImmutable::parse('2026-04-01T00:00:00Z'));
        $packet = $this->workPacket($intake, 'wp-1', 'completed');
        $this->cycle($intake, $packet, 'completed', []);

        $payload = (new ObraReviewService)->review([
            'intake' => $intake->uuid,
            'now' => CarbonImmutable::parse('2026-05-19T00:00:00Z'),
        ]);

        $this->assertSame(2, $payload['summary']['stale_risks_count']);
        $this->assertSame(
            AtlasLongHorizonCanon::OBRA_REVIEW_DECISION_ADJUST_SCOPE,
            $payload['monthly_architecture_review']['recommended_decision'],
        );
    }

    public function test_hash_is_deterministic(): void
    {
        $now = CarbonImmutable::parse('2026-05-19T00:00:00Z');
        $intake = $this->intake(CarbonImmutable::parse('2026-05-01T00:00:00Z'));

        $a = (new ObraReviewService)->review(['intake' => $intake->uuid, 'now' => $now]);
        $b = (new ObraReviewService)->review(['intake' => $intake->uuid, 'now' => $now]);

        $this->assertSame($a['review_hash'], $b['review_hash']);
    }

    public function test_missing_intake_blocks(): void
    {
        $payload = (new ObraReviewService)->review(['intake' => 'missing']);

        $this->assertSame(ObraReviewService::STATUS_BLOCKED, $payload['status']);
        $this->assertSame(['forge intake not found'], $payload['blockers']);
    }

    public function test_command_emits_json(): void
    {
        $this->intake(CarbonImmutable::parse('2026-05-01T00:00:00Z'));

        $exit = Artisan::call('atlas:long-horizon:obra-review', ['--json' => true]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(AtlasLongHorizonCanon::OBRA_REVIEW_RECEIPT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(ObraReviewService::STATUS_READY, $payload['status']);
    }

    private function intake(CarbonImmutable $createdAt): AiForgeIntake
    {
        $intake = AiForgeIntake::query()->create([
            'schema_version' => 'atlas.forge.intake.v1',
            'uuid' => (string) str()->uuid(),
            'origin' => 'test',
            'recommended_forge_mode' => 'obra',
            'obra_title' => 'Obra TEOS',
            'workspace_slug' => 'atlas',
            'original_user_intent' => 'redacted',
            'risk_band' => 'high',
            'definition_of_done' => ['done'],
            'required_evidence' => ['test'],
            'status' => 'ready',
            'intake_hash' => hash('sha256', (string) str()->uuid()),
            'actor_type' => 'system',
        ]);
        $intake->timestamps = false;
        $intake->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $intake->refresh();
    }

    private function milestone(AiForgeIntake $intake, string $id, string $status): AiForgeMilestone
    {
        return AiForgeMilestone::query()->create([
            'schema_version' => 'atlas.forge.milestone.v1',
            'uuid' => (string) str()->uuid(),
            'intake_id' => $intake->id,
            'position' => 1,
            'milestone_id' => $id,
            'title' => $id,
            'expected_artifacts' => ['artifact'],
            'required_gates' => ['gate'],
            'required_evidence' => ['evidence'],
            'status' => $status,
            'blocker_reason' => $status === 'blocked' ? 'blocked' : null,
            'milestone_hash' => hash('sha256', $id),
        ]);
    }

    private function workPacket(AiForgeIntake $intake, string $id, string $status): AiForgeWorkPacket
    {
        return AiForgeWorkPacket::query()->create([
            'schema_version' => 'atlas.forge.work_packet.v1',
            'uuid' => (string) str()->uuid(),
            'intake_id' => $intake->id,
            'packet_position' => 1,
            'packet_id' => $id,
            'title' => $id,
            'objective' => 'objective',
            'acceptance_criteria' => ['accept'],
            'required_evidence' => ['evidence'],
            'status' => $status,
            'risk_band' => 'medium',
            'packet_hash' => hash('sha256', $id),
        ]);
    }

    /**
     * @param  array<int,string>  $evidenceRefs
     */
    private function cycle(AiForgeIntake $intake, AiForgeWorkPacket $packet, string $status, array $evidenceRefs): AiForgeWorkPacketExecutionCycle
    {
        return AiForgeWorkPacketExecutionCycle::query()->create([
            'schema_version' => 'atlas.forge.work_packet_execution_cycle.v1',
            'uuid' => (string) str()->uuid(),
            'intake_id' => $intake->id,
            'work_packet_id' => $packet->id,
            'work_packet_canonical_id' => $packet->packet_id,
            'cycle_position' => 1,
            'execution_mode' => 'dry_run',
            'status' => $status,
            'execution_plan' => ['step'],
            'expected_artifacts' => ['artifact'],
            'evidence_refs' => $evidenceRefs,
            'next_action' => ['continue'],
            'started_at' => CarbonImmutable::parse('2026-05-18T00:00:00Z'),
            'completed_at' => CarbonImmutable::parse('2026-05-18T01:00:00Z'),
            'cycle_hash' => hash('sha256', $packet->packet_id.$status),
        ]);
    }
}
