<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves AtlasExternalBrainAmbiguityResolutionPlanner is no longer an orphan: it is invoked
 * from atlas:task:self-heal, which turns any 'ambiguity' metadata recorded on a blocked packet
 * into resolution actions / unresolved items instead of letting it become a speculative respec.
 */
final class AtlasExternalBrainAmbiguityResolutionPlannerWiringWiredTest extends TestCase
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

    public function test_output_carries_ambiguity_resolution_keys(): void
    {
        $output = $this->exec();

        $this->assertArrayHasKey('ambiguity_resolution_actions', $output);
        $this->assertArrayHasKey('unresolved_ambiguity_items', $output);
        $this->assertArrayHasKey('ambiguity_task_creation_allowed', $output);
    }

    public function test_local_check_ambiguity_item_produces_resolution_action(): void
    {
        $this->seedBlocked('ambig-local-001', [
            'ambiguity' => ['grep_pattern' => 'SomeClass'],
        ]);

        $output = $this->exec();

        $this->assertNotEmpty($output['ambiguity_resolution_actions']);
        $this->assertEmpty($output['unresolved_ambiguity_items']);
        $this->assertTrue($output['ambiguity_task_creation_allowed']);
    }

    public function test_explicit_escalation_ambiguity_item_blocks_task_creation(): void
    {
        $this->seedBlocked('ambig-escalate-001', [
            'ambiguity' => ['ambiguity_score' => 0.95],
        ]);

        $output = $this->exec();

        $this->assertNotEmpty($output['unresolved_ambiguity_items']);
        $this->assertFalse($output['ambiguity_task_creation_allowed']);
    }

    public function test_packet_without_ambiguity_metadata_does_not_appear_in_ambiguity_output(): void
    {
        $this->seedBlocked('no-ambig-001');

        $output = $this->exec();

        $this->assertSame([], $output['ambiguity_resolution_actions']);
        $this->assertSame([], $output['unresolved_ambiguity_items']);
        $this->assertTrue($output['ambiguity_task_creation_allowed']);
    }
}
