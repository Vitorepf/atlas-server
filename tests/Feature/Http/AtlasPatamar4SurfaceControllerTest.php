<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;

class AtlasPatamar4SurfaceControllerTest extends TestCase
{
    public function test_scheduler_endpoint_returns_canonical_envelope(): void
    {
        $r = $this->getJson('/atlas/patamar4/scheduler');
        $r->assertStatus(200);
        $data = $r->json();
        $this->assertSame('atlas.patamar4.surface.scheduler.v1', $data['schema_version']);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('silent_alarm', $data['status']);
        $this->assertArrayHasKey('recent_heartbeats', $data);
        $this->assertArrayHasKey('claim_policy', $data);
    }

    public function test_swarm_endpoint_returns_canonical_envelope(): void
    {
        $r = $this->getJson('/atlas/patamar4/swarm');
        $r->assertStatus(200);
        $data = $r->json();
        $this->assertSame('atlas.patamar4.surface.swarm.v1', $data['schema_version']);
        $this->assertArrayHasKey('dispatch_count', $data);
        $this->assertArrayHasKey('execution_count', $data);
        $this->assertArrayHasKey('production_resolver', $data);
        $this->assertArrayHasKey('flag_enabled', $data['production_resolver']);
        // After C5 activation, the flag is ON in .env; the test asserts the
        // field is wired and boolean — not that it is OFF.
        $this->assertIsBool($data['production_resolver']['flag_enabled']);
    }

    public function test_rebalance_endpoint_returns_canonical_envelope(): void
    {
        $r = $this->getJson('/atlas/patamar4/rebalance');
        $r->assertStatus(200);
        $data = $r->json();
        $this->assertSame('atlas.patamar4.surface.rebalance.v1', $data['schema_version']);
        $this->assertArrayHasKey('probes', $data);
        $this->assertArrayHasKey('cache_compact', $data['probes']);
        $this->assertArrayHasKey('aemor_recompact_advice', $data['probes']);
    }

    public function test_cognitive_function_endpoint(): void
    {
        $r = $this->getJson('/atlas/patamar4/cognitive-function');
        $r->assertStatus(200);
        $data = $r->json();
        $this->assertSame('atlas.patamar4.surface.cognitive_function.v1', $data['schema_version']);
        $this->assertArrayHasKey('self_model', $data);
        $this->assertArrayHasKey('gaps_by_group', $data);
        $this->assertArrayHasKey('recent_decompositions', $data);
    }

    public function test_governance_endpoint_returns_kernel_trust_vault(): void
    {
        $r = $this->getJson('/atlas/patamar4/governance');
        $r->assertStatus(200);
        $data = $r->json();
        $this->assertSame('atlas.patamar4.surface.governance.v1', $data['schema_version']);
        $this->assertStringStartsWith('sha256:', $data['kernel']['kernel_hash']);
        $this->assertArrayHasKey('trust_budget', $data);
        $this->assertArrayHasKey('canonical_budget', $data['trust_budget']);
        $this->assertArrayHasKey('vault', $data);
    }

    public function test_decompose_post_endpoint_returns_six_axis_vector(): void
    {
        $r = $this->withHeaders(['X-Atlas-Token' => (string) config('atlas.token')])->postJson('/atlas/patamar4/decompose', [
            'input' => 'refatore o controller e escreva testes',
            'role' => 'engineer',
            'framework' => 'programming',
        ]);
        $r->assertStatus(200);
        $data = $r->json();
        $this->assertSame('atlas.cognitive_function.decomposition.v1', $data['schema_version']);
        $this->assertSame(
            ['reasoning', 'retrieval', 'generation', 'code', 'vision', 'audit'],
            array_keys($data['weights'])
        );
        $this->assertSame('code', $data['dominant_function']);
    }

    public function test_madrugada_inbox_endpoint(): void
    {
        $r = $this->getJson('/atlas/patamar4/inbox/madrugada');
        $r->assertStatus(200);
        $data = $r->json();
        $this->assertSame('atlas.patamar4.surface.madrugada_inbox.v1', $data['schema_version']);
        $this->assertArrayHasKey('nightly_counterfactuals', $data);
        $this->assertArrayHasKey('adml_outcomes', $data);
    }

    public function test_tail_query_clamped_across_endpoints(): void
    {
        foreach (['scheduler', 'swarm', 'rebalance', 'governance', 'inbox/madrugada'] as $p) {
            $r = $this->getJson('/atlas/patamar4/'.$p.'?tail=9999');
            $r->assertStatus(200);
        }
    }

    public function test_conduct_post_returns_governed_engineering_run_envelope(): void
    {
        $r = $this->withHeaders(['X-Atlas-Token' => (string) config('atlas.token')])->postJson('/atlas/patamar4/conduct', [
            'task' => 'code_generation',
            'role' => 'primary',
            'mode' => 'shadow',
            'parallelism' => 1,
        ]);
        $r->assertStatus(200);
        $data = $r->json();
        $this->assertSame('atlas.patamar4.surface.conduct.v1', $data['schema_version']);
        $this->assertSame('atlas.engineering_run.envelope.v1', $data['run']['schema_version']);
        $this->assertSame('shadow', $data['run']['mode']);
        $this->assertArrayHasKey('claim_policy', $data['run']);
    }

    public function test_conduct_requires_a_task(): void
    {
        $r = $this->withHeaders(['X-Atlas-Token' => (string) config('atlas.token')])->postJson('/atlas/patamar4/conduct', ['task' => '']);
        $r->assertStatus(422);
        $this->assertSame('task_required', $r->json()['error']);
    }

    public function test_conduct_live_over_http_cannot_escalate_without_server_flag(): void
    {
        // Sovereignty: even if the request asks for live + operator_approved, the
        // server-side flag is OFF, so the conductor downgrades to shadow. Request
        // input alone can never trigger real provider spend over HTTP.
        config(['atlas.patamar4.swarm_production_resolver_enabled' => false]);

        $r = $this->withHeaders(['X-Atlas-Token' => (string) config('atlas.token')])->postJson('/atlas/patamar4/conduct', [
            'task' => 'code_generation',
            'role' => 'primary',
            'mode' => 'live',
            'operator_approved' => true,
            'parallelism' => 1,
        ]);
        $r->assertStatus(200);
        $data = $r->json();
        // The escalation proof: the request asked for live, but the effective
        // mode is shadow — never live — because the server flag is off. (The
        // exact downgrade_reason only applies once arms dispatch; that path is
        // proven deterministically in the conductor unit test.)
        $this->assertSame('live', $data['run']['requested_mode']);
        $this->assertSame('shadow', $data['run']['mode'], 'HTTP must never escalate to live spend without the server flag');
    }

    public function test_conduct_sdd_gate_blocks_an_ambiguous_spec_over_http(): void
    {
        $r = $this->withHeaders(['X-Atlas-Token' => (string) config('atlas.token')])->postJson('/atlas/patamar4/conduct', [
            'task' => 'code_generation',
            'mode' => 'shadow',
            'spec' => ['objective' => 'do something'],
        ]);
        $r->assertStatus(200);
        $data = $r->json();
        $this->assertSame('spec_blocked', $data['run']['status']);
        $this->assertTrue($data['run']['spec_review']['has_blocking_questions']);
        $this->assertNotEmpty($data['run']['spec_review']['clarification_questions']);
    }
}
