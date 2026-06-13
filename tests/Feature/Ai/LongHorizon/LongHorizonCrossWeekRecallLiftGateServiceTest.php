<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\LongHorizon;

use App\Models\AtlasLongHorizonContinuationPack;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\LongHorizon\LongHorizonCrossWeekRecallLiftGateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

/**
 * FROZEN gate proof for the L6-12 cross-week recall-lift gate.
 *
 * The gate proves the temporal DoD: an old memory ref (first captured >=3 weeks
 * ago, by REAL persisted created_at) recalled into a task TODAY, with a measured
 * A/B certification-rate lift. These tests prove the gate:
 *   1. auto-greens on REAL elapsed evidence (old + new packs sharing a ref);
 *   2. refuses to fabricate — days-old packs (the real current soak state) block
 *      with honest recall_age/calendar_span blockers and exit 1;
 *   3. discriminates lift (recall arm must beat the no-recall arm);
 *   4. is fail-closed on missing data;
 *   5. has its schedule + flags wired ON.
 *
 * The age is derived strictly from the persisted created_at, so the green-path
 * test SET the timestamps the same way real elapsed calendar time will — it does
 * NOT let the gate mint time.
 */
final class LongHorizonCrossWeekRecallLiftGateServiceTest extends TestCase
{
    use CreatesLongHorizonPersistenceTables;

    private const SCOPE_TYPE = 'long_horizon';

    private const SCOPE_ID = 'fable-l6-12-recall-test';

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

    public function test_gate_greens_when_old_memory_is_recalled_into_a_new_task_with_positive_lift(): void
    {
        $now = CarbonImmutable::parse('2026-07-10T12:00:00Z');

        // OLD pack persisted 24 days ago — carries the recalled ref.
        $this->persistPack(
            scopeId: self::SCOPE_ID,
            createdAt: $now->subDays(24),
            refs: ['docs/decision-3-weeks-ago.md', 'storage/app/atlas/evidence/old-artifact.json'],
            safeResumeMode: AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
        );

        // NEW task (today) that RECALLS the 24-day-old ref → recall arm, certified.
        $this->persistPack(
            scopeId: self::SCOPE_ID,
            createdAt: $now->subDays(1),
            refs: ['docs/decision-3-weeks-ago.md', 'storage/app/atlas/evidence/today.json'],
            safeResumeMode: AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
        );

        // NEW task (today) WITHOUT recall → no-recall arm, not certified.
        $this->persistPack(
            scopeId: self::SCOPE_ID,
            createdAt: $now->subDays(2),
            refs: ['docs/fresh-only.md'],
            safeResumeMode: AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED,
        );

        $payload = app(LongHorizonCrossWeekRecallLiftGateService::class)->evaluate([
            'scope_type' => self::SCOPE_TYPE,
            'scope_id' => self::SCOPE_ID,
            'now' => $now,
        ]);

        $this->assertSame(LongHorizonCrossWeekRecallLiftGateService::STATUS_READY, $payload['status']);
        $this->assertTrue($payload['certified']);
        $this->assertTrue($payload['completion_claim_allowed']);
        $this->assertSame([], $payload['blockers']);

        $m = $payload['measurement'];
        $this->assertGreaterThanOrEqual(21.0, (float) $m['max_recall_age_days']);
        $this->assertGreaterThanOrEqual(21.0, (float) $m['calendar_span_days']);
        $this->assertGreaterThanOrEqual(1, (int) $m['recall_event_count']);
        $this->assertSame(1, (int) $m['ab']['recall_arm_tasks']);
        $this->assertSame(1, (int) $m['ab']['no_recall_arm_tasks']);
        // Recall arm certified (1.0) vs no-recall arm not certified (0.0) → lift 1.0.
        $this->assertSame(1.0, (float) $m['ab']['recall_arm_certification_rate']);
        $this->assertSame(0.0, (float) $m['ab']['no_recall_arm_certification_rate']);
        $this->assertGreaterThan(0.0, (float) $m['ab']['recall_lift']);

        // Anti-fabrication invariants.
        $this->assertTrue($payload['claim_policy']['does_not_backfill_time']);
        $this->assertTrue($payload['claim_policy']['does_not_mint_recall_events']);
        $this->assertTrue($payload['claim_policy']['does_not_inflate_lift']);
        $this->assertTrue($payload['claim_policy']['age_derived_from_real_created_at']);
        $this->assertFalse($payload['claim_policy']['provider_calls_made']);
    }

