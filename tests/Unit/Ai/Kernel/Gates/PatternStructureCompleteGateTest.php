<?php

namespace Tests\Unit\Ai\Kernel\Gates;

use App\Services\Ai\Kernel\Gates\PatternPersonalEvidenceProviderSafeGate;
use App\Services\Ai\Kernel\Gates\PatternStructureCompleteGate;
use Tests\TestCase;

class PatternStructureCompleteGateTest extends TestCase
{
    public function test_structure_gate_passes_complete_pattern(): void
    {
        $result = app(PatternStructureCompleteGate::class)->evaluate([
            'name' => 'validate-then-scale',
            'category' => 'process',
            'intent' => 'Validate before scale.',
            'problem_context' => 'Scale increases cost of error.',
            'forces' => [['name' => 'evidence']],
            'solution' => ['abstract' => 'Test small.'],
            'consequences' => ['pros' => ['cheap evidence']],
        ]);

        $this->assertSame('passed', $result['status']);
    }

    public function test_provider_safety_gate_blocks_unredacted_private_evidence(): void
    {
        $result = app(PatternPersonalEvidenceProviderSafeGate::class)->evaluate([
            'personal_evidence_refs' => [
                ['ledger_event_id' => 1, 'privacy_class' => 3, 'redacted' => false],
            ],
        ]);

        $this->assertSame('blocked', $result['status']);
    }
}
