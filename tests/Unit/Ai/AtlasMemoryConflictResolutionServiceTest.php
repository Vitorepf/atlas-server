<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Memory\AtlasMemoryConflictResolutionService;
use Tests\TestCase;

/**
 * Atlas Cognition Operating System — Absorcao 2 (Conflict Verbs).
 *
 * Cobre logica pura do AtlasMemoryConflictResolutionService:
 *  - validacao de verdicts (enum dos seis)
 *  - heuristica de escalation (engram rule)
 *  - normalizacao de inputs
 *
 * Testes de persistencia + integracao com banco vivem em Feature/Ai/AtlasMemoryConflictResolutionPersistenceTest.
 */
class AtlasMemoryConflictResolutionServiceTest extends TestCase
{
    public function test_is_valid_verdict_accepts_all_six_canonical_verbs(): void
    {
        foreach (AtlasMemoryConflictResolutionService::ALLOWED_VERDICTS as $verdict) {
            $this->assertTrue(
                AtlasMemoryConflictResolutionService::isValidVerdict($verdict),
                "Verdict '$verdict' deve ser canon valido."
            );
        }
    }

    public function test_is_valid_verdict_rejects_non_canonical(): void
    {
        foreach (['', 'invalid', 'related ', 'supersede', 'CONFLICTS_WITH', 'conflict'] as $bad) {
            $this->assertFalse(
                AtlasMemoryConflictResolutionService::isValidVerdict($bad),
                "Verdict '$bad' nao deve ser aceito."
            );
        }
    }

    public function test_visible_verdicts_are_only_supersedes_and_conflicts_with(): void
    {
        $this->assertTrue(AtlasMemoryConflictResolutionService::isVisibleInSearch('supersedes'));
        $this->assertTrue(AtlasMemoryConflictResolutionService::isVisibleInSearch('conflicts_with'));
        $this->assertFalse(AtlasMemoryConflictResolutionService::isVisibleInSearch('related'));
        $this->assertFalse(AtlasMemoryConflictResolutionService::isVisibleInSearch('compatible'));
        $this->assertFalse(AtlasMemoryConflictResolutionService::isVisibleInSearch('scoped'));
        $this->assertFalse(AtlasMemoryConflictResolutionService::isVisibleInSearch('not_conflict'));
    }

    public function test_should_escalate_returns_required_when_confidence_below_threshold(): void
    {
        $service = new AtlasMemoryConflictResolutionService;

        $decision = $service->shouldEscalate('related', 0.5, ['preference']);

        $this->assertTrue($decision['required']);
        $this->assertContains('low_confidence_below_0_7', $decision['reasons']);
    }

    public function test_should_escalate_returns_required_for_visible_verdict_on_high_risk_type(): void
    {
        $service = new AtlasMemoryConflictResolutionService;

        $decision = $service->shouldEscalate('supersedes', 0.95, ['decision', 'preference']);

        $this->assertTrue($decision['required']);
        $this->assertContains('visible_verdict_on_high_risk_memory_type', $decision['reasons']);
    }

    public function test_should_escalate_returns_required_for_conflicts_with_on_architecture(): void
    {
        $service = new AtlasMemoryConflictResolutionService;

        $decision = $service->shouldEscalate('conflicts_with', 0.85, ['architecture']);

        $this->assertTrue($decision['required']);
        $this->assertContains('visible_verdict_on_high_risk_memory_type', $decision['reasons']);
    }

    public function test_should_escalate_returns_required_for_supersedes_on_policy(): void
    {
        $service = new AtlasMemoryConflictResolutionService;

        $decision = $service->shouldEscalate('supersedes', 0.95, ['policy']);

        $this->assertTrue($decision['required']);
    }

    public function test_should_escalate_silent_when_confidence_high_and_low_risk_type(): void
    {
        $service = new AtlasMemoryConflictResolutionService;

        $decision = $service->shouldEscalate('related', 0.9, ['preference']);

        $this->assertFalse($decision['required']);
        $this->assertSame([], $decision['reasons']);
    }

    public function test_should_escalate_silent_for_compatible_verdict_even_on_high_risk_type(): void
    {
        // compatible nao e visible verdict -> nao escala por tipo, so por confidence.
        $service = new AtlasMemoryConflictResolutionService;

        $decision = $service->shouldEscalate('compatible', 0.9, ['decision']);

        $this->assertFalse($decision['required']);
    }

    public function test_should_escalate_accumulates_reasons(): void
    {
        $service = new AtlasMemoryConflictResolutionService;

        $decision = $service->shouldEscalate('supersedes', 0.5, ['decision']);

        $this->assertTrue($decision['required']);
        $this->assertContains('low_confidence_below_0_7', $decision['reasons']);
        $this->assertContains('visible_verdict_on_high_risk_memory_type', $decision['reasons']);
        $this->assertCount(2, $decision['reasons']);
    }

    public function test_should_escalate_handles_null_confidence(): void
    {
        $service = new AtlasMemoryConflictResolutionService;

        $decision = $service->shouldEscalate('related', null, ['preference']);

        $this->assertFalse($decision['required']);
    }

    public function test_judge_rejects_self_reference(): void
    {
        $service = new AtlasMemoryConflictResolutionService;

        $result = $service->judge('uuid-same', 'uuid-same', 'related');

        $this->assertFalse($result['ok']);
        $this->assertSame('self_reference', $result['status']);
    }

    public function test_judge_rejects_empty_ids(): void
    {
        $service = new AtlasMemoryConflictResolutionService;

        $result = $service->judge('', 'uuid-target', 'related');

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_ids', $result['status']);
    }

    public function test_judge_rejects_invalid_verdict(): void
    {
        $service = new AtlasMemoryConflictResolutionService;

        $result = $service->judge('uuid-source', 'uuid-target', 'super_conflicts_with_invalid');

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_verdict', $result['status']);
    }

    public function test_envelope_carries_canonical_schema_version(): void
    {
        $service = new AtlasMemoryConflictResolutionService;

        $result = $service->judge('', '', 'related');

        $this->assertSame('atlas.memory.relation_verdict.v1', $result['schema_version']);
    }
}
