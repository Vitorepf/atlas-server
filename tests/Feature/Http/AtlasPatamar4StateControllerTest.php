<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;

class AtlasPatamar4StateControllerTest extends TestCase
{
    public function test_endpoint_returns_canonical_envelope(): void
    {
        $response = $this->getJson('/atlas/patamar4/state');
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertSame('atlas.patamar4.state.v1', $data['schema_version']);
        $this->assertArrayHasKey('kernel', $data);
        $this->assertArrayHasKey('cognitive_function_atlas', $data);
        $this->assertArrayHasKey('autonomy_admission', $data);
        $this->assertArrayHasKey('reconciliation', $data);
        $this->assertArrayHasKey('teos_i4', $data);
        $this->assertArrayHasKey('swarm', $data);
        $this->assertArrayHasKey('temporary_domain', $data);
        $this->assertArrayHasKey('claim_policy', $data);
    }

    public function test_claim_policy_is_hardcoded_safe(): void
    {
        $data = $this->getJson('/atlas/patamar4/state')->json();
        $cp = $data['claim_policy'];
        $this->assertFalse($cp['benchmark_claim_allowed']);
        $this->assertFalse($cp['rivals_claim_allowed']);
        $this->assertFalse($cp['superiority_claim_allowed']);
        $this->assertFalse($cp['external_rivals_certification_touched']);
        $this->assertTrue($cp['cognitive_immune_law_enforced']);
        $this->assertTrue($cp['provider_safe_only_enforced']);
    }

    public function test_kernel_section_carries_hash_and_petreo_invariants(): void
    {
        $data = $this->getJson('/atlas/patamar4/state')->json();
        $this->assertStringStartsWith('sha256:', $data['kernel']['kernel_hash']);
        $this->assertNotEmpty($data['kernel']['invariants']);
        foreach ($data['kernel']['invariants'] as $i) {
            $this->assertSame('petreo', $i['class']);
        }
    }

    public function test_tail_query_parameter_clamped(): void
    {
        // tail=999 should be clamped to 50; tail=0 to 1.
        $r1 = $this->getJson('/atlas/patamar4/state?tail=999');
        $r1->assertStatus(200);
        $r2 = $this->getJson('/atlas/patamar4/state?tail=0');
        $r2->assertStatus(200);
    }

    public function test_cognitive_function_atlas_subsystem_count(): void
    {
        $data = $this->getJson('/atlas/patamar4/state')->json();
        $this->assertSame(51, $data['cognitive_function_atlas']['subsystem_count']);
    }
}
