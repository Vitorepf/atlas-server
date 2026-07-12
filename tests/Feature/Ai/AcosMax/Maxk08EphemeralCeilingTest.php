<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AcosMax;

use App\Services\Ai\SelfConstruction\Lineage\AtlasDecisionLineageLedger;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtlasAutonomyEnvelopeEphemeralCeilingService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipAutonomyEnvelope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MAXK-08 (LOTE 8 F2) — ephemeral autonomy-envelope ceiling by reversal rate.
 *
 * Invariants proven here:
 *   1. DEFAULT-OFF: flag OFF ⇒ ceiling = stored, basis=stored, feature_flag_off.
 *   2. Insufficient signal (< MIN_LANDINGS in window) ⇒ ceiling = stored.
 *   3. Below tightening threshold (rate < 5%) ⇒ ceiling = stored, honest reason.
 *   4. Mild band [5%, 15%) ⇒ files/cycles tightened by CEILING_STEP_1 (0.75×).
 *   5. Aggressive band ≥ 15% ⇒ files/cycles tightened by CEILING_STEP_2 (0.50×)
 *      AND risk_ceiling steps down (high→medium; medium→low).
 *   6. MONOTONIC-DOWN: effective is NEVER wider than stored on any field.
 *   7. Stored envelope is BYTE-IDENTICAL after auto-tightening (nothing written).
 *      Widening always requires an operator amendment (MAXK-07), never the loop.
 *   8. Reversals older than the window do not push the rate up.
 */
