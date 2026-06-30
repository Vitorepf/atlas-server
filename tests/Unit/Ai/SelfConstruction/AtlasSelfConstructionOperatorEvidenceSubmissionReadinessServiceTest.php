<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionOperatorEvidenceSubmissionReadinessServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function build(array $options = []): array
    {
        return (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build($options);
    }

    public function test_next_action_graph_has_ordered_nodes_for_all_four_steps(): void
    {
        $payload = $this->build();

        $this->assertArrayHasKey('next_action_graph', $payload);
        $ids = array_column($payload['next_action_graph']['nodes'], 'id');
        $this->assertSame([
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'human_completion_receipt',
            'rerun_completion_audit',
        ], $ids);
    }

    public function test_each_node_has_required_fields(): void
    {
        $payload = $this->build();

        foreach ($payload['next_action_graph']['nodes'] as $node) {
            foreach (['depends_on', 'ready', 'blocking_reasons', 'canonical_command', 'worker_or_operator_owner'] as $key) {
                $this->assertArrayHasKey($key, $node, "missing {$key} on node {$node['id']}");
            }
            $this->assertNotEmpty($node['canonical_command']);
        }
    }

    public function test_operator_and_provider_owned_steps_are_marked_non_worker(): void
    {
        $payload = $this->build();
        $nodesById = collect($payload['next_action_graph']['nodes'])->keyBy('id');

        $this->assertSame('operator', $nodesById['runtime_promotion_receipt']['worker_or_operator_owner']);
        $this->assertSame('provider', $nodesById['real_provider_smoke']['worker_or_operator_owner']);
        $this->assertSame('operator', $nodesById['human_completion_receipt']['worker_or_operator_owner']);

        foreach (['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'] as $id) {
            $this->assertNotSame('worker', $nodesById[$id]['worker_or_operator_owner']);
        }
    }

    public function test_dependency_chain_is_correct(): void
    {
        $payload = $this->build();
        $nodesById = collect($payload['next_action_graph']['nodes'])->keyBy('id');

        $this->assertSame([], $nodesById['runtime_promotion_receipt']['depends_on']);
        $this->assertSame(['runtime_promotion_receipt'], $nodesById['real_provider_smoke']['depends_on']);
        $this->assertSame(['runtime_promotion_receipt', 'real_provider_smoke'], $nodesById['human_completion_receipt']['depends_on']);
        $this->assertSame(['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'], $nodesById['rerun_completion_audit']['depends_on']);
    }

    public function test_no_input_means_no_node_is_ready(): void
    {
        $payload = $this->build();

        foreach ($payload['next_action_graph']['nodes'] as $node) {
            $this->assertFalse($node['ready'], "{$node['id']} should not be ready with no input");
            $this->assertNotEmpty($node['blocking_reasons']);
        }
        $this->assertSame('blocked_on_operator_or_provider_owned_steps', $payload['next_action_graph']['status']);
    }

    public function test_graph_does_not_grant_execution(): void
    {
        $payload = $this->build();

        $this->assertFalse($payload['next_action_graph']['can_run_from_graph']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['next_action_graph']['next_action_graph_hash']);
    }
}
