<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition;

use App\Services\Ai\Cognition\AtlasAcosEvolutionScoreService;
use Tests\TestCase;

/**
 * O instrumento das 3 notas é evidence-resolved e degrade-safe: em ambiente de
 * teste (sqlite :memory:, sem heartbeat/receipts reais) as probes degradam para
 * 0 honesto — nunca crash, nunca ready fabricado.
 */
class AtlasAcosEvolutionScoreServiceTest extends TestCase
{
    public function test_envelope_shape_and_degrade_safety(): void
    {
        $report = app(AtlasAcosEvolutionScoreService::class)->build();

        $this->assertSame(AtlasAcosEvolutionScoreService::SCHEMA_VERSION, $report['schema_version']);
        $this->assertIsFloat($report['overall_out_of_10'] + 0.0);

        foreach (['execucao_provada', 'inteligencia_entregue', 'autonomia'] as $dimension) {
            $this->assertArrayHasKey($dimension, $report['dimensions']);
            $d = $report['dimensions'][$dimension];
            $this->assertGreaterThanOrEqual(0.0, $d['score']);
            $this->assertLessThanOrEqual(10.0, $d['score']);
            $this->assertNotEmpty($d['signals']);
            foreach ($d['signals'] as $signal) {
                $this->assertLessThanOrEqual($signal['max'], $signal['points']);
                $this->assertNotSame('', (string) $signal['evidence']);
            }
        }

        // A média das 3 dimensões é a nota geral (arredondada a 2 casas).
        $expected = round((
            $report['dimensions']['execucao_provada']['score']
            + $report['dimensions']['inteligencia_entregue']['score']
            + $report['dimensions']['autonomia']['score']
        ) / 3, 2);
        $this->assertSame($expected, $report['overall_out_of_10']);

        $this->assertStringStartsWith('sha256:', (string) $report['score_hash']);
    }

    public function test_missing_stores_yield_honest_zero_not_crash(): void
    {
        // Em sqlite :memory: as tabelas de feedback/candidates/aurg não existem:
        // as parcelas correspondentes precisam pontuar 0 com evidência explicando.
        $report = app(AtlasAcosEvolutionScoreService::class)->build();

        $signals = collect($report['dimensions']['inteligencia_entregue']['signals'])->keyBy('signal');
        $this->assertSame(0.0, (float) $signals['pack_anti_lixo']['points']);
        $this->assertSame('store ausente (0 honesto)', $signals['pack_anti_lixo']['evidence']);
    }
}
