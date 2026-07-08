<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves AtlasExternalBrainContextHygieneIncidentTaskPlanner is wired into a real call path:
 * atlas:task:self-heal now composes context_hygiene_incidents recorded on a blocked packet's
 * metadata into a context_hygiene_task_plan. It is no longer an orphan.
 */
final class AtlasExternalBrainContextHygieneIncidentTaskPlannerWiringWiredTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function seedBlocked(string $id, array $metadata = []): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build([
            'task_packet_id' => $id,
            'objective' => "blocked packet {$id}: implement app/Foo/{$id}Service.php deterministically.",
            'operator_id' => 'tester',
            'allowed_files' => ["app/Foo/{$id}Service.php", "tests/Unit/Foo/{$id}ServiceTest.php"],
            'scope_in' => ["app/Foo/{$id}Service.php"],
            'acceptance_criteria' => ["{$id} test exits 0"],
            'required_evidence' => ['tests_or_gates_result'],
        ]);

        $queue->enqueue($packet);
        $queue->updateStatus($id, 'blocked', $metadata);
    }

    private function exec(): array
    {
        Artisan::call('atlas:task:self-heal', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function test_context_hygiene_incidents_are_composed_into_a_task_plan(): void
    {
        $this->seedBlocked('hygiene-001', [
            'context_hygiene_incidents' => [
                ['incident_id' => 'inc-1', 'issue_code' => 'raw_prompt_leakage', 'source_id' => 'source-a', 'evidence_count' => 2],
            ],
        ]);

        $output = $this->exec();

        $this->assertArrayHasKey('context_hygiene_task_plan', $output);
        $this->assertCount(1, $output['context_hygiene_task_plan']);
        $this->assertSame('memory_safety_gate', $output['context_hygiene_task_plan'][0]['target_capability']);
    }

    public function test_no_hygiene_incidents_yields_empty_task_plan(): void
    {
        $this->seedBlocked('no-hygiene-001');

        $output = $this->exec();

        $this->assertSame([], $output['context_hygiene_task_plan']);
    }
}
