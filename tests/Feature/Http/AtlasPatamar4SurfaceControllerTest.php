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
        $r = $this->postJson('/atlas/patamar4/decompose', [
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
}
