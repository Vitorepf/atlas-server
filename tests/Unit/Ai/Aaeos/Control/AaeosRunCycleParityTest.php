<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Control;

use App\Console\Commands\AtlasAaeosCycleCommand;
use App\Console\Commands\AtlasAaeosRunCommand;
use App\Services\Ai\Aaeos\Control\AaeosCycleRuntime;
use PHPUnit\Framework\TestCase;

final class AaeosRunCycleParityTest extends TestCase
{
    public function test_dry_run_and_cycle_share_the_cycle_runtime_truthful_receipt_contract(): void
    {
        $runtime = new AaeosCycleRuntime;
        $run = $runtime->runCycle('fix a bounded validation bug', ['source' => 'atlas_aaeos_run'], [], true);
        $cycle = $runtime->runCycle('fix a bounded validation bug', ['source' => 'cli'], [], true);

        $this->assertSame($run['status'], $cycle['status']);
        $this->assertFalse($run['runtime_write_performed']);
        $this->assertFalse($cycle['runtime_write_performed']);

        foreach ([AtlasAaeosRunCommand::class, AtlasAaeosCycleCommand::class] as $command) {
            $source = file_get_contents((new \ReflectionClass($command))->getFileName());
            $this->assertIsString($source);
            $this->assertStringContainsString('runCycle(', $source);
        }
    }
}
