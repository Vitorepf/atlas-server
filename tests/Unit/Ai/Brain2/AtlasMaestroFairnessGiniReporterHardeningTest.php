<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;
use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroWorkerFleetProbe;
use App\Services\Ai\SelfConstruction\Maestro\Fairness\AtlasMaestroFairnessGiniReporter;

final class AtlasMaestroFairnessGiniReporterHardeningTest extends TestCase
{
    private function createReporter(callable $completedTaskSource): AtlasMaestroFairnessGiniReporter
    {
        $probe = new AtlasMaestroWorkerFleetProbe(fn () => []);
        return new AtlasMaestroFairnessGiniReporter($probe, $completedTaskSource);
    }

    public function test_empty_outcome_not_counted_as_success(): void
    {
        $reporter = $this->createReporter(fn () => [
            ['task_packet_id' => 'brain:opus48:test-1', 'outcome' => 'success'],
            ['task_packet_id' => 'brain:opus48:test-2', 'outcome' => ''],
            ['task_packet_id' => 'brain:opus48:test-3', 'outcome' => ''],
        ]);

        $method = new \ReflectionMethod($reporter, 'taskDimensionCounts');
        [$taskClass, $lane, $tier] = $method->invoke($reporter);

        $total = array_sum($taskClass);
        $this->assertSame(1, $total, 'Empty outcomes must not be counted as success');
    }

    public function test_success_outcome_counted(): void
    {
        $reporter = $this->createReporter(fn () => [
            ['task_packet_id' => 'brain:opus48:test-1', 'outcome' => 'success'],
            ['task_packet_id' => 'brain:opus48:test-2', 'outcome' => 'success'],
        ]);

        $method = new \ReflectionMethod($reporter, 'taskDimensionCounts');
        [$taskClass, $lane, $tier] = $method->invoke($reporter);

        $total = array_sum($taskClass);
        $this->assertSame(2, $total);
    }

    public function test_source_does_not_default_outcome_to_success(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/Fairness/AtlasMaestroFairnessGiniReporter.php');

        $this->assertStringNotContainsString("?? 'success'", $source, 'outcome must not default to success');
        $this->assertStringContainsString("?? ''", $source, 'outcome must default to empty string');
    }
}
