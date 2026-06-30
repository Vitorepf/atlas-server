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
}
