<?php

namespace Tests\Feature\Ai\ToolRuntime;

use App\Models\AiToolHealthCheck;
use App\Services\Ai\ToolRuntime\ToolDefinitionRegistryService;
use App\Services\Ai\ToolRuntime\ToolHealthService;
use App\Services\Ai\ToolRuntime\ToolSeedDefinitions;
use Tests\Concerns\CreatesToolRuntimeTables;
use Tests\TestCase;

class ToolRuntimeHealthDoctorTest extends TestCase
{
    use CreatesToolRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createToolRuntimeTables();
        app(ToolDefinitionRegistryService::class)->seedDefaults(ToolSeedDefinitions::all());
    }

    protected function tearDown(): void
    {
        $this->dropToolRuntimeTables();
        parent::tearDown();
    }

    public function test_doctor_records_health_check_per_tool_and_marks_healthy(): void
    {
        $payload = app(ToolHealthService::class)->doctor();

        $this->assertTrue($payload['ok']);
        $this->assertSame(10, $payload['summary']['healthy']);
        $this->assertSame(10, AiToolHealthCheck::query()->count());

        foreach (AiToolHealthCheck::query()->get() as $check) {
            $this->assertSame('healthy', $check->status);
            $this->assertSame([], $check->missing_requirements);
            $this->assertSame(64, strlen((string) $check->health_hash));
        }
    }
}
