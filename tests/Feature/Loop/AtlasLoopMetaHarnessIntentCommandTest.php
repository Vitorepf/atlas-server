<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the meta-harness intent source is live at the operator surface and emits deterministic facts. With
 * either gate OFF (provider-safe default) it yields no candidates; with both gates ON it enumerates real
 * non-pétreo harness files (guard-filtered, limit-respected) as self-improvement intents.
 */
final class AtlasLoopMetaHarnessIntentCommandTest extends TestCase
{
    public function test_emits_no_candidates_when_gates_off(): void
    {
        config([
            'atlas.loop.meta_harness_targets' => false,
            'atlas.loop.meta_harness_self_improve.enabled' => false,
        ]);

        $decoded = $this->invoke(6);

        $this->assertSame('atlas.loop.meta_harness_intent.v1', $decoded['schema']);
        $this->assertSame(0, $decoded['count']);
        $this->assertSame([], $decoded['candidates']);
    }

    public function test_emits_guard_filtered_candidates_when_gates_on(): void
    {
        config([
            'atlas.loop.meta_harness_targets' => true,
            'atlas.loop.meta_harness_self_improve.enabled' => true,
            'atlas.loop.meta_harness_self_improve.max_candidates' => 50,
        ]);

        $decoded = $this->invoke(3);

        $this->assertGreaterThanOrEqual(1, $decoded['count']);
        $this->assertLessThanOrEqual(3, $decoded['count']); // limit honoured

        foreach ($decoded['candidates'] as $candidate) {
            $this->assertStringStartsWith('app/Services/Ai/AutonomousEvolution/', $candidate['path']);
            $this->assertSame('meta_harness_self_improve', $candidate['source']);
            // never a pétreo self-target (guard chokepoint)
            $this->assertStringNotContainsString('AtlasEvolutionFrozenJudge', $candidate['path']);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function invoke(int $limit): array
    {
        $exit = Artisan::call('atlas:loop:meta-harness-intent', ['--limit' => $limit, '--json' => true]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
