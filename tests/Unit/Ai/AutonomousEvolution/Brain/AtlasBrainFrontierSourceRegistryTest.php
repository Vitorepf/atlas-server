<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainFrontierSourceRegistry;
use Tests\TestCase;

/**
 * FROZEN proof of the frontier-source registry — proves the append/topK round-trip, fail-closed on missing
 * fields, newest-first bounded read, empty-file gracefulness, and the pétreo contract.
 */
final class AtlasBrainFrontierSourceRegistryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-brain-frontier-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            foreach (glob($this->root.'/*.ndjson') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    private function reg(): AtlasBrainFrontierSourceRegistry
    {
        return new AtlasBrainFrontierSourceRegistry($this->root);
    }

    public function test_empty_scope_returns_empty(): void
    {
        self::assertSame([], $this->reg()->topK('loop'));
    }

    public function test_append_then_top_k_is_newest_first_and_bounded(): void
    {
        $reg = $this->reg();
        // Append 4 in order; topK(2) must return the LAST two in newest-first order.
        $reg->append('loop', ['title' => 'A', 'source' => 'github', 'captured_at' => '2026-06-25T00:00Z']);
        $reg->append('loop', ['title' => 'B', 'source' => 'github', 'captured_at' => '2026-06-26T00:00Z']);
        $reg->append('loop', ['title' => 'C', 'source' => 'github', 'captured_at' => '2026-06-27T00:00Z']);
        $reg->append('loop', ['title' => 'D', 'source' => 'github', 'captured_at' => '2026-06-28T00:00Z']);

        $top = $reg->topK('loop', 2);

        self::assertCount(2, $top);
        self::assertSame('D', $top[0]['title'], 'newest-first');
        self::assertSame('C', $top[1]['title']);
    }

    public function test_append_fail_closed_on_missing_title_or_source(): void
    {
        $reg = $this->reg();

        self::assertNull($reg->append('loop', ['title' => '', 'source' => 'github']));
        self::assertNull($reg->append('loop', ['title' => 'no source', 'source' => '   ']));
        self::assertSame([], $reg->topK('loop'));
    }

    public function test_per_scope_isolation(): void
    {
        $reg = $this->reg();
        $reg->append('loop', ['title' => 'loop-candidate', 'source' => 'arxiv']);
        $reg->append('muscle', ['title' => 'muscle-candidate', 'source' => 'arxiv']);

        self::assertCount(1, $reg->topK('loop'));
        self::assertSame('loop-candidate', $reg->topK('loop')[0]['title']);
        self::assertCount(1, $reg->topK('muscle'));
    }

    public function test_top_k_zero_returns_empty(): void
    {
        $reg = $this->reg();
        $reg->append('loop', ['title' => 'X', 'source' => 'github']);

        self::assertSame([], $reg->topK('loop', 0));
    }

    public function test_registry_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainFrontierSourceRegistry.php',
            true
        );

        self::assertSame('forbidden', $verdict);
    }
}
