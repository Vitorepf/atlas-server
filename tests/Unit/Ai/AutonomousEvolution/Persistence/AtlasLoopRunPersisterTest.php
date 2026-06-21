<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Persistence;

use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopRunPersister;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class AtlasLoopRunPersisterTest extends TestCase
{
    private AtlasLoopRunPersister $persister;

    protected function setUp(): void
    {
        $store = (new ReflectionClass(AtlasLoopStore::class))->newInstanceWithoutConstructor();
        $this->persister = new AtlasLoopRunPersister($store);
    }

    public function testAssertNeverMergedThrowsOnlyForLiteralTrue(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('propose-only invariant violated: engine result reported merged_to_main=true');

        $this->persister->assertNeverMerged([
            'merged_to_main' => true,
        ]);
    }

    public function testAssertNeverMergedDoesNotThrowForFalseOrMissingFlag(): void
    {
        $this->persister->assertNeverMerged([
            'merged_to_main' => false,
        ]);
        $this->persister->assertNeverMerged([]);

        $this->addToAssertionCount(1);
    }

    public function testAssertNeverMergedDoesNotTreatTruthyNonBooleanAsMerged(): void
    {
        $this->persister->assertNeverMerged([
            'merged_to_main' => 1,
        ]);
        $this->persister->assertNeverMerged([
            'merged_to_main' => '1',
        ]);

        $this->addToAssertionCount(1);
    }
}
