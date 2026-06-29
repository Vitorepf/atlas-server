<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the meta-objective proposer is live at the operator surface: a CREDITED capability-Δ shape on an
 * allowed area becomes a deterministic proposal, a credited shape on a pétreo forbidden-self-target is DROPPED
 * (never emitted), and an uncredited/thin shape earns nothing.
 */
final class AtlasLoopMetaObjectiveProposeCommandTest extends TestCase
{
    public function test_meta_objective_proposals_emit_from_credited_shapes_and_drop_forbidden(): void
    {
        $this->app->bind('atlas.loop.meta_objective.attribution_by_shape', fn (): array => [
            // credited shape on an allowed area => becomes a proposal
            ['shape_token' => 'wiring', 'target_area' => 'app/Services/Ai/SomeArea', 'samples' => 7, 'mean_delta' => 0.8, 'wilson_lower_bound' => 0.5, 'credited' => true],
            // credited shape on a PÉTREO area (the frozen judge) => dropped, never emitted
            ['shape_token' => 'judge-edit', 'target_area' => 'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php', 'samples' => 9, 'mean_delta' => 1.0, 'wilson_lower_bound' => 0.6, 'credited' => true],
            // uncredited/thin shape => ignored entirely
            ['shape_token' => 'thin', 'target_area' => 'app/Services/Ai/OtherArea', 'samples' => 1, 'mean_delta' => 0.1, 'wilson_lower_bound' => 0.0, 'credited' => false],
        ]);

        $exit = Artisan::call('atlas:loop:meta-objective-propose', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.meta_objective_proposal.v1', $decoded['schema']);

        $areas = array_column($decoded['proposals'], 'target_area');
        $this->assertContains('app/Services/Ai/SomeArea', $areas);
        $this->assertNotContains('app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php', $areas);
        $this->assertNotContains('app/Services/Ai/OtherArea', $areas); // uncredited never proposes

        $dropped = array_column($decoded['dropped'], 'reason', 'target_area');
        $this->assertSame('forbidden_self_target', $dropped['app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php']);
    }
}
