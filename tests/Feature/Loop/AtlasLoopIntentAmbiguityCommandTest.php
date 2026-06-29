<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the intent-meaning ambiguity detector is live at the operator surface (via atlas:loop:intent-ambiguity):
 * triangulation facts grounding to two distinct (symbol,file) sites are flagged ambiguous; facts grounding to a
 * single site are unambiguous.
 */
final class AtlasLoopIntentAmbiguityCommandTest extends TestCase
{
    private function detect(array $facts): array
    {
        $exit = Artisan::call('atlas:loop:intent-ambiguity', [
            '--facts' => (string) json_encode($facts),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_conflicting_facts_are_ambiguous(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->detect([
            ['symbol' => 'Foo', 'file' => 'a.php'],
            ['symbol' => 'Bar', 'file' => 'b.php'], // two distinct sites ⇒ ambiguous
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.intent_ambiguity.v1', $d['schema']);
        $this->assertTrue($d['ambiguous'], (string) json_encode($d));
        $this->assertSame(2, $d['site_count']);
        $this->assertTrue($d['clarification_required']);
    }

    public function test_single_site_facts_are_unambiguous(): void
    {
        ['d' => $d] = $this->detect([
            ['symbol' => 'Foo', 'file' => 'a.php'],
            ['symbol' => 'Foo', 'file' => 'a.php'], // same site repeated ⇒ unique
        ]);

        $this->assertFalse($d['ambiguous']);
        $this->assertSame(1, $d['site_count']);
        $this->assertSame('unique_grounded_site', $d['reason']);
    }

    public function test_missing_facts_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:intent-ambiguity', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
