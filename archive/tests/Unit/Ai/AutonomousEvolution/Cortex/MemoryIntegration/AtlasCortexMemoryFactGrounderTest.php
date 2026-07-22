<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\MemoryIntegration;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MemoryIntegration\AtlasCortexMemoryFactGrounder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AtlasCortexMemoryFactGrounderTest extends TestCase
{
    #[Test]
    public function it_grounds_a_memory_entry_to_a_real_inventory_item(): void
    {
        $grounder = new AtlasCortexMemoryFactGrounder;

        $mapping = $grounder->ground([
            [
                'memory_entry_id' => 'mem-1',
                'title' => 'Reader note',
                'summary' => null,
                'body' => 'Investigate App\\Services\\Ai\\AutonomousEvolution\\FooReader and app/Services/Ai/AutonomousEvolution/FooReader.php',
            ],
        ], [
            [
                'id' => 'inv-1',
                'symbol' => 'App\\Services\\Ai\\AutonomousEvolution\\FooReader',
                'path' => 'app/Services/Ai/AutonomousEvolution/FooReader.php',
            ],
        ]);

        self::assertSame('grounded', $mapping['mem-1']['status']);
        self::assertSame('inv-1', $mapping['mem-1']['inventory_items'][0]['id']);
    }

    #[Test]
    public function it_marks_non_existent_references_as_ungrounded_without_fabrication(): void
    {
        $grounder = new AtlasCortexMemoryFactGrounder;

        $mapping = $grounder->ground([
            [
                'memory_entry_id' => 'mem-2',
                'title' => 'Missing thing',
                'summary' => null,
                'body' => 'Investigate App\\Services\\Ai\\AutonomousEvolution\\DoesNotExist',
            ],
        ], [
            [
                'id' => 'inv-1',
                'symbol' => 'App\\Services\\Ai\\AutonomousEvolution\\FooReader',
                'path' => 'app/Services/Ai/AutonomousEvolution/FooReader.php',
            ],
        ]);

        self::assertSame('ungrounded', $mapping['mem-2']['status']);
        self::assertSame([], $mapping['mem-2']['inventory_items']);
    }

    #[Test]
    public function it_is_deterministic_for_the_same_input_snapshot(): void
    {
        $grounder = new AtlasCortexMemoryFactGrounder;
        $memoryEntries = [
            [
                'memory_entry_id' => 'mem-1',
                'title' => 'Reader note',
                'summary' => 'Touches FooReader',
                'body' => 'Investigate App\\Services\\Ai\\AutonomousEvolution\\FooReader',
            ],
        ];
        $inventory = [
            [
                'id' => 'inv-1',
                'symbol' => 'App\\Services\\Ai\\AutonomousEvolution\\FooReader',
                'path' => 'app/Services/Ai/AutonomousEvolution/FooReader.php',
            ],
        ];

        $first = $grounder->ground($memoryEntries, $inventory);
        $second = $grounder->ground($memoryEntries, $inventory);

        self::assertSame(serialize($first), serialize($second));
    }
}
