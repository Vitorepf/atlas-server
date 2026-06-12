<?php

declare(strict_types=1);

namespace Tests\Feature\Cognition;

use App\Services\Ai\Cognition\AtlasCognitionEvidenceResolver;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use Tests\TestCase;

/**
 * L3-11: o mint de pipeline receipts MIRA os subsistemas `partial`.
 *
 * Antes, o mint varria TODAS as capabilities e a maioria não era owner de subsistema
 * partial → conversão baixíssima (20 mints → +0.05). A correção: o resolver expõe os
 * capability_ids que GOVERNAM um service_class (`ownerCapabilityIdsForFqn`), e o mint
 * usa o `build()` (subsystems no TOPO, não sob `report` — o path errado que zerava o
 * targeting) para priorizar exatamente os owners dos partials. Cada receipt verde então
 * flipa partial→ready (conversão real).
 *
 * Estes testes congelam o contrato do targeting sem depender de rodar PHPUnit real (o que
 * o mint faz ao vivo): (a) o resolver é degrade-safe (FQN inválido ⇒ []); (b) o build()
 * expõe `subsystems` no topo com `pipeline_status` por subsistema (o shape de que o
 * targeting depende — o teste falha se alguém re-aninhar sob `report`).
 */
final class AtlasPipelineReceiptTargetingTest extends TestCase
{
    public function test_resolver_owner_capability_ids_is_degrade_safe_for_invalid_fqn(): void
    {
        $resolver = app(AtlasCognitionEvidenceResolver::class);

        $this->assertSame([], $resolver->ownerCapabilityIdsForFqn(null));
        $this->assertSame([], $resolver->ownerCapabilityIdsForFqn(''));
        $this->assertSame([], $resolver->ownerCapabilityIdsForFqn('Nao\\Existe\\Classe'));
    }

    public function test_resolver_returns_a_list_of_strings_when_resolvable(): void
    {
        $resolver = app(AtlasCognitionEvidenceResolver::class);
        // Não depende de um FQN específico estar indexado (degrada para [] sem índice vivo),
        // mas o CONTRATO é sempre uma list<string>.
        $ids = $resolver->ownerCapabilityIdsForFqn('App\\Services\\Ai\\Aemor\\AtlasAemorRuntimeService');

        $this->assertIsArray($ids);
        foreach ($ids as $id) {
            $this->assertIsString($id);
            $this->assertNotSame('', $id);
        }
        // Sem duplicatas (array_unique).
        $this->assertSame(array_values(array_unique($ids)), $ids);
    }

    public function test_scorecard_build_exposes_subsystems_at_top_level_with_pipeline_status(): void
    {
        // O targeting do mint lê `data_get($card, 'subsystems')` (TOPO). Se alguém re-aninhar
        // sob `report`, o targeting silenciosamente zera (o bug que deu Δ+0). Este teste é o
        // ratchet do shape: subsystems no topo + cada um com pipeline_status.
        $card = app(AtlasCognitionScoreCardService::class)->build();

        $this->assertArrayHasKey('subsystems', $card, 'subsystems deve estar no TOPO do build()');
        $this->assertNotEmpty($card['subsystems']);
        $first = $card['subsystems'][0];
        $this->assertArrayHasKey('pipeline_status', $first);
        $this->assertArrayHasKey('service_class', $first);
        $this->assertContains($first['pipeline_status'], [
            AtlasCognitionScoreCardService::STATUS_READY,
            AtlasCognitionScoreCardService::STATUS_PARTIAL,
            AtlasCognitionScoreCardService::STATUS_BUILDING,
            'blocked',
        ]);
    }

    public function test_pipeline_dimension_is_resolved_evidence_never_self_declared(): void
    {
        $card = app(AtlasCognitionScoreCardService::class)->build();
        $pipeline = $card['score']['dimensions']['pipeline'] ?? null;

        $this->assertIsArray($pipeline);
        $this->assertArrayHasKey('score_out_of_10', $pipeline);
        // O score é função de sum/max reais (receipts resolvidos), não um literal.
        $this->assertArrayHasKey('sum', $pipeline);
        $this->assertArrayHasKey('max', $pipeline);
        $this->assertGreaterThan(0, $pipeline['max']);
    }
}
