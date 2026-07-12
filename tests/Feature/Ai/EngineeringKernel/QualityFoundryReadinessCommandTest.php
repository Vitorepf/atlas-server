<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\EngineeringKernel;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class QualityFoundryReadinessCommandTest extends TestCase
{
    public function test_readiness_command_exposes_blocked_master_plan_without_claiming_completion(): void
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:engineering:quality-foundry-readiness', ['--json' => true], $output);
        $data = json_decode($output->fetch(), true);

        self::assertSame(0, $exit);
        self::assertIsArray($data);
        self::assertSame('atlas.quality_foundry.readiness_manifest.v1', $data['schema']);
        self::assertSame('blocked', $data['status']);
        self::assertFalse($data['completion_allowed']);
        self::assertGreaterThan(0, $data['summary']['open_items']);
    }
}
