<?php

namespace Tests\Feature\Ai\SelfConstruction;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AgentControlPlaneMergeReviewRuntimeSurfaceTest extends TestCase
{
    public function test_command_exposes_merge_review_runtime_quartet(): void
    {
        foreach ([
            '--agent-control-plane-merge-review-runtime-contract' => 'atlas.self_construction_agent_control_plane_merge_review_runtime_contract.v1',
            '--agent-control-plane-merge-review-runtime-preflight' => 'atlas.self_construction_agent_control_plane_merge_review_runtime_preflight.v1',
            '--agent-control-plane-merge-review-runtime-implementation-packet' => 'atlas.self_construction_agent_control_plane_merge_review_runtime_implementation_packet.v1',
            '--agent-control-plane-merge-review-runtime-status' => 'atlas.self_construction_agent_control_plane_merge_review_runtime_status.v1',
        ] as $flag => $schema) {
            $exit = Artisan::call('atlas:ai:self-construction', [$flag => true, '--json' => true]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertFalse($payload['execution_allowed']);
            $this->assertFalse($payload['dispatch_allowed']);
            $this->assertFalse($payload['ledger_write_allowed']);
            $this->assertFalse($payload['runtime_write_allowed']);
        }
    }

    public function test_status_projection_is_available_and_non_promoting(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-merge-review-runtime-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $status = (array) data_get($payload, 'agent_control_plane_merge_review_runtime_status');

        $this->assertSame(0, $exit);
        $this->assertSame('available', $payload['status']);
        $this->assertSame('available', $status['status']);
        $this->assertTrue((bool) $status['invariants_all_true']);
        $this->assertSame(0, (int) $status['violation_count']);
        $this->assertFalse((bool) $status['promotion_allowed']);
        $this->assertFalse((bool) $status['completion_claim_allowed']);
        $this->assertTrue((bool) $status['runtime_safety_all_false']);
    }

    public function test_status_batch_includes_merge_review_runtime(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-certification-status-batch-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $statuses = (array) data_get($payload, 'agent_control_plane_certification_status_batch.statuses', []);
        $row = collect($statuses)->firstWhere('key', 'merge_review_runtime');

        $this->assertSame(0, $exit);
        $this->assertSame('available', data_get($row, 'status'));
        $this->assertFalse((bool) data_get($row, 'runtime_execution_allowed'));
        $this->assertFalse((bool) data_get($row, 'dispatch_allowed'));
    }
}
