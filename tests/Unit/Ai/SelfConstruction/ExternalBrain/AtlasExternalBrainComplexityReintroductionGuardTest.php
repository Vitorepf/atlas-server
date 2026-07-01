<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainComplexityReintroductionGuard;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainComplexityReintroductionGuardTest extends TestCase
{
    private function guard(): AtlasExternalBrainComplexityReintroductionGuard
    {
        return new AtlasExternalBrainComplexityReintroductionGuard;
    }

    public function test_reintroduced_complexity_rejection_case(): void
    {
        $r = $this->guard()->evaluate([
            'removed_patterns' => [
                ['pattern' => 'AdapterFactoryWrapper', 'kind' => 'indirection'],
            ],
            'candidate' => [
                'pattern' => 'AdapterFactoryWrapper',
                'kind' => 'indirection',
            ],
        ]);

        $this->assertSame('reject', $r['decision']);
        $this->assertSame('AdapterFactoryWrapper', $r['matched_removed_pattern']);
        $this->assertSame('reintroduces_removed_pattern_without_new_evidence', $r['reason']);
    }

    public function test_new_capability_admit_case(): void
    {
        $r = $this->guard()->evaluate([
            'removed_patterns' => [
                ['pattern' => 'AdapterFactoryWrapper', 'kind' => 'indirection'],
            ],
            'candidate' => [
                'pattern' => 'NewCapabilityOrgan',
                'kind' => 'new_capability',
            ],
        ]);

        $this->assertSame('admit', $r['decision']);
        $this->assertNull($r['matched_removed_pattern']);
        $this->assertSame('new_capability_no_reintroduction', $r['reason']);
    }

    public function test_reintroduction_with_new_evidence_is_admitted(): void
    {
        $r = $this->guard()->evaluate([
            'removed_patterns' => [
                ['pattern' => 'DuplicateValidator', 'kind' => 'duplicate'],
            ],
            'candidate' => [
                'pattern' => 'DuplicateValidator',
                'kind' => 'duplicate',
                'new_evidence' => 'legal now requires a second independent validator per SOC2 finding #42',
            ],
        ]);

        $this->assertSame('admit', $r['decision']);
        $this->assertSame('admitted_with_new_evidence', $r['reason']);
        $this->assertSame('DuplicateValidator', $r['matched_removed_pattern']);
    }

    public function test_same_pattern_different_kind_is_not_a_match(): void
    {
        $r = $this->guard()->evaluate([
            'removed_patterns' => [
                ['pattern' => 'Foo', 'kind' => 'duplicate'],
            ],
            'candidate' => [
                'pattern' => 'Foo',
                'kind' => 'public_surface',
            ],
        ]);

        $this->assertSame('admit', $r['decision']);
        $this->assertNull($r['matched_removed_pattern']);
    }

    public function test_empty_history_admits_any_candidate(): void
    {
        $r = $this->guard()->evaluate([
            'candidate' => ['pattern' => 'Anything', 'kind' => 'duplicate'],
        ]);

        $this->assertSame('admit', $r['decision']);
    }

    public function test_schema_present(): void
    {
        $r = $this->guard()->evaluate([]);

        $this->assertSame(AtlasExternalBrainComplexityReintroductionGuard::SCHEMA, $r['schema']);
    }
}
