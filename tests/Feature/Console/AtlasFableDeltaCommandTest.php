<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * L2-7: o medidor do exponencial. O delta compara HOJE vs Marco Zero com fontes VIVAS
 * (scorecard resolved-evidence, tabela do Loop, runtime semântico) — nunca números
 * declarados. Congelado: o comando resolve, o shape carrega baseline/current/delta.
 */
final class AtlasFableDeltaCommandTest extends TestCase
{
    public function test_delta_resolves_against_a_baseline_with_live_sources(): void
    {
        $baseline = sys_get_temp_dir().'/marco-zero-test-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($baseline, json_encode([
            'recorded_at' => '2026-06-11',
            'baseline' => [
                'maturity_scorecard' => ['acos_overall' => 5.0],
                'learning_capture_quality_7d' => ['gate_mode' => 'observe'],
            ],
        ]));

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:fable:delta', ['--baseline' => $baseline, '--json' => true], $out);
        @unlink($baseline);

        $this->assertSame(0, $exit);
        $report = json_decode($out->fetch(), true);
        $this->assertSame('atlas.fable.delta.v1', $report['schema_version']);

        $m = $report['metrics']['scorecard_overall'];
        $this->assertEqualsWithDelta(5.0, $m['baseline'], 0.001);
        $this->assertIsNumeric($m['current']);
        $this->assertEqualsWithDelta($m['current'] - $m['baseline'], $m['delta'], 0.001, 'delta = current - baseline, resolvido');
        $this->assertArrayHasKey('sources', $report, 'todas as métricas declaram a fonte viva');
    }

    public function test_missing_baseline_fails_closed(): void
    {
        $exit = Artisan::call('atlas:fable:delta', ['--baseline' => '/nonexistent/mz.json']);
        $this->assertSame(1, $exit);
    }
}
