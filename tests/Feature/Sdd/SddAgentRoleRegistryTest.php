<?php

namespace Tests\Feature\Sdd;

use App\Services\Ai\Programming\Sdd\Agents\SddAgentRoleRegistry;
use Tests\TestCase;

class SddAgentRoleRegistryTest extends TestCase
{
    private string $token = 'roles-test-token-with-enough-length-1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        config(['atlas.token' => $this->token]);
    }

    public function test_registry_lists_canonical_fourteen_roles(): void
    {
        $names = (new SddAgentRoleRegistry())->names();
        $this->assertCount(14, $names);

        foreach ([
            'context_scout', 'product_analyst', 'business_rule_miner',
            'spec_compiler', 'spec_critic', 'architecture_agent',
            'plan_compiler', 'task_compiler', 'execution_agent',
            'qa_agent', 'security_agent', 'drift_detector',
            'evidence_agent', 'learning_curator',
        ] as $expected) {
            $this->assertContains($expected, $names, "missing canonical role {$expected}");
        }
    }

    public function test_each_role_declares_io_actions_and_evidence(): void
    {
        foreach ((new SddAgentRoleRegistry())->all() as $name => $role) {
            $this->assertNotEmpty($role['name'], "{$name} missing name");
            $this->assertNotEmpty($role['pipeline_stage'], "{$name} missing pipeline_stage");
            $this->assertIsArray($role['allowed_actions'], "{$name} missing allowed_actions");
            $this->assertIsArray($role['forbidden_actions'], "{$name} missing forbidden_actions");
            $this->assertIsArray($role['required_evidence'], "{$name} missing required_evidence");
        }
    }

    public function test_learning_curator_forbids_kernel_mutation(): void
    {
        $registry = new SddAgentRoleRegistry();
        $this->assertFalse($registry->isAllowedAction('learning_curator', 'mutate_kernel'));
        $this->assertTrue($registry->isAllowedAction('learning_curator', 'propose_template_update'));
    }

    public function test_execution_agent_forbids_writing_outside_receipt_scope(): void
    {
        $registry = new SddAgentRoleRegistry();
        $this->assertFalse($registry->isAllowedAction('execution_agent', 'write_outside_receipt_scope'));
        $this->assertTrue($registry->isAllowedAction('execution_agent', 'write_allowed_files'));
    }

    public function test_index_endpoint_returns_manifest(): void
    {
        $payload = $this->withHeader('X-Atlas-Token', $this->token)
            ->getJson('/atlas-code/sdd/agent-roles')
            ->assertOk()
            ->json();

        $this->assertSame('atlas.sdd_agent_role_registry.v1', $payload['schema_version']);
        $this->assertSame(14, $payload['count']);
    }

    public function test_show_endpoint_returns_role_or_404(): void
    {
        $this->withHeader('X-Atlas-Token', $this->token)
            ->getJson('/atlas-code/sdd/agent-roles/spec_critic')
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.sdd_agent_role.v1')
            ->assertJsonPath('key', 'spec_critic');

        $this->withHeader('X-Atlas-Token', $this->token)
            ->getJson('/atlas-code/sdd/agent-roles/nonexistent')
            ->assertStatus(404);
    }
}
