<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasWorldModelRtService;
use Tests\TestCase;

final class AtlasWorldModelRtTest extends TestCase
{
    private AtlasWorldModelRtService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasWorldModelRtService();
    }

    public function testEntityAndEdgeTaxonomiesMatchTheClosedDocumentedSets(): void
    {
        // "Entidades" lists exactly 16 entity types; "Edges" lists exactly 11.
        $this->assertCount(16, AtlasWorldModelRtService::ENTITY_TYPES);
        $this->assertCount(11, AtlasWorldModelRtService::EDGE_TYPES);

        $this->assertContains('oportunidade', AtlasWorldModelRtService::ENTITY_TYPES);
        $this->assertContains('regulacao', AtlasWorldModelRtService::ENTITY_TYPES);
        $this->assertContains('competes_with', AtlasWorldModelRtService::EDGE_TYPES);
        $this->assertContains('creates_risk', AtlasWorldModelRtService::EDGE_TYPES);

        // An out-of-taxonomy type is rejected.
        $bad = $this->service->validateEntity([
            'type' => 'spaceship',
            'source' => 's',
            'date' => '2026-01-01',
            'claim_kind' => 'fact',
            'confidence' => 0.5,
        ]);
        $this->assertFalse($bad['valid']);
        $this->assertContains('unknown_entity_type', $bad['errors']);
    }

    public function testEntityWithoutSourceOrDateIsInvalid(): void
    {
        // Regras para IA: "Sempre registrar fonte e data" -> failure mode
        // "Fonte fraca vira fato".
        $result = $this->service->validateEntity([
            'type' => 'empresa',
            'name' => 'acme',
            'claim_kind' => 'inference',
            'confidence' => 0.7,
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('missing_source_or_date', $result['errors']);
        $this->assertFalse($result['is_fact']);
    }

    public function testCausalEdgeFromInferenceCannotAssertCausality(): void
    {
        // Regras para IA: "Nao transformar correlacao em causalidade."
        $inferred = $this->service->validateEdge([
            'type' => 'creates_opportunity',
            'from' => 'niche',
            'to' => 'product',
            'source' => 'analyst-note',
            'date' => '2026-05-25',
            'claim_kind' => 'inference',
        ]);
        $this->assertTrue($inferred['is_causal']);
        $this->assertFalse($inferred['asserts_causality']);
        $this->assertFalse($inferred['valid']);
        $this->assertContains('causal_from_inference', $inferred['errors']);

        // The same causal edge grounded in a fact may assert causality.
        $factual = $this->service->validateEdge([
            'type' => 'creates_opportunity',
            'from' => 'niche',
            'to' => 'product',
            'source' => 'measured-experiment',
            'date' => '2026-05-25',
            'claim_kind' => 'fact',
        ]);
        $this->assertTrue($factual['valid']);
        $this->assertTrue($factual['asserts_causality']);
    }

    public function testExpiredContextCannotDriveASensitiveDecision(): void
    {
        // Regras para IA: "Nao usar contexto expirado para decisao sensivel."
        // Age beyond the 90-day expiry window with a sensitive decision.
        $expired = $this->service->canUseForDecision(
            ['age_days' => 200, 'valid' => true, 'claim_kind' => 'fact'],
            true,
        );
        $this->assertSame('expired', $expired['freshness']);
        $this->assertFalse($expired['allowed']);
        $this->assertContains('expired_context_sensitive_decision', $expired['refusal_reasons']);

        // Same expired fact is advisory-only (not hard-refused) when the
        // decision is NOT sensitive.
        $advisory = $this->service->canUseForDecision(
            ['age_days' => 200, 'valid' => true, 'claim_kind' => 'fact'],
            false,
        );
        $this->assertTrue($advisory['allowed']);
        $this->assertTrue($advisory['advisory_only']);

        // A fresh fact (within 30 days) is fully usable for a sensitive decision.
        $fresh = $this->service->canUseForDecision(
            ['age_days' => 5, 'valid' => true, 'claim_kind' => 'fact'],
            true,
        );
        $this->assertSame('fresh', $fresh['freshness']);
        $this->assertTrue($fresh['allowed']);
        $this->assertFalse($fresh['advisory_only']);
    }

    public function testSnapshotIsBlockedUntilAllFourQualityGatesPass(): void
    {
        // One entity is sourceless + missing freshness/confidence -> the
        // sources/freshness/confidence gates cannot all pass, so the snapshot
        // is not usable (protects against "Contexto desatualizado").
        $blocked = $this->service->buildSnapshot([
            'entities' => [
                [
                    'type' => 'plataforma',
                    'source' => 'docs',
                    'date' => '2026-05-20',
                    'age_days' => 10,
                    'claim_kind' => 'fact',
                    'confidence' => 0.9,
                ],
                [
                    'type' => 'mercado',
                    'claim_kind' => 'inference',
                ],
            ],
        ]);
        $this->assertFalse($blocked['usable']);
        $this->assertSame('quality_gates_failed', $blocked['blocked_reason']);
        $this->assertContains('freshness-known', $blocked['quality_gates']['failed']);
        $this->assertContains('confidence-set', $blocked['quality_gates']['failed']);

        // A complete, fresh, sourced, confidence-set entity passes every gate.
        $usable = $this->service->buildSnapshot([
            'entities' => [
                [
                    'type' => 'plataforma',
                    'source' => 'docs',
                    'date' => '2026-05-20',
                    'age_days' => 10,
                    'claim_kind' => 'fact',
                    'confidence' => 0.9,
                ],
            ],
        ]);
        $this->assertTrue($usable['usable']);
        $this->assertSame([], $usable['quality_gates']['failed']);
        $this->assertTrue($usable['quality_gates']['passed']);
    }

    public function testEvidenceRequiresTheSevenDocumentedFields(): void
    {
        // "Evidencias": Fonte, data, trecho/resumo, confidence, hash,
        // entidade afetada e edge.
        $this->assertCount(7, AtlasWorldModelRtService::REQUIRED_EVIDENCE_FIELDS);

        $incomplete = $this->service->evidenceComplete([
            'source' => 's',
            'date' => '2026-01-01',
            'excerpt' => 'x',
            'confidence' => 0.5,
            // hash + entity missing; edge key absent
        ]);
        $this->assertFalse($incomplete['complete']);
        $this->assertContains('hash', $incomplete['missing']);
        $this->assertContains('entity', $incomplete['missing']);
        $this->assertContains('edge', $incomplete['missing']);

        $complete = $this->service->evidenceComplete([
            'source' => 's',
            'date' => '2026-01-01',
            'excerpt' => 'x',
            'confidence' => 0.5,
            'hash' => 'abc',
            'entity' => 'acme',
            'edge' => null, // legitimately null for a pure entity fact
        ]);
        $this->assertTrue($complete['complete']);
        $this->assertSame([], $complete['missing']);
    }

    public function testFlowExposesTheSevenOrderedSteps(): void
    {
        $flow = $this->service->flow();
        $this->assertSame(7, $flow['count']);
        $this->assertSame('identify_entities', $flow['steps'][0]['step']);
        $this->assertSame('consult_during_planning', $flow['steps'][5]['step']);
        $this->assertSame('update_with_outcomes', $flow['steps'][6]['step']);
    }
}
