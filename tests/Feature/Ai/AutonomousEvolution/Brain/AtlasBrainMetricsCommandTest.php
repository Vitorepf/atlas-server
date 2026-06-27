<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AtlasBrainMetricsCommandTest extends TestCase
{
    public function test_metrics_emits_prometheus_textfile_lines(): void
    {
        $base = sys_get_temp_dir().'/atlas-brain-metrics-'.bin2hex(random_bytes(4));
        @mkdir($base.'/done-set', 0o775, true);
        config()->set('atlas.brain.done_set_root', $base.'/done-set');
        config()->set('atlas.brain.scopes.loop', ['label' => 't', 'roots' => [], 'docs_roots' => [], 'meta_harness' => true]);
        config()->set('atlas.brain.default_scope', 'loop');

        $buf = new BufferedOutput;
        Artisan::call('atlas:brain:metrics', [], $buf);
        $out = trim($buf->fetch());

        // Spot-check several Prometheus-textfile lines.
        self::assertStringContainsString('atlas_brain_score{scope="loop"} ', $out);
        self::assertStringContainsString('atlas_brain_gates_holes{scope="loop"} 0', $out);
        self::assertStringContainsString('atlas_brain_served_ratio_pct{scope="loop"} ', $out);
        self::assertStringContainsString('atlas_brain_origination_gap_cycles{scope="loop"} ', $out);
        self::assertStringContainsString('atlas_brain_evidence_age_seconds{scope="loop"} -1', $out);
        self::assertStringContainsString('# TYPE atlas_brain_score gauge', $out);
        self::assertStringContainsString('# HELP atlas_brain_score Composite', $out);
    }

    public function test_metrics_command_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit('app/Console/Commands/AtlasBrainMetricsCommand.php', true);
        self::assertSame('forbidden', $verdict);
    }
}
