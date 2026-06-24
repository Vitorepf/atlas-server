<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\BehaviorDelta;

use App\Services\Ai\AutonomousEvolution\BehaviorDelta\AtlasLoopBehaviorDeltaSnapshotter;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Proves AtlasLoopBehaviorDeltaSnapshotter is a PURE, deterministic capture of a scope's behavior surface:
 * byte-identical output for the same (repoRoot, scopeRoot), well-formed per-symbol facts (fqcn + signature
 * hash + sorted caller_fqcns), and a source free of write/provider tokens.
 */
final class AtlasLoopBehaviorDeltaSnapshotterTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = sys_get_temp_dir().'/atlas-behavior-delta-'.bin2hex(random_bytes(6));
        mkdir($this->repoRoot.'/src', 0o755, true);

        file_put_contents(
            $this->repoRoot.'/src/Foo.php',
            "<?php\nnamespace Demo;\nclass Foo\n{\n    public function alpha(int \$n): int { return \$n + 1; }\n    public function beta(): string { return 'b'; }\n}\n",
        );
        file_put_contents(
            $this->repoRoot.'/src/Bar.php',
            "<?php\nnamespace Demo;\nclass Bar\n{\n    public function useFoo(): int { return (new \\Demo\\Foo)->alpha(2); }\n}\n",
        );
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->repoRoot]))->run();
        parent::tearDown();
    }

    private function snapshotter(): AtlasLoopBehaviorDeltaSnapshotter
    {
        return new AtlasLoopBehaviorDeltaSnapshotter;
    }

    public function test_same_input_yields_byte_identical_output(): void
    {
        $run1 = $this->snapshotter()->snapshot($this->repoRoot, 'src');
        $run2 = $this->snapshotter()->snapshot($this->repoRoot, 'src');

        $this->assertSame(
            json_encode($run1, JSON_UNESCAPED_SLASHES),
            json_encode($run2, JSON_UNESCAPED_SLASHES),
            'same (repoRoot, scopeRoot) on an unchanged scope must be byte-identical',
        );
    }

    public function test_each_symbol_carries_fqcn_signature_hash_and_sorted_caller_list(): void
    {
        $snap = $this->snapshotter()->snapshot($this->repoRoot, 'src');

        $this->assertSame('atlas.loop.behavior_delta_snapshot.v1', $snap['schema']);
        $this->assertArrayHasKey('captured_at', $snap);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $snap['api_surface_hash']);
        $this->assertNotEmpty($snap['symbols'], 'the two demo classes are inventoried');

        $fqcns = [];
        foreach ($snap['symbols'] as $symbol) {
            $this->assertArrayHasKey('fqcn', $symbol);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $symbol['public_api_signature_hash']);
            $this->assertIsArray($symbol['caller_fqcns']);
            $sorted = $symbol['caller_fqcns'];
            sort($sorted, SORT_STRING);
            $this->assertSame($sorted, $symbol['caller_fqcns'], 'caller_fqcns must be sorted');
            $fqcns[] = $symbol['fqcn'];
        }

        $this->assertContains('Demo\\Foo', $fqcns);
        $this->assertContains('Demo\\Bar', $fqcns);
        $sortedFqcns = $fqcns;
        sort($sortedFqcns, SORT_STRING);
        $this->assertSame($sortedFqcns, $fqcns, 'symbols are ordered deterministically by fqcn');
    }

    public function test_source_contains_no_write_or_provider_tokens(): void
    {
        $source = (string) file_get_contents(
            base_path('app/Services/Ai/AutonomousEvolution/BehaviorDelta/AtlasLoopBehaviorDeltaSnapshotter.php'),
        );

        foreach (['Process', 'Http', 'DB::insert', 'DB::update', 'fwrite', 'file_put_contents', 'Storage::put'] as $token) {
            $this->assertStringNotContainsString($token, $source, "snapshotter source must not contain '{$token}' (pure read-only)");
        }
    }
}
