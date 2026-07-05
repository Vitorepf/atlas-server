<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroRoundHealthReceiptCompiler;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroRoundHealthReceiptCompilerTest extends TestCase
{
    private AtlasMaestroRoundHealthReceiptCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new AtlasMaestroRoundHealthReceiptCompiler;
    }

    public function test_receipt_contains_health_pass(): void
    {
        $result = $this->compiler->compile(['health_pass' => true]);

        $this->assertArrayHasKey('health_pass', $result);
        $this->assertTrue($result['health_pass']);
    }

    public function test_receipt_contains_malformed_pass(): void
    {
        $result = $this->compiler->compile(['malformed_pass' => false, 'malformed_blockers' => ['poison_risk']]);

        $this->assertArrayHasKey('malformed_pass', $result);
        $this->assertFalse($result['malformed_pass']);
    }

    public function test_receipt_contains_emitted_collision_pass(): void
    {
        $result = $this->compiler->compile(['emitted_collision_pass' => false, 'collision_blockers' => ['app/Foo.php']]);

        $this->assertArrayHasKey('emitted_collision_pass', $result);
        $this->assertFalse($result['emitted_collision_pass']);
    }

    public function test_receipt_contains_exact_blocker_summaries(): void
    {
        $result = $this->compiler->compile([
            'health_pass' => false,
            'health_blockers' => ['queue_unhealthy'],
            'malformed_pass' => false,
            'malformed_blockers' => ['poison_risk'],
            'emitted_collision_pass' => false,
            'collision_blockers' => ['app/Foo.php'],
        ]);

        $this->assertNotEmpty($result['blocker_summary']);
        $this->assertArrayHasKey('health', $result['blocker_summary']);
        $this->assertArrayHasKey('malformed', $result['blocker_summary']);
        $this->assertArrayHasKey('collision', $result['blocker_summary']);
        $this->assertSame(['queue_unhealthy'], $result['blocker_summary']['health']);
    }

    public function test_all_pass_when_no_blockers(): void
    {
        $result = $this->compiler->compile([]);

        $this->assertTrue($result['all_pass']);
        $this->assertEmpty($result['blocker_summary']);
    }

    public function test_schema_present(): void
    {
        $result = $this->compiler->compile([]);
        $this->assertSame(AtlasMaestroRoundHealthReceiptCompiler::SCHEMA, $result['schema']);
    }
}
