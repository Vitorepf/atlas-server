<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\LongHorizon;

use App\Models\AiForgeIntake;
use App\Models\AiForgeMilestone;
use App\Models\AiForgeWorkPacket;
use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\LongHorizon\OperatorAttentionQueueService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\Concerns\CreatesForgeLongHorizonStateTable;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

class OperatorAttentionQueueServiceTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;
    use CreatesForgeLongHorizonStateTable;
    use CreatesLongHorizonPersistenceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasMemoryEntryTable();
        $this->createForgeLongHorizonStateTable();
        $this->createLongHorizonPersistenceTables();
    }

    public function test_secret_memory_creates_critical_operator_attention_item_without_deleting_memory(): void
    {
        $memory = $this->memory('sensitive', [
            'privacy_class' => 'secret',
            'body' => 'raw secret body must not leak',
        ]);

        $payload = $this->service()->build([
            'now' => CarbonImmutable::parse('2026-05-19T12:00:00Z'),
        ]);

        $this->assertSame(AtlasLongHorizonCanon::OPERATOR_ATTENTION_QUEUE_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(OperatorAttentionQueueService::STATUS_BLOCKED, $payload['status']);
        $this->assertSame(1, $payload['summary']['by_severity']['critical']);
        $this->assertSame('strategic_forgetting', $payload['items'][0]['source']);
        $this->assertSame('review_memory_forgetting_policy', $payload['items'][0]['recommended_action']);
        $this->assertDatabaseHas('atlas_memory_entries', ['id' => $memory->id]);
        $this->assertStringNotContainsString('raw secret body must not leak', json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function test_monthly_obra_review_creates_decision_and_stale_attention_items(): void
    {
        $intake = $this->intake(CarbonImmutable::parse('2026-04-01T00:00:00Z'));
        $packet = $this->workPacket($intake, 'wp-1', 'completed');
        $this->cycle($intake, $packet, 'completed', []);

        $payload = $this->service()->build([
            'intake' => $intake->uuid,
            'now' => CarbonImmutable::parse('2026-05-19T12:00:00Z'),
        ]);

        $this->assertSame(OperatorAttentionQueueService::STATUS_WATCH, $payload['status']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['by_source']['obra_review']);
        $this->assertContains('choose_monthly_obra_decision', array_column($payload['items'], 'recommended_action'));
        $this->assertContains('refresh_obra_context', array_column($payload['items'], 'recommended_action'));
        $this->assertTrue($payload['claim_policy']['does_not_mutate_obra']);
    }

    public function test_obra_blocker_is_high_attention_item(): void
    {
        $intake = $this->intake(CarbonImmutable::parse('2026-05-01T00:00:00Z'));
        $this->milestone($intake, 'm1', 'blocked');

        $payload = $this->service()->build([
            'intake' => $intake->uuid,
            'now' => CarbonImmutable::parse('2026-05-19T12:00:00Z'),
        ]);

        $items = collect($payload['items']);

        $this->assertSame(OperatorAttentionQueueService::STATUS_WATCH, $payload['status']);
        $this->assertNotNull($items->firstWhere('recommended_action', 'resolve_obra_blocker'));
        $this->assertSame(1, $payload['summary']['by_severity']['high']);
    }

    public function test_continuity_blocker_is_critical_when_scope_cannot_resume(): void
    {
        $payload = $this->service()->build([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_OBRA,
            'scope_id' => 'obra-1',
            'now' => CarbonImmutable::parse('2026-05-19T12:00:00Z'),
        ]);

        $this->assertSame(OperatorAttentionQueueService::STATUS_BLOCKED, $payload['status']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['by_source']['continuity_certification']);
        $this->assertContains('repair_continuity_before_resume', array_column($payload['items'], 'recommended_action'));
    }

    public function test_dedupe_collapses_duplicate_attention_items(): void
    {
        $intake = $this->intake(CarbonImmutable::parse('2026-05-01T00:00:00Z'));
        $packet = $this->workPacket($intake, 'wp-1', 'in_progress');
        $this->cycle($intake, $packet, 'failed', []);
        $this->cycle($intake, $packet, 'failed', []);

        $payload = $this->service()->build([
            'intake' => $intake->uuid,
            'now' => CarbonImmutable::parse('2026-05-19T12:00:00Z'),
        ]);

        $dedupeKeys = array_column($payload['items'], 'dedupe_key');

        $this->assertSame(count($dedupeKeys), count(array_unique($dedupeKeys)));
    }

    public function test_hash_is_deterministic_for_same_content(): void
    {
        $now = CarbonImmutable::parse('2026-05-19T12:00:00Z');
        $this->memory('sensitive', ['privacy_class' => 'secret']);

        $a = $this->service()->build(['now' => $now]);
        $b = $this->service()->build(['now' => $now]);

        $this->assertSame($a['queue_hash'], $b['queue_hash']);
    }

    public function test_command_emits_json(): void
    {
        $this->memory('sensitive', ['privacy_class' => 'secret']);

        $exit = Artisan::call('atlas:long-horizon:attention-queue', ['--json' => true]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(AtlasLongHorizonCanon::OPERATOR_ATTENTION_QUEUE_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(OperatorAttentionQueueService::STATUS_BLOCKED, $payload['status']);
    }

    private function service(): OperatorAttentionQueueService
    {
        return app(OperatorAttentionQueueService::class);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function memory(string $title, array $overrides = []): AtlasMemoryEntry
    {
        $now = CarbonImmutable::parse('2026-05-19T12:00:00Z');

        return AtlasMemoryEntry::query()->create(array_merge([
            'memory_type' => 'technical_context',
            'scope_type' => 'global',
            'scope_id' => null,
            'title' => $title,
            'body' => 'redacted body for '.$title,
            'summary' => 'summary',
            'importance' => 3,
            'priority' => 50,
            'confidence' => 0.9,
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'source_type' => 'test',
            'source_id' => sha1($title),
            'source_label' => 'test',
            'status' => 'active',
            'tags' => [],
            'metadata' => [],
            'recorded_at' => $now,
            'last_used_at' => $now,
            'authority_level' => 'verified',
        ], $overrides));
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
