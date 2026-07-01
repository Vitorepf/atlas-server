<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * atlas:task:self-heal is a read-only operator surface. Seeds real queue records (via the
 * builder + repository) so quality facts can be controlled without running the inspector.
 * Proves: correct JSON output (respec_proposals, repair_candidates, ranked_repair_candidates)
 * and zero queue mutations after the command runs.
 */
final class AtlasTaskQueueSelfHealCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function seedBlocked(string $id, array $taskPacketOverrides = [], array $metadata = []): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build(array_merge([
            'task_packet_id' => $id,
            'objective' => "blocked packet {$id}: implement app/Foo/{$id}Service.php deterministically.",
            'operator_id' => 'tester',
            'allowed_files' => ["app/Foo/{$id}Service.php", "tests/Unit/Foo/{$id}ServiceTest.php"],
            'scope_in' => ["app/Foo/{$id}Service.php"],
            'acceptance_criteria' => ["{$id} test exits 0"],
            'required_evidence' => ['tests_or_gates_result'],
        ], $taskPacketOverrides));

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

    public function test_json_output_contains_respec_and_repair_candidates(): void
    {
        $this->seedBlocked('missing-test-001', ['allowed_files' => ['app/Foo/MissingTestService.php']]);

        $output = $this->exec();

        $this->assertArrayHasKey('respec_proposals', $output);
        $this->assertArrayHasKey('repair_candidates', $output);
        $this->assertArrayHasKey('ranked_repair_candidates', $output);
        $this->assertSame(1, $output['blocked_count']);
    }

    public function test_missing_test_path_packet_is_flagged_respec_required(): void
    {
        $this->seedBlocked('missing-test-002', ['allowed_files' => ['app/Foo/MissingTestService.php']]);

        $output = $this->exec();

        $proposal = $output['respec_proposals'][0];
        $this->assertSame('missing-test-002', $proposal['task_packet_id']);
        $this->assertTrue($proposal['respec_required']);
        $this->assertContains('missing_test_path', array_column($proposal['issues'], 'type'));
    }

    public function test_ranked_repair_candidates_prioritize_missing_files_over_analysis_only(): void
    {
        $this->seedBlocked('diag-001', [], ['root_cause' => 'unexplained_failure']);
        $this->seedBlocked('scope-001', ['allowed_files' => []], ['missing_files' => ['app/Foo/ScopeService.php']]);

        $output = $this->exec();

        $rankedIds = array_column($output['ranked_repair_candidates'], 'task_id');
        $scopeIndex = array_search('scope-001', $rankedIds, true);
        $diagIndex = array_search('diag-001', $rankedIds, true);

        $this->assertNotFalse($scopeIndex);
        $this->assertNotFalse($diagIndex);
        $this->assertLessThan($diagIndex, $scopeIndex, 'a safe add_allowed_file repair must rank above an analysis-only diagnostic');
    }

    public function test_command_does_not_mutate_queue_status(): void
    {
        $this->seedBlocked('immutable-001');

        $this->exec();

        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $this->assertSame('blocked', $repo->get('immutable-001')['status']);
    }

    public function test_empty_queue_produces_zero_blocked_count(): void
    {
        $output = $this->exec();

        $this->assertSame(0, $output['blocked_count']);
        $this->assertSame([], $output['respec_proposals']);
        $this->assertSame([], $output['repair_candidates']);
    }
}
