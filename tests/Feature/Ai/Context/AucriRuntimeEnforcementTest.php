<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasAgenticRagFrameworkService;
use App\Services\Ai\Context\AtlasAucriRuntimeEnforcementService;
use Tests\TestCase;

/**
 * Direct coverage for the AUCRI runtime governance gate
 * {@see AtlasAucriRuntimeEnforcementService::enforce()} — the binary pass/block
 * verdict that runs all 18 AUCRI blocks before a provider call. Pins the two
 * load-bearing outcomes (passed vs blocked), the {@see $payload['blockers']}
 * shape, and the AARF (block 3) audit-trail entry whose runtime hash is sourced
 * from the `agentic_rag_plan_hash` key. Follows the pattern in
 * {@see AucriOptimizationAuditTest}.
 */
final class AucriRuntimeEnforcementTest extends TestCase
{
    public function test_low_risk_programming_execution_input_passes_the_gate(): void
    {
        $payload = app(AtlasAucriRuntimeEnforcementService::class)->enforce([
            'flow_id' => 'atlas.programming.execution',
            'domain' => 'programming',
            'task_type' => 'execution',
            'risk_level' => 'low',
            'provider' => 'gpt',
            'provider_target' => 'external',
            'objective' => 'Refactor the retrieval ranking helper and add a unit test.',
        ]);

        $this->assertSame(AtlasAucriRuntimeEnforcementService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);

        // blockers shape: an empty list when the gate passes.
        $this->assertIsArray($payload['blockers']);
        $this->assertSame([], $payload['blockers']);

        // provider-safety + readiness claims the gate asserts before any provider call.
        $this->assertFalse($payload['claims']['providers_invoked']);
        $this->assertTrue($payload['claims']['enforced_before_provider_call']);
        $this->assertTrue($payload['claims']['all_18_aucri_blocks_executed']);

        // all 18 AUCRI blocks executed in the auditable trail.
        $this->assertCount(18, $payload['block_refs']);
        $this->assertSame(18, $payload['block_ref_summary']['total']);
        $this->assertTrue($payload['block_ref_summary']['all_18_blocks_executed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['runtime_enforcement_hash']);

        // AARF block (3): acronym + the runtime hash sourced from `agentic_rag_plan_hash`.
        $aarf = $this->blockRef($payload, 'AARF');
        $this->assertSame(3, $aarf['block']);
        $this->assertSame('Atlas Agentic RAG Framework', $aarf['name']);
        $this->assertSame('executed', $aarf['execution_status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $aarf['runtime_hash']);

        // The `agentic_rag_plan_hash` key the gate reads for AARF exists on the block payload.
        $aarfPlan = app(AtlasAgenticRagFrameworkService::class)->plan([
            'domain' => 'programming',
            'task_type' => 'execution',
            'risk_level' => 'low',
            'objective' => 'AUCRI runtime enforcement',
        ]);
        $this->assertArrayHasKey('agentic_rag_plan_hash', $aarfPlan);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $aarfPlan['agentic_rag_plan_hash']);
    }

    public function test_high_risk_secret_context_is_blocked_with_blockers(): void
    {
        $payload = app(AtlasAucriRuntimeEnforcementService::class)->enforce([
            'flow_id' => 'atlas.programming.execution',
            'domain' => 'programming',
            'task_type' => 'execution',
            'risk_level' => 'high',
            'provider' => 'gpt',
            'provider_target' => 'external',
            // High-risk: a live-looking credential that must never leave the machine
            // for an external provider. The privacy/trust layer classifies this as
            // `secret` and fails the provider gate closed.
            'objective' => 'Deploy step: set api_key=sk-test0000example0000placeholder0000 then rotate it.',
        ]);

        $this->assertSame('blocked', $payload['status']);

        // blockers shape: a non-empty list of non-empty strings.
        $this->assertIsArray($payload['blockers']);
        $this->assertNotEmpty($payload['blockers']);
        foreach ($payload['blockers'] as $blocker) {
            $this->assertIsString($blocker);
            $this->assertNotSame('', $blocker);
        }

        // The privacy/trust gate is the block source: secret context -> local-only.
        $this->assertContains('privacy:secret_context_requires_local_only', $payload['blockers']);

        // Even when blocked, the gate runs all 18 blocks and never calls a provider.
        $this->assertFalse($payload['claims']['providers_invoked']);
        $this->assertCount(18, $payload['block_refs']);

        // AARF block still present in the trail with a valid runtime hash.
        $aarf = $this->blockRef($payload, 'AARF');
        $this->assertSame('Atlas Agentic RAG Framework', $aarf['name']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $aarf['runtime_hash']);
    }

    /**
     * Locate a single AUCRI block-ref by its acronym, failing loudly if the
     * 18-block audit trail does not contain it.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function blockRef(array $payload, string $acronym): array
    {
        foreach ((array) ($payload['block_refs'] ?? []) as $ref) {
            if (is_array($ref) && ($ref['acronym'] ?? null) === $acronym) {
                return $ref;
            }
        }

        $this->fail("block_refs trail is missing the {$acronym} block");
    }
}
