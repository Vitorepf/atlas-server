<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\AtlasCortexIntentSnapshot;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\TriangulatedIntentFact;
use PHPUnit\Framework\TestCase;

/**
 * Proves the cortex intent snapshot: it attaches a fact per item, persists sorted-key JSON whose per-item
 * payload is byte-identical across builds (only top-level generated_at differs), offers null-safe per-fqcn
 * lookup, and reports a fact-only summary.
 */
final class AtlasCortexIntentSnapshotTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-cortex-'.bin2hex(random_bytes(6)).'/intent-snapshot.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(dirname($this->path));
        parent::tearDown();
    }

    private function fact(string $fqcn, ?string $purpose, array $conflicts = []): TriangulatedIntentFact
    {
        return new TriangulatedIntentFact($fqcn, $purpose, ['extractor' => [], 'history' => [], 'siblings' => []], $purpose !== null ? 80 : 0, $conflicts);
    }

    /** @return list<array{fqcn:string}> */
    private function items(): array
    {
        return [
            ['fqcn' => 'App\\Z\\Last'],
            ['fqcn' => 'App\\A\\First'],
            ['fqcn' => 'App\\M\\Mid'],
        ];
    }

    private function resolver(): callable
    {
        $map = [
            'App\\Z\\Last' => $this->fact('App\\Z\\Last', 'purpose of last', ['some_conflict']),
            'App\\A\\First' => $this->fact('App\\A\\First', 'purpose of first'),
            'App\\M\\Mid' => $this->fact('App\\M\\Mid', null), // no purpose
        ];

        return fn (array $item): TriangulatedIntentFact => $map[$item['fqcn']];
    }

    public function test_persists_sorted_keys_and_summary(): void
    {
        $snapshot = new AtlasCortexIntentSnapshot($this->path);
        $doc = $snapshot->build($this->items(), $this->resolver(), '2026-06-24T00:00:00+00:00');

        $this->assertFileExists($this->path);
        $this->assertSame(['App\\A\\First', 'App\\M\\Mid', 'App\\Z\\Last'], array_keys($doc['items']), 'items sorted by fqcn');
        $this->assertSame(['items_total' => 3, 'items_with_purpose' => 2, 'items_with_conflicts' => 1], $doc['summary']);
        $this->assertGreaterThan(0, $doc['summary']['items_total']);
        $this->assertLessThanOrEqual($doc['summary']['items_total'], $doc['summary']['items_with_purpose']);
        $this->assertLessThanOrEqual($doc['summary']['items_total'], $doc['summary']['items_with_conflicts']);
    }

    public function test_per_item_payload_is_byte_identical_only_generated_at_differs(): void
    {
        $a = (new AtlasCortexIntentSnapshot($this->path))->build($this->items(), $this->resolver(), '2026-06-24T00:00:00+00:00');
        $b = (new AtlasCortexIntentSnapshot($this->path))->build($this->items(), $this->resolver(), '2026-06-25T11:22:33+00:00');

        $this->assertSame(json_encode($a['items']), json_encode($b['items']), 'per-item payload byte-identical');
        $this->assertNotSame($a['generated_at'], $b['generated_at'], 'only the top-level generated_at differs');
    }

    public function test_get_intent_for_is_null_safe(): void
    {
        $snapshot = new AtlasCortexIntentSnapshot($this->path);
        $snapshot->build($this->items(), $this->resolver(), '2026-06-24T00:00:00+00:00');

        $this->assertInstanceOf(TriangulatedIntentFact::class, $snapshot->getIntentFor('App\\A\\First'));
        $this->assertSame('purpose of first', $snapshot->getIntentFor('App\\A\\First')->purposeStatement);
        $this->assertNull($snapshot->getIntentFor('App\\Unknown\\Nope'));
    }
}
