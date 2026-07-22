<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AtlasMemoryRegistryService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionMemoryRecall;
use App\Services\Ai\Memory\MemoryRecallInput;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\TestCase;

final class AtlasLoopProjectionMemoryRecallTest extends TestCase
{
    public function test_flag_off_returns_empty_without_querying_registry(): void
    {
        config(['atlas.loop.projection_memory_recall_enabled' => false]);
        $port = new AtlasLoopProjectionMemoryRecall($this->registry([[
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'summary' => 'TargetFoo wired decision.',
        ]], throws: true), new MemoryRecallInput);

        $this->assertSame([], $port->forTarget('app/TargetFoo.php', 'wired'));
    }

    public function test_registry_exception_fails_closed_to_empty(): void
    {
        config(['atlas.loop.projection_memory_recall_enabled' => true]);
        $port = new AtlasLoopProjectionMemoryRecall($this->registry([], throws: true), new MemoryRecallInput);

        $this->assertSame([], $port->forTarget('app/TargetFoo.php', 'wired'));
    }

    public function test_items_are_provider_safe_truncated_and_have_deterministic_refs(): void
    {
        config([
            'atlas.loop.projection_memory_recall_enabled' => true,
            'atlas.ai.memory_recall_item_chars' => 80,
        ]);
        $longText = str_repeat('TargetFoo wired provider safe sentence. ', 6);
        $port = new AtlasLoopProjectionMemoryRecall($this->registry([[
            'memory_type' => 'decision',
            'scope_type' => 'workspace',
            'body' => $longText,
            'source_type' => 'manual',
            'source_label' => 'projection-test',
            'source_id' => 'internal-id-must-not-leak',
            'trace_id' => 'trace-must-not-leak',
            'prompt' => 'prompt-must-not-leak',
        ]]), new MemoryRecallInput);

        $first = $port->forTarget('app/TargetFoo.php', 'wired');
        $second = $port->forTarget('app/TargetFoo.php', 'wired');

        $this->assertCount(1, $first);
        $this->assertSame(['kind', 'scope', 'text', 'source_ref'], array_keys($first[0]));
        $this->assertSame('decision', $first[0]['kind']);
        $this->assertSame('workspace', $first[0]['scope']);
        $this->assertLessThanOrEqual(80, strlen($first[0]['text']));
        $this->assertSame($first[0]['source_ref'], $second[0]['source_ref']);
        $this->assertStringStartsWith('atlas_memory:', $first[0]['source_ref']);
        $this->assertStringNotContainsString('internal-id', implode(' ', $first[0]));
        $this->assertStringNotContainsString('trace', implode(' ', $first[0]));
        $this->assertStringNotContainsString('prompt', implode(' ', $first[0]));
    }

    public function test_only_whitelisted_kinds_pass_through(): void
    {
        config(['atlas.loop.projection_memory_recall_enabled' => true]);
        $port = new AtlasLoopProjectionMemoryRecall($this->registry([
            ['memory_type' => 'decision', 'scope_type' => 'global', 'summary' => 'TargetFoo wired decision.'],
            ['memory_type' => 'learning', 'scope_type' => 'campaign', 'summary' => 'TargetFoo wired learning.'],
            ['memory_type' => 'technical_context', 'scope_type' => 'workspace', 'summary' => 'TargetFoo wired context.'],
            ['memory_type' => 'harness_learning', 'scope_type' => 'global', 'summary' => 'TargetFoo wired harness.'],
            ['memory_type' => 'secret', 'scope_type' => 'global', 'summary' => 'TargetFoo wired secret.'],
            ['memory_type' => 'private_context', 'scope_type' => 'global', 'summary' => 'TargetFoo wired private.'],
        ]), new MemoryRecallInput);

        $items = $port->forTarget('app/TargetFoo.php', 'wired', 10);

        $this->assertSame([
            'decision',
            'learning',
            'technical_context',
            'harness_learning',
        ], array_column($items, 'kind'));
        $this->assertSame(['global', 'campaign', 'workspace', 'global'], array_column($items, 'scope'));
    }

    public function test_lexical_rerank_prefers_target_basename_and_binding_axis_hits(): void
    {
        config(['atlas.loop.projection_memory_recall_enabled' => true]);
        $port = new AtlasLoopProjectionMemoryRecall($this->registry([
            [
                'memory_type' => 'decision',
                'scope_type' => 'global',
                'summary' => 'Generic projection note with no matching tokens.',
            ],
            [
                'memory_type' => 'decision',
                'scope_type' => 'global',
                'summary' => 'TargetFoo must keep the wired projection obligation grounded.',
            ],
        ]), new MemoryRecallInput);

        $items = $port->forTarget('app/Services/TargetFoo.php', 'wired', 2);

        $this->assertSame('TargetFoo must keep the wired projection obligation grounded.', $items[0]['text']);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function registry(array $items, bool $throws = false): AtlasMemoryRegistryService
    {
        return new class($items, $throws) extends AtlasMemoryRegistryService
        {
            /**
             * @param  list<array<string, mixed>>  $items
             */
            public function __construct(private readonly array $items, private readonly bool $throws)
            {
            }

            /**
             * @param  array<string, mixed>  $filters
             * @return Collection<int, array<string, mixed>>
             */
            public function search(array $filters = [], int $limit = 50): Collection
            {
                if ($this->throws) {
                    throw new RuntimeException('registry unavailable');
                }

                return collect($this->items)->take($limit);
            }
        };
    }
}
