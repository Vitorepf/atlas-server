<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the V4 meta-objective gate is live at the operator surface: a meta-objective citing a confounded
 * (unattributed) capability dimension is refused; a non-originated one is refused; a well-attributed one is
 * admitted.
 */
final class AtlasLoopMetaObjectiveGateCommandTest extends TestCase
{
    private function admit(array $metaObjective, array $attribution): array
    {
        $exit = Artisan::call('atlas:loop:meta-objective-gate', [
            '--meta-objective' => (string) json_encode($metaObjective),
            '--attribution' => (string) json_encode($attribution),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_confounded_citation_is_refused(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->admit(
            ['originated' => true, 'target_metric' => 'capability', 'cited_facts' => ['capability.speed=0.9']],
            ['attributed' => true, 'unattributed_dimensions' => ['speed']], // speed moved unclaimed ⇒ confound
        );

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.v4_meta_objective_gate.v1', $d['schema']);
        $this->assertFalse($d['admitted'], (string) json_encode($d));
        $this->assertSame('cites_confounded_capability_dimension', $d['refuse_reason']);
        $this->assertContains('capability.speed=0.9', $d['confounded_citations']);
    }

    public function test_not_originated_is_refused(): void
    {
        ['d' => $d] = $this->admit(
            ['originated' => false, 'cited_facts' => []],
            ['attributed' => true, 'unattributed_dimensions' => []],
        );

        $this->assertFalse($d['admitted']);
        $this->assertSame('upstream_not_originated', $d['refuse_reason']);
    }

    public function test_well_attributed_meta_objective_is_admitted(): void
    {
        ['d' => $d] = $this->admit(
            ['originated' => true, 'target_metric' => 'capability', 'cited_facts' => ['capability.speed=0.9']],
            ['attributed' => true, 'unattributed_dimensions' => []],
        );

        $this->assertTrue($d['admitted'], (string) json_encode($d));
        $this->assertNull($d['refuse_reason']);
        $this->assertSame([], $d['confounded_citations']);
    }

    public function test_missing_attribution_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:meta-objective-gate', ['--meta-objective' => '{}', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
