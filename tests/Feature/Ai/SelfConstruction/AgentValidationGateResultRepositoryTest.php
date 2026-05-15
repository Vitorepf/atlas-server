<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentValidationGateDryRunEvaluator;
use App\Services\Ai\SelfConstruction\AgentValidationGatePlanBuilder;
use App\Services\Ai\SelfConstruction\AgentValidationGateResultRepository;
use Tests\TestCase;

final class AgentValidationGateResultRepositoryTest extends TestCase
{
    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_validation_gate_result_repository.v1', AgentValidationGateResultRepository::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_validation_gate_result_repository', AgentValidationGateResultRepository::MODE);
    }

    public function test_empty_repository_shape(): void
    {
        $repo = new AgentValidationGateResultRepository;
        $this->assertSame(0, $repo->count());
        $this->assertTrue($repo->isEmpty());
        $this->assertSame([], $repo->all());
        $this->assertSame([], $repo->ids());
        $this->assertNull($repo->latest());
        $this->assertFalse($repo->has('does-not-exist'));
        $this->assertNull($repo->find('does-not-exist'));
        $this->assertSame([], $repo->failed());
        $this->assertSame([], $repo->passed());
    }

    public function test_store_assigns_id_when_missing(): void
    {
        $repo = new AgentValidationGateResultRepository;
        $id = $repo->store(['overall_status' => 'passed', 'evaluations' => []]);
        $this->assertMatchesRegularExpression('/^result-[a-f0-9]{16}$/', $id);
        $this->assertTrue($repo->has($id));
        $this->assertNotNull($repo->find($id));
        $this->assertSame(1, $repo->count());
        $this->assertSame([$id], $repo->ids());
    }

    public function test_store_uses_provided_id(): void
    {
        $repo = new AgentValidationGateResultRepository;
        $id = $repo->store(['result_set_id' => 'result-fixed', 'overall_status' => 'passed']);
        $this->assertSame('result-fixed', $id);
        $this->assertTrue($repo->has('result-fixed'));
    }

    public function test_store_revisions_on_repeat(): void
    {
        $repo = new AgentValidationGateResultRepository;
        $repo->store(['result_set_id' => 'r1', 'overall_status' => 'passed']);
        $repo->store(['result_set_id' => 'r1', 'overall_status' => 'failed']);
        $repo->store(['result_set_id' => 'r1', 'overall_status' => 'passed_with_warnings']);
        $found = $repo->find('r1');
        $this->assertSame(3, $found['storage_revision']);
        $this->assertSame('passed_with_warnings', $found['overall_status']);
        $this->assertSame(1, $repo->count());
    }

    public function test_latest_returns_last_stored(): void
    {
        $repo = new AgentValidationGateResultRepository;
        $repo->store(['result_set_id' => 'r1', 'overall_status' => 'passed']);
        $repo->store(['result_set_id' => 'r2', 'overall_status' => 'failed']);
        $latest = $repo->latest();
        $this->assertSame('r2', $latest['result_set_id']);
        $this->assertSame('failed', $latest['overall_status']);
    }

    public function test_clear_empties_store(): void
    {
        $repo = new AgentValidationGateResultRepository;
        $repo->store(['result_set_id' => 'r1']);
        $repo->store(['result_set_id' => 'r2']);
        $repo->clear();
        $this->assertSame(0, $repo->count());
        $this->assertTrue($repo->isEmpty());
        $this->assertSame([], $repo->ids());
        $this->assertNull($repo->latest());
    }

    public function test_forget_removes_only_target(): void
    {
        $repo = new AgentValidationGateResultRepository;
        $repo->store(['result_set_id' => 'r1']);
        $repo->store(['result_set_id' => 'r2']);
        $repo->store(['result_set_id' => 'r3']);
        $ok = $repo->forget('r2');
        $this->assertTrue($ok);
        $this->assertFalse($repo->has('r2'));
        $this->assertSame(['r1', 'r3'], $repo->ids());
        $this->assertFalse($repo->forget('r2'));
    }

    public function test_where_overall_status_filters(): void
    {
        $repo = new AgentValidationGateResultRepository;
        $repo->store(['result_set_id' => 'r1', 'overall_status' => 'passed']);
        $repo->store(['result_set_id' => 'r2', 'overall_status' => 'failed']);
        $repo->store(['result_set_id' => 'r3', 'overall_status' => 'passed']);
        $this->assertCount(2, $repo->passed());
        $this->assertCount(1, $repo->failed());
        $this->assertCount(2, $repo->whereOverallStatus('passed'));
        $this->assertCount(0, $repo->whereOverallStatus('nonexistent'));
    }

    public function test_digest_hash_stable_and_changes(): void
    {
        $repo = new AgentValidationGateResultRepository;
        $repo->store([
            'result_set_id' => 'r1',
            'plan_id' => 'p1',
            'plan_hash' => 'h1',
            'overall_status' => 'passed',
            'evaluation_hash' => 'ev1',
        ]);
        $d1 = $repo->digest();
        $d2 = $repo->digest();
        $this->assertSame($d1['digest_hash'], $d2['digest_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $d1['digest_hash']);
        $repo->store([
            'result_set_id' => 'r2',
            'plan_id' => 'p2',
            'plan_hash' => 'h2',
            'overall_status' => 'failed',
            'evaluation_hash' => 'ev2',
        ]);
        $d3 = $repo->digest();
        $this->assertNotSame($d1['digest_hash'], $d3['digest_hash']);
        $this->assertSame(2, $d3['count']);
    }

    public function test_digest_runtime_safety_all_false(): void
    {
        $repo = new AgentValidationGateResultRepository;
        $rs = $repo->digest()['runtime_safety'];
        $this->assertTrue($rs['runtime_safety_all_false']);
        $this->assertFalse($rs['execution_allowed']);
        $this->assertFalse($rs['dispatch_allowed']);
        $this->assertFalse($rs['provider_call_allowed']);
        $this->assertFalse($rs['token_spend_allowed']);
        $this->assertFalse($rs['self_programming_allowed']);
        $this->assertFalse($rs['ledger_write_allowed']);
        $this->assertFalse($rs['runtime_write_allowed']);
    }

    public function test_integration_with_evaluator(): void
    {
        $plan = (new AgentValidationGatePlanBuilder)->buildPlan();
        $eval = new AgentValidationGateDryRunEvaluator;
        $inputs = [];
        foreach ($plan['ordered_runs'] as $run) {
            $inputs[$run['gate_id']] = ['status' => 'pass', 'evidence_artifact' => $run['expected_artifact']];
        }
        $rs = $eval->evaluate($plan, $inputs);
        $repo = new AgentValidationGateResultRepository;
        $id = $repo->store($rs);
        $this->assertSame($rs['result_set_id'], $id);
        $this->assertSame(1, $repo->count());
        $back = $repo->find($id);
        $this->assertSame('passed', $back['overall_status']);
        $this->assertSame(10, count($back['evaluations']));
    }

    public function test_all_preserves_insertion_order(): void
    {
        $repo = new AgentValidationGateResultRepository;
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $repo->store(['result_set_id' => "r-{$id}", 'overall_status' => 'passed']);
        }
        $ids = array_map(static fn ($r) => $r['result_set_id'], $repo->all());
        $this->assertSame(['r-a', 'r-b', 'r-c', 'r-d'], $ids);
    }

    public function test_digest_items_carry_expected_fields(): void
    {
        $repo = new AgentValidationGateResultRepository;
        $repo->store(['result_set_id' => 'r1', 'plan_id' => 'p1', 'plan_hash' => 'h1', 'overall_status' => 'passed', 'evaluation_hash' => 'eh']);
        $d = $repo->digest();
        $this->assertSame(1, $d['count']);
        $this->assertSame('r1', $d['items'][0]['result_set_id']);
        $this->assertSame('p1', $d['items'][0]['plan_id']);
        $this->assertSame('h1', $d['items'][0]['plan_hash']);
        $this->assertSame('passed', $d['items'][0]['overall_status']);
        $this->assertSame('eh', $d['items'][0]['evaluation_hash']);
        $this->assertSame(1, $d['items'][0]['storage_revision']);
    }

    public function test_stored_record_carries_microtime(): void
    {
        $repo = new AgentValidationGateResultRepository;
        $repo->store(['result_set_id' => 'r1']);
        $back = $repo->find('r1');
        $this->assertIsFloat($back['stored_at_microtime']);
        $this->assertGreaterThan(0.0, $back['stored_at_microtime']);
    }
}
