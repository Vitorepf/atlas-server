<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the chaos-signal labeler is live at the operator surface: a negative event WITH a shape_token mints an
 * attributable lesson whose label carries the shape_token, while an event WITHOUT one is unattributable. Events
 * are injected so the test touches no live disk.
 */
final class AtlasLoopChaosLessonsCommandTest extends TestCase
{
    public function test_chaos_lessons_mints_attributable_and_unattributable(): void
    {
        $this->app->bind(
            'atlas.loop.chaos_lessons.event_source',
            fn (): Closure => fn (int $limit): array => [
                ['kind' => 'given_back', 'shape_token' => 'orphan_wiring_proxy', 'provider' => 'codex', 'reason' => 'acceptance_not_runnable'],
                ['kind' => 'given_back', 'shape_token' => '', 'provider' => 'codex', 'reason' => 'unknown'],
            ],
        );

        $exit = Artisan::call('atlas:loop:chaos-lessons', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertCount(2, $decoded['lessons']);

        $attributable = $decoded['lessons'][0];
        $this->assertTrue($attributable['attributable']);
        $this->assertSame('orphan_wiring_proxy', $attributable['shape_token']);
        $this->assertStringContainsString('orphan_wiring_proxy', $attributable['label']);
        $this->assertNotNull($attributable['lesson']);

        $unattributable = $decoded['lessons'][1];
        $this->assertFalse($unattributable['attributable'], 'no shape_token ⇒ unattributable, no fabricated lesson');
        $this->assertNull($unattributable['lesson']);
    }
}
