<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Kernel;

use App\Models\AiMission;
use App\Models\AiMissionEvidenceRef;
use App\Models\AiWorkOrder;
use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Programming\Kernel\ProgrammingEvidenceBridge;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Focused unit coverage for the factory-critical programming evidence bridge.
 *
 * Feature suites exercise attach end-to-end; this file guards availability probes,
 * tolerant fallbacks, and receipt hashing without spinning up adapter tables.
 */
final class ProgrammingEvidenceBridgeTest extends TestCase
{
    public function test_mission_evidence_availability_reflects_schema_probe(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('ai_mission_evidence_refs')->andReturn(true);

        $bridge = new ProgrammingEvidenceBridge($this->createMock(Container::class));

        $this->assertTrue($bridge->missionEvidenceAvailable());
    }

    public function test_evidence_runtime_availability_reflects_schema_probe(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('ai_receipts')->andReturn(false);

        $bridge = new ProgrammingEvidenceBridge($this->createMock(Container::class));

        $this->assertFalse($bridge->evidenceRuntimeAvailable());
    }

    public function test_attach_returns_local_receipt_when_runtime_tables_are_unavailable(): void
    {
        Schema::shouldReceive('hasTable')->with('ai_mission_evidence_refs')->andReturn(false);
        Schema::shouldReceive('hasTable')->with('ai_receipts')->andReturn(false);

        $bridge = new ProgrammingEvidenceBridge($this->createMock(Container::class));
        $mission = new AiMission(['id' => '00000000-0000-0000-0000-000000000001']);

        $result = $bridge->attach($mission, 'test', 'tests:phpunit:smoke', ['scope' => 'unit']);

        $this->assertNull($result['mission_evidence_ref_id']);
        $this->assertNull($result['mission_evidence_hash']);
        $this->assertNull($result['mission_evidence_error']);
        $this->assertSame('programming_adapter_local_receipt', $result['evidence_runtime']['kind']);
        $this->assertSame('evidence_runtime_unavailable', $result['evidence_runtime']['reason']);
        $this->assertSame(64, strlen((string) $result['evidence_runtime']['hash']));
    }

    public function test_local_receipt_hash_distinguishes_evidence_refs_when_runtime_unavailable(): void
    {
        Schema::shouldReceive('hasTable')->with('ai_mission_evidence_refs')->andReturn(false);
        Schema::shouldReceive('hasTable')->with('ai_receipts')->andReturn(false);

        $bridge = new ProgrammingEvidenceBridge($this->createMock(Container::class));
        $mission = new AiMission(['id' => '00000000-0000-0000-0000-000000000001']);

        $first = $bridge->attach($mission, 'test', 'ref-alpha');
        $second = $bridge->attach($mission, 'test', 'ref-beta');

        $this->assertNotSame(
            $first['evidence_runtime']['hash'],
            $second['evidence_runtime']['hash'],
        );
    }

    public function test_attach_records_mission_evidence_error_without_aborting_receipt_fallback(): void
    {
        Schema::shouldReceive('hasTable')->with('ai_mission_evidence_refs')->andReturn(true);
        Schema::shouldReceive('hasTable')->with('ai_receipts')->andReturn(false);

        $container = $this->createMock(Container::class);
        $container->expects($this->once())
            ->method('make')
            ->with(MissionEvidenceService::class)
            ->willReturn(new class
            {
                public function attach(AiMission $mission, array $payload): never
                {
                    throw new RuntimeException('mission evidence attach failed');
                }
            });

        $bridge = new ProgrammingEvidenceBridge($container);
        $mission = new AiMission(['id' => '00000000-0000-0000-0000-000000000002']);
        $workOrder = new AiWorkOrder(['id' => '00000000-0000-0000-0000-000000000003']);

        $result = $bridge->attach($mission, 'test', 'tests:phpunit:failed', [], $workOrder);

        $this->assertNull($result['mission_evidence_ref_id']);
        $this->assertSame('mission evidence attach failed', $result['mission_evidence_error']);
        $this->assertSame('programming_adapter_local_receipt', $result['evidence_runtime']['kind']);
        $this->assertSame('evidence_runtime_unavailable', $result['evidence_runtime']['reason']);
    }

    public function test_attach_passes_programming_adapter_source_metadata_to_mission_evidence(): void
    {
        Schema::shouldReceive('hasTable')->with('ai_mission_evidence_refs')->andReturn(true);
        Schema::shouldReceive('hasTable')->with('ai_receipts')->andReturn(false);

        $record = new AiMissionEvidenceRef([
            'id' => '00000000-0000-0000-0000-000000000004',
            'evidence_hash' => str_repeat('a', 64),
        ]);

        $container = $this->createMock(Container::class);
        $container->expects($this->once())
            ->method('make')
            ->with(MissionEvidenceService::class)
            ->willReturn(new class($record)
            {
                public function __construct(private readonly AiMissionEvidenceRef $record) {}

                public function attach(AiMission $mission, array $payload): AiMissionEvidenceRef
                {
                    if (($payload['metadata']['source'] ?? null) !== 'programming_adapter') {
                        throw new RuntimeException('missing programming_adapter source metadata');
                    }

                    return $this->record;
                }
            });

        $bridge = new ProgrammingEvidenceBridge($container);
        $mission = new AiMission(['id' => '00000000-0000-0000-0000-000000000005']);

        $result = $bridge->attach($mission, 'test', 'tests:phpunit:metadata', ['scope' => 'unit']);

        $this->assertSame($record->id, $result['mission_evidence_ref_id']);
        $this->assertSame($record->evidence_hash, $result['mission_evidence_hash']);
    }
}
