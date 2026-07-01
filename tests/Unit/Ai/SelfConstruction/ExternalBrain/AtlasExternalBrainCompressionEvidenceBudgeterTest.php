<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionEvidenceBudgeter;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompressionEvidenceBudgeterTest extends TestCase
{
    private function budgeter(): AtlasExternalBrainCompressionEvidenceBudgeter
    {
        return new AtlasExternalBrainCompressionEvidenceBudgeter;
    }

    public function test_high_risk_deep_budget_case(): void
    {
        $r = $this->budgeter()->evaluate([
            'risk_level' => 'high',
            'blast_radius' => 10,
            'capability_criticality' => 'critical',
        ]);

        $this->assertSame('deep', $r['evidence_tier']);
        $this->assertContains('unit_tests', $r['required_evidence']);
        $this->assertContains('integration_tests', $r['required_evidence']);
        $this->assertContains('behavior_lock_proof', $r['required_evidence']);
        $this->assertContains('rollback_evidence', $r['required_evidence']);
        $this->assertContains('manual_review_receipt', $r['required_evidence']);
    }

    public function test_low_risk_minimal_budget_case_never_bypasses_unit_tests(): void
    {
        $r = $this->budgeter()->evaluate([
            'risk_level' => 'low',
            'blast_radius' => 0,
            'capability_criticality' => 'low',
        ]);

        $this->assertSame('minimal', $r['evidence_tier']);
        $this->assertSame(['unit_tests'], $r['required_evidence']);
    }

    public function test_medium_risk_alone_reaches_standard_tier(): void
    {
        $r = $this->budgeter()->evaluate([
            'risk_level' => 'medium',
            'blast_radius' => 3,
        ]);

        $this->assertSame('standard', $r['evidence_tier']);
        $this->assertContains('integration_tests', $r['required_evidence']);
        $this->assertContains('behavior_lock_proof', $r['required_evidence']);
        $this->assertNotContains('rollback_evidence', $r['required_evidence']);
    }

    public function test_critical_capability_alone_pushes_toward_deeper_evidence(): void
    {
        $r = $this->budgeter()->evaluate([
            'capability_criticality' => 'critical',
            'blast_radius' => 6,
        ]);

        $this->assertSame('deep', $r['evidence_tier']);
    }

    public function test_missing_facts_default_to_minimal(): void
    {
        $r = $this->budgeter()->evaluate([]);

        $this->assertSame('minimal', $r['evidence_tier']);
        $this->assertSame(0, $r['score']);
    }

    public function test_schema_present(): void
    {
        $r = $this->budgeter()->evaluate([]);

        $this->assertSame(AtlasExternalBrainCompressionEvidenceBudgeter::SCHEMA, $r['schema']);
    }
}
