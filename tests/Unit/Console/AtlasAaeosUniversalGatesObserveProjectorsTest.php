<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\AtlasAaeosCommand;
use App\Services\Ai\AgenticEngineeringOs\AtlasUniversalGatesEvaluator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AtlasAaeosUniversalGatesObserveProjectorsTest extends TestCase
{
    public function test_observe_projectors_table_is_dense_and_well_formed(): void
    {
        $command = new AtlasAaeosCommand;
        $method = new ReflectionMethod(AtlasAaeosCommand::class, 'universalGatesObserveProjectors');
        $method->setAccessible(true);

        $gates = (new ReflectionClass(AtlasUniversalGatesEvaluator::class))
            ->newInstanceWithoutConstructor();

        /** @var list<array{0: string, 1: string, 2: callable}> $rows */
        $rows = $method->invoke($command, $gates);

        $this->assertGreaterThan(100, count($rows));
        $options = [];
        foreach ($rows as $row) {
            $this->assertCount(3, $row);
            [$option, $observeKey, $projector] = $row;
            $this->assertIsString($option);
            $this->assertNotSame('', $option);
            $this->assertIsString($observeKey);
            $this->assertNotSame('', $observeKey);
            $this->assertIsCallable($projector);
            $options[] = $option;
        }
        $this->assertSame(count($options), count(array_unique($options)), 'option names must be unique');
    }

    public function test_universal_gates_method_is_thin_orchestrator(): void
    {
        $method = new ReflectionMethod(AtlasAaeosCommand::class, 'universalGates');
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $loc = $end - $start;
        $this->assertLessThan(120, $loc, 'universalGates should stay a thin option→projectors loop after density peel');
    }
}