    public function test_gate_blocks_when_only_days_old_packs_exist_real_soak_state(): void
    {
        $now = CarbonImmutable::parse('2026-06-13T12:00:00Z');

        // Two packs both created within the last few days (the real current
        // soak state): no >=3-week-old memory can possibly be recalled yet.
        $this->persistPack(
            scopeId: self::SCOPE_ID,
            createdAt: $now->subDays(2),
            refs: ['docs/fable-lista-6-14-itens.md'],
            safeResumeMode: AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
        );
        $this->persistPack(
            scopeId: self::SCOPE_ID,
            createdAt: $now->subDays(1),
            refs: ['docs/fable-lista-6-14-itens.md', 'storage/app/atlas/evidence/today.json'],
            safeResumeMode: AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
        );

        $payload = app(LongHorizonCrossWeekRecallLiftGateService::class)->evaluate([
            'scope_type' => self::SCOPE_TYPE,
            'scope_id' => self::SCOPE_ID,
            'now' => $now,
        ]);

        $this->assertSame(LongHorizonCrossWeekRecallLiftGateService::STATUS_INSUFFICIENT, $payload['status']);
        $this->assertFalse($payload['certified']);
        $this->assertFalse($payload['completion_claim_allowed']);
        $this->assertContains('recall_age_below_floor', $payload['blockers']);
        $this->assertContains('calendar_span_below_floor', $payload['blockers']);
        $this->assertContains('recall_lift_not_measured', $payload['blockers']);
    }

    public function test_gate_blocks_when_recall_lift_is_below_floor(): void
    {
        $now = CarbonImmutable::parse('2026-07-10T12:00:00Z');

        $this->persistPack(
            scopeId: self::SCOPE_ID,
            createdAt: $now->subDays(30),
            refs: ['docs/old-decision.md'],
            safeResumeMode: AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED,
        );
        // Recall arm: recalls old ref but is NOT certified.
        $this->persistPack(
            scopeId: self::SCOPE_ID,
            createdAt: $now->subDays(1),
            refs: ['docs/old-decision.md', 'docs/new.md'],
            safeResumeMode: AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED,
        );
        // No-recall arm: certified. So recall arm rate (0) < no-recall rate (1) → negative lift.
        $this->persistPack(
            scopeId: self::SCOPE_ID,
            createdAt: $now->subDays(2),
            refs: ['docs/fresh.md'],
            safeResumeMode: AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
        );

        $payload = app(LongHorizonCrossWeekRecallLiftGateService::class)->evaluate([
            'scope_type' => self::SCOPE_TYPE,
            'scope_id' => self::SCOPE_ID,
            'now' => $now,
        ]);

        $this->assertSame(LongHorizonCrossWeekRecallLiftGateService::STATUS_INSUFFICIENT, $payload['status']);
        $this->assertFalse($payload['certified']);
        $this->assertContains('recall_lift_below_floor', $payload['blockers']);
        $this->assertLessThan(0.01, (float) $payload['measurement']['ab']['recall_lift']);
    }

    public function test_gate_fail_closed_when_no_packs_exist(): void
    {
        $payload = app(LongHorizonCrossWeekRecallLiftGateService::class)->evaluate([
            'scope_type' => self::SCOPE_TYPE,
            'scope_id' => 'fable-l6-12-empty',
            'now' => CarbonImmutable::parse('2026-07-10T12:00:00Z'),
        ]);

        $this->assertSame(LongHorizonCrossWeekRecallLiftGateService::STATUS_INSUFFICIENT, $payload['status']);
        $this->assertFalse($payload['certified']);
        $this->assertContains('insufficient_pack_history', $payload['blockers']);
        $this->assertContains('recall_events_below_floor', $payload['blockers']);
    }

