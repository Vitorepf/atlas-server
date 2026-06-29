<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDecompositionArchetypeOracle;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the decomposition-archetype oracle is live at the operator surface: a goal whose tokens match a frozen
 * archetype reports that archetype + its invariants; a non-matching goal honestly reports matched=false.
 */
final class AtlasLoopDecompositionArchetypeCommandTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-archetypes-'.bin2hex(random_bytes(5));
        @mkdir($this->dir, 0o755, true);
        file_put_contents($this->dir.'/service-extraction.json', json_encode([
            'id' => 'service-extraction',
            'match_all' => ['extract'],
            'match_any' => ['service', 'class'],
            'min_nodes' => 2,
            'required_create_suffixes' => ['Service.php'],
            'min_distinct_targets' => 2,
        ]));

        // Inject the frozen archetype library via the oracle's constructor dir.
        $this->app->bind(
            AtlasLoopDecompositionArchetypeOracle::class,
            fn (): AtlasLoopDecompositionArchetypeOracle => new AtlasLoopDecompositionArchetypeOracle($this->dir),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/service-extraction.json');
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_matching_goal_classifies_into_archetype(): void
    {
        $exit = Artisan::call('atlas:loop:decomposition-archetype', [
            '--goal' => 'Extract a new payment Service from the monolith',
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.decomposition_archetype.v1', $decoded['schema']);
        $this->assertTrue($decoded['matched'], (string) json_encode($decoded));
        $this->assertSame('service-extraction', $decoded['archetype']['id']);
        $this->assertSame(2, $decoded['archetype']['min_nodes']);
        $this->assertContains('Service.php', $decoded['archetype']['required_create_suffixes']);
    }

    public function test_non_matching_goal_reports_no_archetype(): void
    {
        $exit = Artisan::call('atlas:loop:decomposition-archetype', [
            '--goal' => 'tweak the homepage css colors',
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertFalse($decoded['matched']);
        $this->assertNull($decoded['archetype']);
    }

    public function test_missing_goal_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:decomposition-archetype', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
