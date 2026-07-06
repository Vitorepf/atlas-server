<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Engineering\EngineeringRefactorCensusService;
use Tests\TestCase;

final class EngineeringRefactorCensusCommandTest extends TestCase
{
    private const FIXTURES = 'tests/Fixtures/refactor_census';

    public function test_command_emits_canonical_envelope_with_clusters_and_flags(): void
    {
        $exit = \Illuminate\Support\Facades\Artisan::call('atlas:engineering:refactor-census', [
            'path' => base_path(self::FIXTURES),
            '--min-cluster' => 2,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $report = json_decode(\Illuminate\Support\Facades\Artisan::output(), true, 64, JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.engineering.refactor_census.v1', $report['schema_version']);
        $this->assertSame('ok', $report['status']);
        $this->assertTrue($report['claim_policy']['read_only']);
        $this->assertStringContainsString('superestimam 3-10x', $report['note']);

        $this->assertSame(5, $report['metrics']['files_scanned']);
        $this->assertSame(2, $report['metrics']['clusters']);
        $this->assertSame(1, $report['metrics']['candidatos_pagantes']);

        $byMethod = [];
        foreach ($report['clusters'] as $cluster) {
            $byMethod[$cluster['methods'][0]] = $cluster;
        }

        // Cluster byte-idêntico plantado 4x em 4 arquivos → pagante.
        $planted = $byMethod['hydratePayload'];
        $this->assertSame(4, $planted['sites']);
        $this->assertCount(4, $planted['files']);
        $this->assertSame('pagante', $planted['flag']);
        $this->assertGreaterThan(0, $planted['loc_liquida_estimada']);

        // Par com corpo ≤3 linhas → regra de calibração: nao_paga.
        $tiny = $byMethod['tinyPair'];
        $this->assertSame(2, $tiny['sites']);
        $this->assertSame('nao_paga', $tiny['flag']);
        $this->assertLessThanOrEqual(0, $tiny['loc_liquida_estimada']);

        // Sinal shingle cross-file do prover reusa o cluster plantado.
        $this->assertNotEmpty($report['shingle_cross_file']);
        $this->assertCount(4, $report['shingle_cross_file'][0]['files']);
    }

    public function test_default_min_cluster_hides_the_pair(): void
    {
        $report = app(EngineeringRefactorCensusService::class)->census(base_path(self::FIXTURES));

        $this->assertSame(4, $report['min_cluster']);
        $this->assertSame(1, $report['metrics']['clusters']);
        $this->assertSame(['hydratePayload'], $report['clusters'][0]['methods']);
    }

    public function test_missing_path_yields_empty_status(): void
    {
        $report = app(EngineeringRefactorCensusService::class)->census(base_path('tests/Fixtures/refactor_census_missing'));

        $this->assertSame('empty', $report['status']);
        $this->assertSame(0, $report['metrics']['files_scanned']);
        $this->assertTrue($report['claim_policy']['read_only']);
    }
}