    public function test_command_strict_exits_nonzero_on_insufficient_evidence_and_writes_receipt(): void
    {
        $now = CarbonImmutable::parse('2026-06-13T12:00:00Z');
        $this->persistPack(self::SCOPE_ID, $now->subDays(1), ['docs/a.md'], AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE);
        $receipt = storage_path('framework/testing/cross-week-recall-lift-'.Str::random(8).'.json');

        $exit = $this->artisan('atlas:long-horizon:cross-week-recall-lift-gate', [
            '--scope-type' => self::SCOPE_TYPE,
            '--scope-id' => self::SCOPE_ID,
            '--receipt' => $receipt,
            '--strict' => true,
            '--json' => true,
        ])->run();

        $this->assertSame(1, $exit);
        $this->assertFileExists($receipt);
        $payload = json_decode((string) File::get($receipt), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(LongHorizonCrossWeekRecallLiftGateService::STATUS_INSUFFICIENT, $payload['status']);
        $this->assertFalse($payload['certified']);

        File::delete($receipt);
    }

    public function test_schedule_and_flags_are_enabled_for_l6_12_keystone(): void
    {
        $this->assertTrue((bool) config('atlas.long_horizon.cross_week_recall_lift_gate.enabled'));
        $this->assertTrue((bool) config('atlas.long_horizon.cross_week_recall_lift_gate.schedule_enabled'));
        $this->assertSame(21, (int) config('atlas.long_horizon.cross_week_recall_lift_gate.min_recall_age_days'));

        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringContainsString('atlas:long-horizon:cross-week-recall-lift-gate --write-receipt --json', $output);
    }

    /**
     * @param  list<string>  $refs
     */
    private function persistPack(string $scopeId, CarbonImmutable $createdAt, array $refs, string $safeResumeMode): AtlasLongHorizonContinuationPack
    {
        $evidenceRefs = array_map(static fn (string $ref): array => [
            'kind' => str_contains($ref, '/evidence/') ? 'artifact' : 'doc',
            'ref' => $ref,
            'source_hash_actual' => hash('sha256', $ref),
        ], $refs);

        $payload = [
            'uuid' => 'l6-12-recall-'.Str::random(20),
            'schema_version' => AtlasLongHorizonCanon::CONTINUATION_PACK_SCHEMA_VERSION,
            'scope_type' => self::SCOPE_TYPE,
            'scope_id' => $scopeId,
            'objective' => 'cross-week recall lift test pack',
            'current_phase' => 'continuity_certification',
            'state_summary' => 'test pack created_at='.$createdAt->toIso8601String(),
            'decisions' => [],
            'superseded_decisions' => [],
            'open_tasks' => [],
            'completed_tasks' => [],
            'blockers' => [],
            'risks' => [],
            'evidence_refs' => $evidenceRefs,
            'context_manifest' => [],
            'context_pack_hash' => hash('sha256', json_encode($evidenceRefs)),
            'summary_hash' => hash('sha256', $scopeId.$createdAt->toIso8601String()),
            'source_receipts' => [],
            'stale_after' => $createdAt->addDays(21),
            'safe_resume_mode' => $safeResumeMode,
            'next_safe_action' => 'test',
            'human_decisions_required' => [],
            'confidence' => 0.8,
        ];
        $payload['pack_hash'] = AtlasLongHorizonContinuationPack::canonicalPackHash($payload);

        $pack = AtlasLongHorizonContinuationPack::query()->create($payload);
        // Stamp the REAL persisted created_at — this is exactly what elapsed
        // calendar time will produce; the gate derives age from this column.
        $pack->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $pack->refresh();
    }
}
