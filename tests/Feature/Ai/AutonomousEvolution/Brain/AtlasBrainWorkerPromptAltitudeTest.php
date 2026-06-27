<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * S1 — prompt ALTITUDE split. The originate prompt must separate the pétreo HARD-CONSTRAINT block from the
 * high-altitude AMBITION block, with no hard floor downgraded to a suggestion. This is the context-engineering
 * lesson: brittle if-else AND vague platitude both fail; the floor stays hard, the ambition stays heuristic.
 */
final class AtlasBrainWorkerPromptAltitudeTest extends TestCase
{
    private function prompt(): string
    {
        Artisan::call('atlas:brain:worker-prompt', ['--scope' => 'autonomous', '--mode' => 'originate']);

        return Artisan::output();
    }

    public function test_prompt_has_distinct_constraint_and_ambition_blocks_in_altitude_order(): void
    {
        $out = $this->prompt();

        $constraintPos = strpos($out, 'HARD CONSTRAINTS');
        $ambitionPos = strpos($out, 'AMBITION');

        self::assertNotFalse($constraintPos, 'a labeled HARD CONSTRAINTS block exists');
        self::assertNotFalse($ambitionPos, 'a labeled AMBITION block exists');
        self::assertLessThan($ambitionPos, $constraintPos, 'the hard floor comes before ambition (low altitude first)');
    }

    public function test_p_etre_o_invariants_survive_verbatim_in_the_constraint_block(): void
    {
        $out = $this->prompt();

        // Every load-bearing floor invariant must still be present and stated as a hard rule, not softened.
        self::assertStringContainsString('NEVER edit app/', $out);
        self::assertStringContainsString('NEVER turn the brain switch on', $out);
        self::assertStringContainsString('NO proxy/faxina', $out);
        self::assertStringContainsString('author≠judge', $out);
        // and the explicit "stop only on an Atlas signal" rule (never self-declared dry)
        self::assertStringContainsString('STOP only on an ATLAS signal', $out);
    }

    public function test_ambition_block_mandates_portfolio_rotation_and_no_self_stop(): void
    {
        $out = $this->prompt();

        self::assertStringContainsString('ROTATE the self-improvement portfolio', $out);
        self::assertStringContainsString('most exponential lift', $out);
        self::assertStringContainsString('NOT a stop', $out);
    }
}
