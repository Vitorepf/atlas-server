<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Control;

use App\Console\Commands\AtlasAaeosCertifyCommand;
use App\Services\Ai\Aaeos\Control\AaeosCycleRuntime;
use PHPUnit\Framework\TestCase;

final class AaeosHumanLoopFieldRetirementTest extends TestCase
{
    public function test_runtime_receipts_and_certification_do_not_treat_human_loop_as_an_authoritative_gate(): void
    {
        $receipt = (new AaeosCycleRuntime)->runAutonomosCycle('certify a dry route', [], true);

        $this->assertArrayNotHasKey('human_in_engineering_loop', $receipt);
        $source = file_get_contents((new \ReflectionClass(AtlasAaeosCertifyCommand::class))->getFileName());
        $this->assertIsString($source);
        $this->assertStringNotContainsString('human_in_engineering_loop', $source);
    }
}
