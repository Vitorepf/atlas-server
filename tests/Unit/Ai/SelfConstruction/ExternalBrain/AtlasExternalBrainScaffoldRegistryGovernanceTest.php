<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScaffoldRegistryGovernance;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainScaffoldRegistryGovernanceTest extends TestCase
{
    private AtlasExternalBrainScaffoldRegistryGovernance $gov;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gov = new AtlasExternalBrainScaffoldRegistryGovernance();
    }

    // AC 2: measured lift + low overfit risk → promoted
    public function test_measured_lift_low_overfit_promoted(): void
    {
        $result = $this->gov->decide([
            'variant_id' => 'v2',
            'measured_lift_verdict' => 'measured_lift',
            'overfit_risk' => 0.1,
            'overfit_threshold' => 0.3,
            'compatible_with_active_version' => true,
        ]);

        $this->assertSame('promote', $result['action']);
    }

    // AC 3: overfit or incompatible → held or deprecated
    public function test_high_overfit_risk_held(): void
    {
        $result = $this->gov->decide([
            'variant_id' => 'v2',
            'measured_lift_verdict' => 'measured_lift',
            'overfit_risk' => 0.6,
            'overfit_threshold' => 0.3,
            'compatible_with_active_version' => true,
        ]);

        $this->assertSame('hold', $result['action']);
    }

    public function test_incompatible_without_replacement_deprecated(): void
    {
        $result = $this->gov->decide([
            'variant_id' => 'v1',
            'compatible_with_active_version' => false,
        ]);

        $this->assertSame('deprecate', $result['action']);
    }

    public function test_incompatible_with_replacement_retired(): void
    {
        $result = $this->gov->decide([
            'variant_id' => 'v1',
            'compatible_with_active_version' => false,
            'replacement_candidate_id' => 'v2',
        ]);

        $this->assertSame('retire', $result['action']);
    }

    public function test_no_measured_lift_held(): void
    {
        $result = $this->gov->decide([
            'variant_id' => 'v2',
            'measured_lift_verdict' => 'no_measured_lift',
            'overfit_risk' => 0.1,
            'compatible_with_active_version' => true,
        ]);

        $this->assertSame('hold', $result['action']);
    }

    // AC 4: lifecycle output includes reason, next_review_trigger and replacement_candidate
    public function test_output_includes_required_fields(): void
    {
        $result = $this->gov->decide([
            'variant_id' => 'v1',
            'compatible_with_active_version' => false,
            'replacement_candidate_id' => 'v3',
        ]);

        $this->assertArrayHasKey('reason', $result);
        $this->assertIsString($result['reason']);
        $this->assertNotEmpty($result['reason']);

        $this->assertArrayHasKey('next_review_trigger', $result);
        $this->assertIsString($result['next_review_trigger']);

        $this->assertArrayHasKey('replacement_candidate', $result);
        $this->assertSame('v3', $result['replacement_candidate']);
    }
}
