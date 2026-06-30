<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroPoisonEarlyWarningModel;
use Tests\TestCase;

final class AtlasMaestroPoisonEarlyWarningModelTest extends TestCase
{
    private function svc(): AtlasMaestroPoisonEarlyWarningModel
    {
        return new AtlasMaestroPoisonEarlyWarningModel;
    }

    private function cleanPacket(): array
    {
        return [
            'allowed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
            'quality_facts' => ['test_only_has_contract' => false],
            'give_back_count' => 0,
        ];
    }

    // ── clean packet ──────────────────────────────────────────────────────────

    public function test_clean_impl_and_test_packet_scores_low_and_serves(): void
    {
        $r = $this->svc()->score($this->cleanPacket());

        $this->assertSame(AtlasMaestroPoisonEarlyWarningModel::RISK_LOW, $r['poison_risk']);
        $this->assertSame(AtlasMaestroPoisonEarlyWarningModel::ACTION_SERVE, $r['recommended_action']);
        $this->assertSame([], $r['reasons']);
        $this->assertEqualsWithDelta(0.95, $r['confidence'], 0.001);
        $this->assertSame(AtlasMaestroPoisonEarlyWarningModel::SCHEMA, $r['schema_version']);
    }

    // ── high-risk signals ─────────────────────────────────────────────────────

    public function test_test_only_has_contract_scores_high_and_rescopes(): void
    {
        $r = $this->svc()->score(array_merge($this->cleanPacket(), [
            'quality_facts' => ['test_only_has_contract' => true],
        ]));

        $this->assertSame(AtlasMaestroPoisonEarlyWarningModel::RISK_HIGH, $r['poison_risk']);
        $this->assertSame(AtlasMaestroPoisonEarlyWarningModel::ACTION_RESCOPE, $r['recommended_action']);
        $this->assertContains('test_only_has_contract', $r['reasons']);
    }

    public function test_forbidden_self_target_scores_high_and_retires(): void
    {
        $r = $this->svc()->score(array_merge($this->cleanPacket(), [
            'forbidden_self_target' => true,
        ]));

        $this->assertSame(AtlasMaestroPoisonEarlyWarningModel::RISK_HIGH, $r['poison_risk']);
        $this->assertSame(AtlasMaestroPoisonEarlyWarningModel::ACTION_RETIRE, $r['recommended_action']);
        $this->assertContains('forbidden_self_target', $r['reasons']);
    }

    public function test_dormant_cli_arm_proxy_scores_high_and_retires(): void
    {
        $r = $this->svc()->score(array_merge($this->cleanPacket(), [
            'quality_facts' => ['dormant_cli_arm_proxy' => true],
        ]));

        $this->assertSame(AtlasMaestroPoisonEarlyWarningModel::RISK_HIGH, $r['poison_risk']);
        $this->assertSame(AtlasMaestroPoisonEarlyWarningModel::ACTION_RETIRE, $r['recommended_action']);
        $this->assertContains('dormant_cli_arm_proxy', $r['reasons']);
    }

    public function test_high_give_back_count_scores_high_and_retires(): void
    {
        $r = $this->svc()->score(array_merge($this->cleanPacket(), ['give_back_count' => 8]));

        $this->assertSame(AtlasMaestroPoisonEarlyWarningModel::RISK_HIGH, $r['poison_risk']);
        $this->assertSame(AtlasMaestroPoisonEarlyWarningModel::ACTION_RETIRE, $r['recommended_action']);
        $this->assertSame(1, count(array_filter($r['reasons'], fn ($r) => str_starts_with($r, 'repeated_give_back:'))));
    }

    // ── medium-risk signals ───────────────────────────────────────────────────

    public function test_contradictory_acceptance_deficiency_scores_medium(): void
    {
        $r = $this->svc()->score(array_merge($this->cleanPacket(), [
            'blocking_deficiencies' => ['contradictory_acceptance'],
        ]));

        $this->assertSame(AtlasMaestroPoisonEarlyWarningModel::RISK_MEDIUM, $r['poison_risk']);
        $this->assertContains('contradictory_acceptance', $r['reasons']);
    }

    public function test_medium_give_back_count_scores_medium(): void
    {
        $r = $this->svc()->score(array_merge($this->cleanPacket(), ['give_back_count' => 5]));

        $this->assertSame(AtlasMaestroPoisonEarlyWarningModel::RISK_MEDIUM, $r['poison_risk']);
        $this->assertSame(AtlasMaestroPoisonEarlyWarningModel::ACTION_HOLD, $r['recommended_action']);
    }

    public function test_schema_mismatch_scores_medium_and_rescopes(): void
    {
        $r = $this->svc()->score(array_merge($this->cleanPacket(), ['schema_mismatch' => true]));

        $this->assertSame(AtlasMaestroPoisonEarlyWarningModel::RISK_MEDIUM, $r['poison_risk']);
        $this->assertSame(AtlasMaestroPoisonEarlyWarningModel::ACTION_RESCOPE, $r['recommended_action']);
        $this->assertContains('schema_mismatch', $r['reasons']);
    }

    public function test_test_file_only_no_impl_scores_medium(): void
    {
        $r = $this->svc()->score([
            'allowed_files' => ['tests/Unit/FooTest.php'],
            'give_back_count' => 0,
        ]);

        $this->assertSame(AtlasMaestroPoisonEarlyWarningModel::RISK_MEDIUM, $r['poison_risk']);
        $this->assertContains('missing_implementation_file', $r['reasons']);
    }

    // ── accumulation and confidence ───────────────────────────────────────────

    public function test_multiple_signals_accumulate_score_to_high(): void
    {
        // contradictory_acceptance(+2) + schema_mismatch(+2) = 4 → high
        $r = $this->svc()->score(array_merge($this->cleanPacket(), [
            'contradictory_acceptance' => true,
            'schema_mismatch' => true,
        ]));

        $this->assertSame(AtlasMaestroPoisonEarlyWarningModel::RISK_HIGH, $r['poison_risk']);
        $this->assertGreaterThanOrEqual(4, $r['score']);
        $this->assertCount(2, $r['reasons']);
    }

    public function test_confidence_is_high_for_clean_packet(): void
    {
        $r = $this->svc()->score($this->cleanPacket());
        $this->assertEqualsWithDelta(0.95, $r['confidence'], 0.001);
    }

    public function test_confidence_lower_for_single_signal(): void
    {
        $r = $this->svc()->score(array_merge($this->cleanPacket(), ['schema_mismatch' => true]));
        $this->assertLessThan(0.95, $r['confidence']);
        $this->assertEqualsWithDelta(0.70, $r['confidence'], 0.001);
    }

    public function test_confidence_medium_for_multiple_signals(): void
    {
        $r = $this->svc()->score(array_merge($this->cleanPacket(), [
            'schema_mismatch' => true,
            'contradictory_acceptance' => true,
        ]));
        $this->assertEqualsWithDelta(0.85, $r['confidence'], 0.001);
    }
}
