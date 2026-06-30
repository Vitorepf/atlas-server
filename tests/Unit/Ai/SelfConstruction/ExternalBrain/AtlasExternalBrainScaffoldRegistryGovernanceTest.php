<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScaffoldRegistryGovernance;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainScaffoldRegistryGovernanceTest extends TestCase
{
    private function gov(): AtlasExternalBrainScaffoldRegistryGovernance
    {
        return new AtlasExternalBrainScaffoldRegistryGovernance;
    }

    private function valid(array $overrides = []): array
    {
        return array_merge([
            'id'                    => 'v1',
            'version'               => '1.0.0',
            'status'                => 'active',
            'safety_checks'         => ['null_check', 'auth_check'],
            'provider_safe_summary' => 'Scaffold for extraction tasks.',
            'rollback_plan'         => 'Revert to v0.9.0',
        ], $overrides);
    }

    // ── AC4: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->gov()->govern([]);
        $this->assertSame(AtlasExternalBrainScaffoldRegistryGovernance::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('valid_entries',    $r);
        $this->assertArrayHasKey('rejected_entries', $r);
        $this->assertArrayHasKey('active_variants',  $r);
        $this->assertArrayHasKey('retired_variants', $r);
        $this->assertArrayHasKey('registry_health',  $r);
    }

    // ── AC3: valid entry accepted ─────────────────────────────────────────────

    public function test_fully_valid_entry_accepted(): void
    {
        $r = $this->gov()->govern(['entries' => [$this->valid()]]);
        $this->assertContains('v1', $r['valid_entries']);
        $this->assertEmpty($r['rejected_entries']);
    }

    // ── AC2: version format ───────────────────────────────────────────────────

    public function test_invalid_version_format_rejected(): void
    {
        $r = $this->gov()->govern(['entries' => [$this->valid(['version' => '1.0'])]]);
        $reasons = $r['rejected_entries'][0]['rejection_reasons'];
        $this->assertContains('invalid_version_format', $reasons);
    }

    public function test_non_numeric_version_rejected(): void
    {
        $r = $this->gov()->govern(['entries' => [$this->valid(['version' => 'v1.2.3'])]]);
        $this->assertContains('invalid_version_format', $r['rejected_entries'][0]['rejection_reasons']);
    }

    // ── AC2: status validation ────────────────────────────────────────────────

    public function test_invalid_status_rejected(): void
    {
        $r = $this->gov()->govern(['entries' => [$this->valid(['status' => 'unknown'])]]);
        $this->assertContains('invalid_status', $r['rejected_entries'][0]['rejection_reasons']);
    }

    public function test_all_valid_statuses_accepted(): void
    {
        foreach (['active', 'retired', 'experimental', 'deprecated'] as $status) {
            $r = $this->gov()->govern(['entries' => [$this->valid(['id' => $status, 'status' => $status])]]);
            $this->assertContains($status, $r['valid_entries'], "Status '$status' should be valid");
        }
    }

    // ── AC3: required fields ──────────────────────────────────────────────────

    public function test_missing_safety_checks_rejected(): void
    {
        $r = $this->gov()->govern(['entries' => [$this->valid(['safety_checks' => []])]]);
        $this->assertContains('missing_safety_checks', $r['rejected_entries'][0]['rejection_reasons']);
    }

    public function test_absent_safety_checks_rejected(): void
    {
        $entry = $this->valid();
        unset($entry['safety_checks']);
        $r = $this->gov()->govern(['entries' => [$entry]]);
        $this->assertContains('missing_safety_checks', $r['rejected_entries'][0]['rejection_reasons']);
    }

    public function test_empty_provider_safe_summary_rejected(): void
    {
        $r = $this->gov()->govern(['entries' => [$this->valid(['provider_safe_summary' => ''])]]);
        $this->assertContains('missing_provider_safe_summary', $r['rejected_entries'][0]['rejection_reasons']);
    }

    public function test_missing_rollback_plan_rejected(): void
    {
        $entry = $this->valid();
        unset($entry['rollback_plan']);
        $r = $this->gov()->govern(['entries' => [$entry]]);
        $this->assertContains('missing_rollback_plan', $r['rejected_entries'][0]['rejection_reasons']);
    }

    public function test_empty_rollback_plan_rejected(): void
    {
        $r = $this->gov()->govern(['entries' => [$this->valid(['rollback_plan' => ''])]]);
        $this->assertContains('missing_rollback_plan', $r['rejected_entries'][0]['rejection_reasons']);
    }

    // ── active/retired classification ─────────────────────────────────────────

    public function test_active_entry_in_active_variants(): void
    {
        $r = $this->gov()->govern(['entries' => [$this->valid(['status' => 'active'])]]);
        $this->assertContains('v1', $r['active_variants']);
        $this->assertNotContains('v1', $r['retired_variants']);
    }

    public function test_retired_entry_in_retired_variants(): void
    {
        $r = $this->gov()->govern(['entries' => [$this->valid(['status' => 'retired'])]]);
        $this->assertContains('v1', $r['retired_variants']);
        $this->assertNotContains('v1', $r['active_variants']);
    }

    // ── registry_health ───────────────────────────────────────────────────────

    public function test_healthy_when_no_rejections_and_active_present(): void
    {
        $r = $this->gov()->govern(['entries' => [$this->valid()]]);
        $this->assertSame('healthy', $r['registry_health']);
    }

    public function test_degraded_when_some_rejected_but_active_present(): void
    {
        $r = $this->gov()->govern(['entries' => [
            $this->valid(['id' => 'good']),
            $this->valid(['id' => 'bad', 'safety_checks' => []]),
        ]]);
        $this->assertSame('degraded', $r['registry_health']);
    }

    public function test_critical_when_no_valid_active(): void
    {
        $r = $this->gov()->govern(['entries' => [
            $this->valid(['status' => 'retired']), // valid but not active
        ]]);
        $this->assertSame('critical', $r['registry_health']);
    }

    public function test_critical_when_empty_entries(): void
    {
        $r = $this->gov()->govern([]);
        $this->assertSame('critical', $r['registry_health']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = ['entries' => [
            $this->valid(['id' => 'a']),
            $this->valid(['id' => 'b', 'status' => 'retired']),
            $this->valid(['id' => 'c', 'safety_checks' => []]),
        ]];
        $a = $this->gov()->govern($facts);
        $b = $this->gov()->govern($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── recommendLifecycle ──────────────────────────────────────────────────────

    private function scaffold(array $overrides = []): array
    {
        return array_merge([
            'id' => 'scaffold-1',
            'version' => '1.0.0',
            'intended_failure_mode' => 'hallucinated_file_path',
            'observed_lift' => 0.2,
            'sample_size' => 10,
            'risk' => 'low',
            'lifecycle_state' => 'experimental',
        ], $overrides);
    }

    public function test_lifecycle_output_has_required_keys(): void
    {
        $r = $this->gov()->recommendLifecycle($this->scaffold());

        foreach (['schema_version', 'id', 'version', 'intended_failure_mode', 'observed_lift', 'risk', 'lifecycle_state', 'recommendation', 'reason'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
        $this->assertSame(AtlasExternalBrainScaffoldRegistryGovernance::SCHEMA, $r['schema_version']);
    }

    public function test_missing_observed_lift_fails_closed_to_keep_testing(): void
    {
        $r = $this->gov()->recommendLifecycle($this->scaffold(['observed_lift' => null]));

        $this->assertSame(AtlasExternalBrainScaffoldRegistryGovernance::RECOMMEND_KEEP_TESTING, $r['recommendation']);
        $this->assertSame('insufficient_lift_evidence', $r['reason']);
    }

    public function test_below_min_sample_size_fails_closed_even_with_high_lift(): void
    {
        $r = $this->gov()->recommendLifecycle($this->scaffold(['observed_lift' => 0.9, 'sample_size' => 2]));

        $this->assertSame(AtlasExternalBrainScaffoldRegistryGovernance::RECOMMEND_KEEP_TESTING, $r['recommendation']);
        $this->assertSame('insufficient_lift_evidence', $r['reason']);
    }

    public function test_negative_lift_is_retired(): void
    {
        $r = $this->gov()->recommendLifecycle($this->scaffold(['observed_lift' => -0.1]));

        $this->assertSame(AtlasExternalBrainScaffoldRegistryGovernance::RECOMMEND_RETIRE, $r['recommendation']);
        $this->assertSame('no_positive_lift', $r['reason']);
    }

    public function test_zero_lift_is_retired(): void
    {
        $r = $this->gov()->recommendLifecycle($this->scaffold(['observed_lift' => 0.0]));

        $this->assertSame(AtlasExternalBrainScaffoldRegistryGovernance::RECOMMEND_RETIRE, $r['recommendation']);
    }

    public function test_high_risk_with_moderate_lift_is_retired(): void
    {
        $r = $this->gov()->recommendLifecycle($this->scaffold(['risk' => 'high', 'observed_lift' => 0.18]));

        $this->assertSame(AtlasExternalBrainScaffoldRegistryGovernance::RECOMMEND_RETIRE, $r['recommendation']);
        $this->assertSame('high_risk_without_sufficient_lift', $r['reason']);
    }

    public function test_high_risk_with_strong_lift_can_still_promote(): void
    {
        $r = $this->gov()->recommendLifecycle($this->scaffold(['risk' => 'high', 'observed_lift' => 0.3]));

        $this->assertSame(AtlasExternalBrainScaffoldRegistryGovernance::RECOMMEND_PROMOTE, $r['recommendation']);
    }

    public function test_low_positive_lift_is_downgraded(): void
    {
        $r = $this->gov()->recommendLifecycle($this->scaffold(['observed_lift' => 0.02]));

        $this->assertSame(AtlasExternalBrainScaffoldRegistryGovernance::RECOMMEND_DOWNGRADE, $r['recommendation']);
        $this->assertSame('lift_below_downgrade_threshold', $r['reason']);
    }

    public function test_moderate_lift_keeps_testing(): void
    {
        $r = $this->gov()->recommendLifecycle($this->scaffold(['observed_lift' => 0.10]));

        $this->assertSame(AtlasExternalBrainScaffoldRegistryGovernance::RECOMMEND_KEEP_TESTING, $r['recommendation']);
        $this->assertSame('lift_positive_but_below_promotion_bar', $r['reason']);
    }

    public function test_high_lift_low_risk_promotes(): void
    {
        $r = $this->gov()->recommendLifecycle($this->scaffold(['observed_lift' => 0.2, 'risk' => 'low']));

        $this->assertSame(AtlasExternalBrainScaffoldRegistryGovernance::RECOMMEND_PROMOTE, $r['recommendation']);
        $this->assertSame('lift_meets_promotion_bar', $r['reason']);
    }

    public function test_lifecycle_result_preserves_identity_fields(): void
    {
        $r = $this->gov()->recommendLifecycle($this->scaffold(['id' => 'scaffold-x', 'version' => '2.1.0', 'intended_failure_mode' => 'logic_gap']));

        $this->assertSame('scaffold-x', $r['id']);
        $this->assertSame('2.1.0', $r['version']);
        $this->assertSame('logic_gap', $r['intended_failure_mode']);
    }
}
