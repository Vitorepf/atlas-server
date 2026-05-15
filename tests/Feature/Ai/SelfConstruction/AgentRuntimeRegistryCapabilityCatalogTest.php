<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryCapabilityCatalog;
use Tests\TestCase;

final class AgentRuntimeRegistryCapabilityCatalogTest extends TestCase
{
    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_runtime_registry_capability_catalog.v1', AgentRuntimeRegistryCapabilityCatalog::SCHEMA_VERSION);
        $this->assertContains('code_edit', AgentRuntimeRegistryCapabilityCatalog::CAPABILITIES);
        $this->assertContains('workspace_isolation', AgentRuntimeRegistryCapabilityCatalog::CAPABILITIES);
        $this->assertContains('continuation_summary', AgentRuntimeRegistryCapabilityCatalog::CAPABILITIES);
    }

    public function test_catalog_lists_canonical_capabilities(): void
    {
        $catalog = (new AgentRuntimeRegistryCapabilityCatalog)->catalog();
        $this->assertSame(AgentRuntimeRegistryCapabilityCatalog::SCHEMA_VERSION, $catalog['schema_version']);
        $this->assertSame(count(AgentRuntimeRegistryCapabilityCatalog::CAPABILITIES), $catalog['capability_count']);
        $names = array_map(static fn (array $c): string => (string) $c['capability'], (array) $catalog['capabilities']);
        $this->assertContains('code_review', $names);
        $this->assertContains('docs_writer', $names);
        $this->assertContains('cost_reporting', $names);
        $this->assertContains('human_approval', $names);
        foreach ((array) $catalog['capabilities'] as $entry) {
            $this->assertArrayNotHasKey('description', $entry);
        }
        $this->assertFalse($catalog['dispatch_allowed']);
        $this->assertFalse($catalog['runtime_execution_allowed']);
        $this->assertFalse($catalog['ledger_write_allowed']);
    }

    public function test_catalog_with_descriptions(): void
    {
        $catalog = (new AgentRuntimeRegistryCapabilityCatalog)->catalog(['include_description' => true]);
        foreach ((array) $catalog['capabilities'] as $entry) {
            $this->assertNotEmpty($entry['description']);
        }
    }

    public function test_normalize_lowercases_dedupes_sorts(): void
    {
        $svc = new AgentRuntimeRegistryCapabilityCatalog;
        $this->assertSame(['code_edit', 'docs_writer'], $svc->normalizeCapabilities([' Code_Edit ', 'docs_writer', 'CODE_EDIT', '']));
    }

    public function test_normalize_empty_returns_empty(): void
    {
        $svc = new AgentRuntimeRegistryCapabilityCatalog;
        $this->assertSame([], $svc->normalizeCapabilities([]));
        $this->assertSame([], $svc->normalizeCapabilities(['', '   ']));
    }

    public function test_validate_detects_unknown(): void
    {
        $svc = new AgentRuntimeRegistryCapabilityCatalog;
        $result = $svc->validateCapabilities(['code_edit', 'foo_bar']);
        $this->assertFalse($result['is_valid']);
        $this->assertSame(['foo_bar'], $result['unknown']);
        $this->assertSame(['code_edit'], $result['known']);
        $this->assertFalse($result['dispatch_allowed']);
    }

    public function test_validate_all_known(): void
    {
        $svc = new AgentRuntimeRegistryCapabilityCatalog;
        $result = $svc->validateCapabilities(['code_edit', 'docs_writer']);
        $this->assertTrue($result['is_valid']);
        $this->assertSame([], $result['unknown']);
        $this->assertSame(['code_edit', 'docs_writer'], $result['known']);
    }

    public function test_match_full(): void
    {
        $svc = new AgentRuntimeRegistryCapabilityCatalog;
        $result = $svc->match(['code_edit', 'docs_writer'], ['code_edit', 'docs_writer', 'cost_reporting']);
        $this->assertSame('matched', $result['match_status']);
        $this->assertSame(1.0, $result['match_score']);
        $this->assertSame([], $result['missing']);
        $this->assertSame(['cost_reporting'], $result['extra']);
    }

    public function test_match_partial(): void
    {
        $svc = new AgentRuntimeRegistryCapabilityCatalog;
        $result = $svc->match(['code_edit', 'docs_writer'], ['code_edit']);
        $this->assertSame('partial', $result['match_status']);
        $this->assertSame(0.5, $result['match_score']);
        $this->assertSame(['docs_writer'], $result['missing']);
    }

    public function test_match_missing(): void
    {
        $svc = new AgentRuntimeRegistryCapabilityCatalog;
        $result = $svc->match(['code_edit'], ['docs_writer']);
        $this->assertSame('missing', $result['match_status']);
        $this->assertSame(0.0, $result['match_score']);
        $this->assertSame(['code_edit'], $result['missing']);
    }

    public function test_match_empty_required_is_matched(): void
    {
        $svc = new AgentRuntimeRegistryCapabilityCatalog;
        $result = $svc->match([], ['code_edit']);
        $this->assertSame('matched', $result['match_status']);
        $this->assertSame(1.0, $result['match_score']);
    }

    public function test_match_score_stable(): void
    {
        $svc = new AgentRuntimeRegistryCapabilityCatalog;
        $a = $svc->match(['code_edit', 'docs_writer'], ['code_edit']);
        $b = $svc->match(['code_edit', 'docs_writer'], ['code_edit']);
        $this->assertSame($a['match_score'], $b['match_score']);
        $this->assertSame($a['match_status'], $b['match_status']);
    }

    public function test_runtime_flags_all_false(): void
    {
        $svc = new AgentRuntimeRegistryCapabilityCatalog;
        foreach ($svc->runtimeFlags() as $key => $value) {
            $this->assertFalse($value, "flag {$key} must remain false");
        }
    }

    public function test_match_outputs_runtime_flags(): void
    {
        $svc = new AgentRuntimeRegistryCapabilityCatalog;
        $result = $svc->match(['code_edit'], ['code_edit']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
    }

    public function test_normalize_preserves_canonical_order(): void
    {
        $svc = new AgentRuntimeRegistryCapabilityCatalog;
        $this->assertSame(['code_edit', 'docs_writer', 'human_approval'], $svc->normalizeCapabilities(['human_approval', 'docs_writer', 'code_edit']));
    }

    public function test_validate_outputs_runtime_flags(): void
    {
        $svc = new AgentRuntimeRegistryCapabilityCatalog;
        $result = $svc->validateCapabilities(['code_edit']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
    }

    public function test_match_extra_capabilities_listed(): void
    {
        $svc = new AgentRuntimeRegistryCapabilityCatalog;
        $result = $svc->match(['code_edit'], ['code_edit', 'cost_reporting', 'docs_writer']);
        $this->assertEqualsCanonicalizing(['cost_reporting', 'docs_writer'], $result['extra']);
    }

    public function test_catalog_entries_canonical_flag(): void
    {
        $svc = new AgentRuntimeRegistryCapabilityCatalog;
        foreach ($svc->catalog()['capabilities'] as $entry) {
            $this->assertTrue($entry['canonical']);
        }
    }
}
