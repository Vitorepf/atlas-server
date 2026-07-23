<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientInvocationBoundary;
use Tests\TestCase;

final class AtlasExternalBrainLocalClientInvocationBoundaryTest extends TestCase
{
    private AtlasExternalBrainLocalClientInvocationBoundary $boundary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boundary = new AtlasExternalBrainLocalClientInvocationBoundary;
    }

    private function safeFacts(array $overrides = []): array
    {
        return array_merge([
            'replaceable' => true,
            'scoped' => true,
            'non_authoritative' => true,
            'requires_raw_secret_passthrough' => false,
            'requires_broad_filesystem_authority' => false,
            'requires_direct_git_commit_rights' => false,
            'requires_direct_atlas_task_report_rights' => false,
            'requires_paid_api_credentials' => false,
        ], $overrides);
    }

    public function test_returns_full_static_boundary_contract(): void
    {
        $result = $this->boundary->describe($this->safeFacts());

        $this->assertSame(AtlasExternalBrainLocalClientInvocationBoundary::SCHEMA, $result['schema']);
        foreach ([
            'allowed_inputs',
            'forbidden_inputs',
            'expected_output_shape',
            'timeout_policy',
            'allowed_files_scope_rule',
            'no_git_rule',
            'no_secret_rule',
            'atlas_report_commit_owner',
        ] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertTrue($result['atlas_report_commit_owner']);
        $this->assertNotEmpty($result['allowed_inputs']);
        $this->assertNotEmpty($result['forbidden_inputs']);
    }

    public function test_replaceable_scoped_non_authoritative_with_no_blockers_is_ready(): void
    {
        $result = $this->boundary->describe($this->safeFacts());

        $this->assertTrue($result['invocation_contract_ready']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_raw_secret_passthrough_blocks(): void
    {
        $result = $this->boundary->describe($this->safeFacts(['requires_raw_secret_passthrough' => true]));

        $this->assertContains('raw_secret_passthrough_required', $result['blockers']);
        $this->assertFalse($result['invocation_contract_ready']);
    }

    public function test_broad_filesystem_authority_blocks(): void
    {
        $result = $this->boundary->describe($this->safeFacts(['requires_broad_filesystem_authority' => true]));

        $this->assertContains('broad_filesystem_authority_required', $result['blockers']);
        $this->assertFalse($result['invocation_contract_ready']);
    }

    public function test_direct_git_commit_rights_blocks(): void
    {
        $result = $this->boundary->describe($this->safeFacts(['requires_direct_git_commit_rights' => true]));

        $this->assertContains('direct_git_commit_rights_required', $result['blockers']);
        $this->assertFalse($result['invocation_contract_ready']);
    }

    public function test_direct_atlas_task_report_rights_blocks(): void
    {
        $result = $this->boundary->describe($this->safeFacts(['requires_direct_atlas_task_report_rights' => true]));

        $this->assertContains('direct_atlas_task_report_rights_required', $result['blockers']);
        $this->assertFalse($result['invocation_contract_ready']);
    }

    public function test_paid_api_credentials_blocks(): void
    {
        $result = $this->boundary->describe($this->safeFacts(['requires_paid_api_credentials' => true]));

        $this->assertContains('paid_api_credentials_required', $result['blockers']);
        $this->assertFalse($result['invocation_contract_ready']);
    }

    public function test_not_replaceable_blocks_readiness_even_without_other_blockers(): void
    {
        $result = $this->boundary->describe($this->safeFacts(['replaceable' => false]));

        $this->assertFalse($result['invocation_contract_ready']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_not_scoped_blocks_readiness(): void
    {
        $result = $this->boundary->describe($this->safeFacts(['scoped' => false]));

        $this->assertFalse($result['invocation_contract_ready']);
    }

    public function test_not_non_authoritative_blocks_readiness(): void
    {
        $result = $this->boundary->describe($this->safeFacts(['non_authoritative' => false]));

        $this->assertFalse($result['invocation_contract_ready']);
    }

    public function test_empty_input_is_not_ready_with_no_blockers(): void
    {
        $result = $this->boundary->describe([]);

        $this->assertFalse($result['invocation_contract_ready']);
        $this->assertSame([], $result['blockers']);
    }
}