final class Maxk08EphemeralCeilingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-12T09:00:00+00:00');

        Schema::dropIfExists('atlas_decision_lineage_ledger');
        Schema::create('atlas_decision_lineage_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('decision_id', 120)->index();
            $table->string('obra_id', 120)->nullable()->index();
            $table->string('entity_kind', 32)->index();
            $table->string('entity_ref', 200);
            $table->string('entity_scope', 60)->nullable();
            $table->string('reverse_handle', 200)->nullable();
            $table->string('writer', 80);
            $table->json('meta')->nullable();
            $table->timestampTz('recorded_at')->index();
            $table->unique(['entity_kind', 'entity_ref']);
        });

        Config::set('atlas.maxk08.ephemeral_ceiling_enabled', false);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Schema::dropIfExists('atlas_decision_lineage_ledger');
        parent::tearDown();
    }

    private function envelope(array $overrides = []): StewardshipAutonomyEnvelope
    {
        return StewardshipAutonomyEnvelope::fromArray(array_replace([
            'area_id' => 'atlas-server',
            'focus' => 'dev_forge',
            'merge_target' => StewardshipAutonomyEnvelope::MERGE_TARGET_INTEGRATION_LANE,
            'admit_cross_system' => false,
            'allowed_owners' => ['atlas_dev'],
            'risk_ceiling' => 'high',
            'max_auto_merge_files' => 20,
            'duration_days' => 7,
            'allowed_providers' => ['cursor_cli'],
            'max_cycles' => 40,
            'max_merges' => 30,
            'operator_actor' => 'vitor',
        ], $overrides));
    }

    private function seedApplyReversal(AtlasDecisionLineageLedger $ledger, string $decision, int $index, ?int $daysAgo = null): void
    {
        $ledger->append(
            $decision,
            AtlasDecisionLineageLedger::KIND_APPLY,
            'apply-'.$decision.'-'.$index,
            'learning_applier',
            reverseHandle: 'applier:reverse:'.$decision.'-'.$index,
        );

        if ($daysAgo !== null) {
            // Rewrite recorded_at to place the row outside the current window.
            \Illuminate\Support\Facades\DB::table('atlas_decision_lineage_ledger')
                ->where('entity_ref', 'apply-'.$decision.'-'.$index)
                ->update(['recorded_at' => CarbonImmutable::now()->utc()->subDays($daysAgo)]);
        }
    }

    public function test_default_off_leaves_ceiling_at_stored_values(): void
    {
        Config::set('atlas.maxk08.ephemeral_ceiling_enabled', false);
        $service = new AtlasAutonomyEnvelopeEphemeralCeilingService(new AtlasDecisionLineageLedger);

        $result = $service->effectiveCeiling($this->envelope(), ['total_landings' => 100]);

        $this->assertFalse($result['enabled']);
        $this->assertSame('stored', $result['basis']);
        $this->assertSame('feature_flag_off', $result['reason']);
        $this->assertSame(20, $result['effective']['max_auto_merge_files']);
        $this->assertSame(40, $result['effective']['max_cycles']);
        $this->assertSame('high', $result['effective']['risk_ceiling']);
        $this->assertTrue($result['operator_amendment_required_to_widen']);
        $this->assertFalse($result['writes_to_stored']);
    }

    public function test_insufficient_landings_holds_ceiling_at_stored(): void
    {
        Config::set('atlas.maxk08.ephemeral_ceiling_enabled', true);
        $ledger = new AtlasDecisionLineageLedger;
        // Only 3 reversals + 5 total landings ⇒ below MIN_LANDINGS floor.
        $this->seedApplyReversal($ledger, 'DEC-1', 1);
        $this->seedApplyReversal($ledger, 'DEC-2', 1);
        $this->seedApplyReversal($ledger, 'DEC-3', 1);

        $service = new AtlasAutonomyEnvelopeEphemeralCeilingService($ledger);
        $result = $service->effectiveCeiling($this->envelope(), ['total_landings' => 5]);

        $this->assertTrue($result['enabled']);
        $this->assertSame('stored', $result['basis']);
        $this->assertSame('insufficient_signal', $result['reason']);
        $this->assertSame(20, $result['effective']['max_auto_merge_files']);
    }

    public function test_below_tightening_threshold_holds_ceiling_at_stored(): void
    {
        Config::set('atlas.maxk08.ephemeral_ceiling_enabled', true);
        $ledger = new AtlasDecisionLineageLedger;
        // 1 reversal / 100 landings = 1% ⇒ below STEP_1 (5%).
        $this->seedApplyReversal($ledger, 'DEC-quiet', 1);

        $service = new AtlasAutonomyEnvelopeEphemeralCeilingService($ledger);
        $result = $service->effectiveCeiling($this->envelope(), ['total_landings' => 100]);

        $this->assertSame('stored', $result['basis']);
        $this->assertSame('below_tightening_threshold', $result['reason']);
        $this->assertEqualsWithDelta(0.01, $result['reversal_rate'], 0.0001);
        $this->assertSame(20, $result['effective']['max_auto_merge_files']);
    }

    public function test_mild_band_tightens_by_step_1_factor(): void
    {
        Config::set('atlas.maxk08.ephemeral_ceiling_enabled', true);
        $ledger = new AtlasDecisionLineageLedger;
        for ($i = 0; $i < 8; $i++) {
            $this->seedApplyReversal($ledger, 'DEC-mild-'.$i, $i);
        }

        $service = new AtlasAutonomyEnvelopeEphemeralCeilingService($ledger);
        // 8 reversals / 100 landings = 8% ⇒ mild (>= 5%, < 15%) ⇒ factor 0.75.
        $result = $service->effectiveCeiling($this->envelope(), ['total_landings' => 100]);

        $this->assertSame('auto_tightened', $result['basis']);
        $this->assertSame('reversal_rate_over_threshold', $result['reason']);
        $this->assertEqualsWithDelta(0.08, $result['reversal_rate'], 0.0001);

        $this->assertSame((int) floor(20 * 0.75), $result['effective']['max_auto_merge_files']); // 15
        $this->assertSame((int) floor(40 * 0.75), $result['effective']['max_cycles']); // 30
        // Mild band does NOT step risk_ceiling down.
        $this->assertSame('high', $result['effective']['risk_ceiling']);
    }

    public function test_aggressive_band_tightens_by_step_2_factor_and_steps_risk_down(): void
    {
        Config::set('atlas.maxk08.ephemeral_ceiling_enabled', true);
        $ledger = new AtlasDecisionLineageLedger;
        for ($i = 0; $i < 20; $i++) {
            $this->seedApplyReversal($ledger, 'DEC-hot-'.$i, $i);
        }

        $service = new AtlasAutonomyEnvelopeEphemeralCeilingService($ledger);
        // 20 reversals / 100 landings = 20% ⇒ aggressive (>= 15%) ⇒ factor 0.50.
        $result = $service->effectiveCeiling($this->envelope(), ['total_landings' => 100]);

        $this->assertSame('auto_tightened', $result['basis']);
        $this->assertEqualsWithDelta(0.20, $result['reversal_rate'], 0.0001);

        $this->assertSame((int) floor(20 * 0.50), $result['effective']['max_auto_merge_files']); // 10
        $this->assertSame((int) floor(40 * 0.50), $result['effective']['max_cycles']); // 20
        // Aggressive band steps high → medium.
        $this->assertSame('medium', $result['effective']['risk_ceiling']);
    }

    public function test_aggressive_band_also_steps_medium_down_to_low(): void
    {
        Config::set('atlas.maxk08.ephemeral_ceiling_enabled', true);
        $ledger = new AtlasDecisionLineageLedger;
        for ($i = 0; $i < 20; $i++) {
            $this->seedApplyReversal($ledger, 'DEC-hot-'.$i, $i);
        }

        $service = new AtlasAutonomyEnvelopeEphemeralCeilingService($ledger);
        $result = $service->effectiveCeiling(
            $this->envelope(['risk_ceiling' => 'medium']),
            ['total_landings' => 100],
        );

        $this->assertSame('auto_tightened', $result['basis']);
        $this->assertSame('low', $result['effective']['risk_ceiling']);
    }

    public function test_monotonic_down_effective_is_never_wider_than_stored(): void
    {
        Config::set('atlas.maxk08.ephemeral_ceiling_enabled', true);
        $ledger = new AtlasDecisionLineageLedger;
        // Fabricate a huge reversal spike (irrelevant — floor still holds).
        for ($i = 0; $i < 50; $i++) {
            $this->seedApplyReversal($ledger, 'DEC-spike-'.$i, $i);
        }
        $service = new AtlasAutonomyEnvelopeEphemeralCeilingService($ledger);

        $envelope = $this->envelope(['max_auto_merge_files' => 12, 'max_cycles' => 24]);
        $result = $service->effectiveCeiling($envelope, ['total_landings' => 100]);

        $this->assertLessThanOrEqual($envelope->maxAutoMergeFiles, $result['effective']['max_auto_merge_files']);
        $this->assertLessThanOrEqual($envelope->maxCycles, $result['effective']['max_cycles']);
        // Risk_ceiling only steps DOWN, never up.
        $this->assertContains($result['effective']['risk_ceiling'], ['high', 'medium', 'low']);
    }

    public function test_stored_envelope_is_byte_identical_after_auto_tightening(): void
    {
        Config::set('atlas.maxk08.ephemeral_ceiling_enabled', true);
        $ledger = new AtlasDecisionLineageLedger;
        for ($i = 0; $i < 20; $i++) {
            $this->seedApplyReversal($ledger, 'DEC-hot-'.$i, $i);
        }
        $service = new AtlasAutonomyEnvelopeEphemeralCeilingService($ledger);

        $envelope = $this->envelope();
        $before = $envelope->toArray();

        $service->effectiveCeiling($envelope, ['total_landings' => 100]);

        // The service NEVER touches the persisted envelope; policy_hash must
        // hash identically after the "auto tightening" pass.
        $this->assertSame($before, $envelope->toArray());
    }

    public function test_reversals_outside_window_do_not_lift_the_rate(): void
    {
        Config::set('atlas.maxk08.ephemeral_ceiling_enabled', true);
        $ledger = new AtlasDecisionLineageLedger;
        // 10 old (outside 30d window) + 1 recent ⇒ effective rate ≈ 1% ⇒ below step 1.
        for ($i = 0; $i < 10; $i++) {
            $this->seedApplyReversal($ledger, 'DEC-stale-'.$i, $i, daysAgo: 60);
        }
        $this->seedApplyReversal($ledger, 'DEC-fresh', 1);

        $service = new AtlasAutonomyEnvelopeEphemeralCeilingService($ledger);
        $result = $service->effectiveCeiling($this->envelope(), ['total_landings' => 100]);

        $this->assertSame('stored', $result['basis']);
        $this->assertSame('below_tightening_threshold', $result['reason']);
        $this->assertEqualsWithDelta(0.01, $result['reversal_rate'], 0.0001);
    }

    public function test_schema_and_shape_are_stable_for_downstream_consumers(): void
    {
        Config::set('atlas.maxk08.ephemeral_ceiling_enabled', false);
        $service = new AtlasAutonomyEnvelopeEphemeralCeilingService(new AtlasDecisionLineageLedger);
        $result = $service->effectiveCeiling($this->envelope(), []);

        $this->assertSame(AtlasAutonomyEnvelopeEphemeralCeilingService::SCHEMA, $result['schema']);
        foreach (['schema', 'enabled', 'stored', 'effective', 'basis', 'reason', 'reversal_rate',
            'window_days', 'window_landings', 'window_reversals',
            'operator_amendment_required_to_widen', 'writes_to_stored'] as $key) {
            $this->assertArrayHasKey($key, $result, "missing key {$key} in schema {$result['schema']}");
        }
    }
}
