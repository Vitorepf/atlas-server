<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopObraExecutionAdapter;
use ReflectionClass;
use Tests\TestCase;

/**
 * ACDE #7 — cross-node consumer-cert by DEFAULT for a multi-file (>=2-node) obra. The decision (force the
 * completeness gate + resolve the Code-Intelligence workspace, repo fallback) is a pure function of the
 * changed-file count + flags. Default-OFF => byte-identical; single-file diffs are NEVER affected.
 *
 * FLOOR: the gate only ever ADDS criteria / coverage — it can never admit an unproven obra.
 */
final class AtlasLoopObraCrossNodeGateTest extends TestCase
{
    private function gate(array $changed, array $envelope, string $repoRoot = '/repo'): array
    {
        $adapter = (new ReflectionClass(AtlasLoopObraExecutionAdapter::class))->newInstanceWithoutConstructor();
        $m = (new ReflectionClass($adapter))->getMethod('obraCrossNodeGate');
        $m->setAccessible(true);

        return (array) $m->invoke($adapter, $changed, $envelope, $repoRoot);
    }

    public function test_off_is_byte_identical_even_for_multi_file(): void
    {
        config()->set('atlas.loop.obra_completeness_gate_enabled', false);
        config()->set('atlas.loop.obra_cross_node_cert_default', false);

        $g = $this->gate(['a.php', 'b.php'], []);
        $this->assertFalse($g['completeness_gate'], 'OFF => completeness gate not forced');
        $this->assertNull($g['code_graph_workspace'], 'OFF => no repo fallback (byte-identical)');
    }

    public function test_default_engages_for_multi_file_and_falls_back_to_repo(): void
    {
        config()->set('atlas.loop.obra_completeness_gate_enabled', false);
        config()->set('atlas.loop.obra_cross_node_cert_default', true);

        $g = $this->gate(['a.php', 'b.php', 'c.php'], [], '/repo/root');
        $this->assertTrue($g['completeness_gate'], 'multi-file + default ON => completeness forced');
        $this->assertSame('/repo/root', $g['code_graph_workspace'], 'missing envelope workspace falls back to the obra repo so contracts populate');
    }

    public function test_default_does_not_touch_single_file(): void
    {
        config()->set('atlas.loop.obra_completeness_gate_enabled', false);
        config()->set('atlas.loop.obra_cross_node_cert_default', true);

        $g = $this->gate(['only.php'], [], '/repo/root');
        $this->assertFalse($g['completeness_gate'], 'single-file obra is never affected by the multi-file default');
        $this->assertNull($g['code_graph_workspace'], 'single-file => no repo fallback');
    }

    public function test_envelope_workspace_is_preserved_not_overridden(): void
    {
        config()->set('atlas.loop.obra_cross_node_cert_default', true);

        $g = $this->gate(['a.php', 'b.php'], ['code_graph_workspace' => '/indexed/ws'], '/repo/root');
        $this->assertSame('/indexed/ws', $g['code_graph_workspace'], 'a real envelope workspace wins over the repo fallback');
    }

    public function test_global_completeness_flag_still_engages_single_file(): void
    {
        // the pre-existing global path is unchanged: it forces the gate regardless of file count.
        config()->set('atlas.loop.obra_completeness_gate_enabled', true);
        config()->set('atlas.loop.obra_cross_node_cert_default', false);

        $g = $this->gate(['only.php'], ['code_graph_workspace' => '/ws']);
        $this->assertTrue($g['completeness_gate'], 'global flag forces the gate as before');
        $this->assertSame('/ws', $g['code_graph_workspace']);
    }
}
