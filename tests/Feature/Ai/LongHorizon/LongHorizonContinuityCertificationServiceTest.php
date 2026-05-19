<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\LongHorizon;

use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Models\AtlasLongHorizonContinuationPack;
use App\Models\AtlasLongHorizonReplayManifest;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\LongHorizon\LongHorizonContinuityCertificationService;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

/**
 * TEOS-I2 M10 · Continuity Certification feature certification.
 *
 * Composes existing TEOS-I1 primitives (continuation pack, compaction
 * receipt, freshness gate, recovery planner, replay manifest) and asserts:
 *   - ready when pack + freshness pass + coverage = 1.0;
 *   - blocked when continuation pack missing;
 *   - blocked when must_keep_coverage < 1.0;
 *   - partial (warn) when replay manifest absent (P1);
 *   - blocked with P0 when --strict-replay and no manifest;
 *   - recovery plan required when freshness blocks;
 *   - hash deterministic over canonical content;
 *   - no raw operator_input / response_text leaks.
 */
class LongHorizonContinuityCertificationServiceTest extends TestCase
{
    use CreatesLongHorizonPersistenceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLongHorizonPersistenceTables();
    }

    protected function tearDown(): void
    {
        $this->dropLongHorizonPersistenceTables();
        parent::tearDown();
    }

    public function test_ready_when_pack_freshness_coverage_and_replay_all_green(): void
    {
        $pack = $this->createPack();
        $this->createCompaction($pack, mustKeepCoverage: 1.0);
        $this->createReplayManifest($pack);

        $report = $this->service()->certify([
            'scope_type' => $pack->scope_type,
            'scope_id' => $pack->scope_id,
        ]);

        $this->assertSame(LongHorizonContinuityCertificationService::SCHEMA_VERSION, $report['schema_version']);
        $this->assertSame(LongHorizonContinuityCertificationService::STATUS_READY, $report['status'], 'expected ready, got '.$report['status'].' blockers='.json_encode($report['blockers']));
        $this->assertSame([], $report['blockers']);
        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE, $report['safe_resume_mode']);
        $this->assertStringStartsWith('sha256:', $report['certification_hash']);
        $this->assertNotEmpty($report['evidence_refs']);
        $this->assertSame('not_required', $report['recovery_plan_status']);
    }

    public function test_blocked_without_continuation_pack(): void
    {
        $report = $this->service()->certify([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_MISSION,
            'scope_id' => 'missing-pack-scope',
        ]);

        $this->assertSame(LongHorizonContinuityCertificationService::STATUS_BLOCKED, $report['status']);
        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED, $report['safe_resume_mode']);
        $blockerIds = array_column($report['blockers'], 'check_id');
        $this->assertContains('continuation_pack_exists', $blockerIds);
        $this->assertGreaterThanOrEqual(1, $report['summary']['p0_blockers']);
    }

    public function test_blocked_when_must_keep_coverage_below_one(): void
    {
        $pack = $this->createPack();
        $this->createCompaction($pack, mustKeepCoverage: 0.85, lossRisk: AtlasLongHorizonCanon::LOSS_RISK_HIGH);

        $report = $this->service()->certify([
            'scope_type' => $pack->scope_type,
            'scope_id' => $pack->scope_id,
        ]);

        $this->assertSame(LongHorizonContinuityCertificationService::STATUS_BLOCKED, $report['status']);
        $blockerIds = array_column($report['blockers'], 'check_id');
        $this->assertContains('compaction_coverage_full', $blockerIds);
    }

    public function test_partial_warn_when_replay_manifest_absent_non_strict(): void
    {
        $pack = $this->createPack();
        $this->createCompaction($pack, mustKeepCoverage: 1.0);
        // no replay manifest

        $report = $this->service()->certify([
            'scope_type' => $pack->scope_type,
            'scope_id' => $pack->scope_id,
        ]);

        $this->assertSame(LongHorizonContinuityCertificationService::STATUS_PARTIAL, $report['status']);
        $warnIds = array_column($report['warnings'], 'check_id');
        $this->assertContains('replay_manifest_available', $warnIds);
        $this->assertSame('missing', $report['replay_manifest_status']);
    }

    public function test_blocked_when_replay_manifest_absent_and_strict_replay(): void
    {
        $pack = $this->createPack();
        $this->createCompaction($pack, mustKeepCoverage: 1.0);

        $report = $this->service()->certify([
            'scope_type' => $pack->scope_type,
            'scope_id' => $pack->scope_id,
            'strict_replay_required' => true,
        ]);

        $this->assertSame(LongHorizonContinuityCertificationService::STATUS_BLOCKED, $report['status']);
        $blockerIds = array_column($report['blockers'], 'check_id');
        $this->assertContains('replay_manifest_available', $blockerIds);
    }

    public function test_recovery_plan_required_when_freshness_blocks_via_stale_pack(): void
    {
        // Pack with stale_after in the past triggers freshness 'blocked'.
        $pack = $this->createPack([
            'stale_after' => now()->subHours(2),
        ]);
        $this->createCompaction($pack, mustKeepCoverage: 1.0);
        $this->createReplayManifest($pack);

        $report = $this->service()->certify([
            'scope_type' => $pack->scope_type,
            'scope_id' => $pack->scope_id,
        ]);

        $this->assertSame(LongHorizonContinuityCertificationService::STATUS_BLOCKED, $report['status']);
        $blockerIds = array_column($report['blockers'], 'check_id');
        $this->assertContains('freshness_gate', $blockerIds);
        // Recovery plan must be available (not missing) — composes correctly.
        $this->assertSame('available', $report['recovery_plan_status']);
        $this->assertNotNull($report['recovery_plan']);
    }

    public function test_blocked_when_evidence_refs_empty(): void
    {
        $pack = $this->createPack(['evidence_refs' => []]);
        $this->createCompaction($pack, mustKeepCoverage: 1.0);
        $this->createReplayManifest($pack);

        $report = $this->service()->certify([
            'scope_type' => $pack->scope_type,
            'scope_id' => $pack->scope_id,
        ]);

        $this->assertSame(LongHorizonContinuityCertificationService::STATUS_BLOCKED, $report['status']);
        $blockerIds = array_column($report['blockers'], 'check_id');
        $this->assertContains('evidence_present_for_completion', $blockerIds);
    }

    public function test_blocked_when_required_evidence_kind_missing(): void
    {
        $pack = $this->createPack([
            'evidence_refs' => [
                ['kind' => 'artifact', 'ref' => 'a:1'],
            ],
        ]);
        $this->createCompaction($pack, mustKeepCoverage: 1.0);
        $this->createReplayManifest($pack);

        $report = $this->service()->certify([
            'scope_type' => $pack->scope_type,
            'scope_id' => $pack->scope_id,
            'required_evidence_kinds' => ['test', 'command'],
        ]);

        $this->assertSame(LongHorizonContinuityCertificationService::STATUS_BLOCKED, $report['status']);
        $blockerIds = array_column($report['blockers'], 'check_id');
        $this->assertContains('evidence_present_for_completion', $blockerIds);
    }

    public function test_hash_is_deterministic_for_same_content(): void
    {
        $pack = $this->createPack();
        $this->createCompaction($pack, mustKeepCoverage: 1.0);
        $this->createReplayManifest($pack);

        $report1 = $this->service()->certify(['scope_type' => $pack->scope_type, 'scope_id' => $pack->scope_id]);
        $report2 = $this->service()->certify(['scope_type' => $pack->scope_type, 'scope_id' => $pack->scope_id]);

        $this->assertSame($report1['certification_hash'], $report2['certification_hash'], 'same content must produce identical hash');
        $this->assertNotSame($report1['generated_at'], $report2['generated_at'], 'generated_at differs but is excluded from hash');
    }

    public function test_no_raw_text_leaks_through_certification_payload(): void
    {
        $pack = $this->createPack([
            'objective' => 'SECRET RAW OBJECTIVE — must never leak',
            'state_summary' => 'SECRET RAW STATE SUMMARY',
        ]);
        $this->createCompaction($pack, mustKeepCoverage: 1.0);
        $this->createReplayManifest($pack);

        $report = $this->service()->certify([
            'scope_type' => $pack->scope_type,
            'scope_id' => $pack->scope_id,
        ]);

        $serialized = json_encode($report);
        $this->assertStringNotContainsString('SECRET RAW OBJECTIVE', $serialized);
        $this->assertStringNotContainsString('SECRET RAW STATE SUMMARY', $serialized);
    }

    public function test_command_strict_exits_failure_when_not_ready(): void
    {
        // No pack at all → blocked → strict exits 1.
        $exit = $this->artisan('atlas:long-horizon:continuity-certify', [
            '--scope-type' => 'mission',
            '--scope-id' => 'absent',
            '--strict' => true,
            '--json' => true,
        ])->run();

        $this->assertSame(1, $exit);
    }

    public function test_command_exits_success_when_ready(): void
    {
        $pack = $this->createPack();
        $this->createCompaction($pack, mustKeepCoverage: 1.0);
        $this->createReplayManifest($pack);

        $exit = $this->artisan('atlas:long-horizon:continuity-certify', [
            '--scope-type' => $pack->scope_type,
            '--scope-id' => $pack->scope_id,
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit);
    }

    public function test_command_exits_failure_when_blocked(): void
    {
        $exit = $this->artisan('atlas:long-horizon:continuity-certify', [
            '--scope-type' => 'mission',
            '--scope-id' => 'no-pack-here',
            '--json' => true,
        ])->run();

        $this->assertSame(1, $exit);
    }

    public function test_command_emits_error_when_scope_type_missing(): void
    {
        $exit = $this->artisan('atlas:long-horizon:continuity-certify', ['--json' => true])->run();
        $this->assertSame(1, $exit);
    }

    private function service(): LongHorizonContinuityCertificationService
    {
        return $this->app->make(LongHorizonContinuityCertificationService::class);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createPack(array $overrides = []): AtlasLongHorizonContinuationPack
    {
        $defaults = [
            'id' => (string) Str::uuid(),
            'schema_version' => 'atlas.long_horizon.continuation_pack.v2',
            'uuid' => 'pack-'.Str::random(20),
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_MISSION,
            'scope_id' => (string) Str::uuid(),
            'objective' => 'Ship feature X with TEOS guarantees.',
            'current_phase' => 'execute',
            'state_summary' => 'baseline ready',
            'decisions' => [],
            'superseded_decisions' => [],
            'open_tasks' => [],
            'completed_tasks' => [],
            'blockers' => [],
            'risks' => [],
            'evidence_refs' => [
                ['kind' => 'artifact', 'ref' => 'artifact:1'],
                ['kind' => 'test', 'ref' => 'test:1'],
            ],
            'context_manifest' => [],
            'context_pack_hash' => str_repeat('a', 64),
            'summary_hash' => str_repeat('b', 64),
            'source_receipts' => [],
            'stale_after' => null,
            'safe_resume_mode' => AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
            'next_safe_action' => 'continue_with_next_stage',
            'human_decisions_required' => [],
            'confidence' => 0.85,
            'pack_hash' => str_repeat('c', 64),
        ];

        return AtlasLongHorizonContinuationPack::query()->create(array_merge($defaults, $overrides));
    }

    private function createCompaction(
        AtlasLongHorizonContinuationPack $pack,
        float $mustKeepCoverage,
        string $lossRisk = AtlasLongHorizonCanon::LOSS_RISK_LOW,
    ): AtlasLongHorizonCompactionReceipt {
        return AtlasLongHorizonCompactionReceipt::query()->create([
            'id' => (string) Str::uuid(),
            'schema_version' => 'atlas.long_horizon.compaction_receipt.v1',
            'uuid' => 'compaction-'.Str::random(20),
            'scope_type' => $pack->scope_type,
            'scope_id' => $pack->scope_id,
            'source_context_refs' => [],
            'retained_items' => [],
            'discarded_items' => [],
            'discarded_reason' => null,
            'must_keep_items' => [],
            'must_keep_coverage' => $mustKeepCoverage,
            'unresolved_loss' => $mustKeepCoverage < 1.0 ? [['kind' => 'decision', 'ref' => 'lost-1']] : [],
            'loss_risk' => $lossRisk,
            'recovery_queries' => [],
            'evidence_refs' => [],
            'summary_hash' => str_repeat('d', 64),
            'quality_score' => 0.9,
            'detected_contradictions' => [],
            'stale_risks' => [],
            'receipt_hash' => str_repeat('e', 64),
        ]);
    }

    private function createReplayManifest(AtlasLongHorizonContinuationPack $pack): AtlasLongHorizonReplayManifest
    {
        return AtlasLongHorizonReplayManifest::query()->create([
            'id' => (string) Str::uuid(),
            'schema_version' => 'atlas.long_horizon.replay_manifest.v1',
            'uuid' => 'replay-'.Str::random(20),
            'scope_type' => $pack->scope_type,
            'scope_id' => $pack->scope_id,
            'continuation_pack_id' => $pack->id,
            'compaction_receipt_id' => null,
            'required_refs' => [],
            'available_refs' => [],
            'missing_refs' => [],
            'event_refs' => [],
            'evidence_refs' => [],
            'context_pack_hash' => $pack->context_pack_hash,
            'reader_instructions' => 'replay reader notes',
            'provider_independent_summary' => 'summary',
            'safety_notes' => [],
            'replay_status' => 'ready',
            'hash' => str_repeat('f', 64),
        ]);
    }
}
