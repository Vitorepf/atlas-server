<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * PART 2 · B4 — the comprehension CLI dumps grounded FACTS for a scope (read-only, never a score).
 */
final class AtlasLoopComprehendCommandTest extends TestCase
{
    public function test_dumps_grounded_facts_for_the_fixture_scope(): void
    {
        $exit = Artisan::call('atlas:loop:comprehend', [
            'scope' => 'app/Scope',
            '--repo' => base_path('tests/Fixtures/loop-comprehension-scope'),
            '--docs' => ['docs'],
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $raw = Artisan::output();
        $out = json_decode($raw, true);
        $this->assertIsArray($out);
        $this->assertSame('atlas.loop.comprehend.v1', $out['schema']);

        // Grounded structural facts (same oracles the Part-1 model proves).
        $this->assertContains('App\\Scope\\Orphan', $out['orphans']);
        $this->assertContains('App\\Scope\\MissingCapability', $out['doc_stated_gaps']);
        $this->assertNotEmpty($out['units']);

        // The orphan unit carries the named transition + boolean level_vector (FACTS, not a number).
        $orphan = null;
        foreach ($out['units'] as $u) {
            if ($u['fqcn'] === 'App\\Scope\\Orphan') {
                $orphan = $u;
                break;
            }
        }
        $this->assertNotNull($orphan);
        $this->assertContains('orphan->wired', $orphan['transitions']);
        $this->assertTrue($orphan['level_vector']['orphan']);

        // PÉTREO: no score / rank / level number anywhere in the dumped facts.
        $this->assertStringNotContainsString('"score"', $raw);
        $this->assertStringNotContainsString('"rank"', $raw);
    }
}
