<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityChainBuilder;
use PHPUnit\Framework\TestCase;

final class AgentControlPlaneChainIntegrityChainBuilderTest extends TestCase
{
    private function builder(): AgentControlPlaneChainIntegrityChainBuilder
    {
        return new AgentControlPlaneChainIntegrityChainBuilder();
    }

    public function test_canonical_deep_chain_returns_entries_with_required_fields(): void
    {
        $chain = $this->builder()->canonicalDeepChain();

        $this->assertNotEmpty($chain);
        foreach ($chain as $entry) {
            $this->assertArrayHasKey('slice_key', $entry);
            $this->assertArrayHasKey('method_prefix', $entry);
            $this->assertArrayHasKey('invoker_class', $entry);
            $this->assertArrayHasKey('prepare_method', $entry);
            $this->assertArrayHasKey('doc_bullet', $entry);
            $this->assertNotEmpty($entry['slice_key']);
            $this->assertNotEmpty($entry['method_prefix']);
            $this->assertNotEmpty($entry['invoker_class']);
            $this->assertNotEmpty($entry['prepare_method']);
            $this->assertNotEmpty($entry['doc_bullet']);
        }
    }

    public function test_canonical_deep_chain_is_deterministic(): void
    {
        $a = $this->builder()->canonicalDeepChain();
        $b = $this->builder()->canonicalDeepChain();

        $this->assertSame($a, $b);
    }

    public function test_canonical_deep_chain_audit_reports_no_duplicates(): void
    {
        $audit = $this->builder()->canonicalDeepChainAudit();

        $this->assertTrue($audit['clean']);
        $this->assertSame(0, $audit['duplicate_count']);
        $this->assertSame([], $audit['blockers']);
        $this->assertNotEmpty($audit['entries']);
    }

    public function test_duplicate_slice_keys_are_detected(): void
    {
        $builder = $this->builder();
        $chain = $builder->canonicalDeepChain();
        $duplicated = array_merge($chain, [$chain[0]]);

        $audit = \App\Services\Ai\SelfConstruction\ChainIntegrity\AgentControlPlaneDeepChainCatalog::audit($duplicated);

        $this->assertFalse($audit['clean']);
        $this->assertNotEmpty($audit['blockers']);
        $this->assertContains('duplicate_slice_key:'.$chain[0]['slice_key'], $audit['blockers']);
    }

    public function test_duplicate_method_prefixes_are_detected(): void
    {
        $builder = $this->builder();
        $chain = $builder->canonicalDeepChain();
        $duplicated = array_merge($chain, [$chain[0]]);

        $audit = \App\Services\Ai\SelfConstruction\ChainIntegrity\AgentControlPlaneDeepChainCatalog::audit($duplicated);

        $this->assertContains('duplicate_method_prefix:'.$chain[0]['method_prefix'], $audit['blockers']);
    }

    public function test_dispatch_to_runtime_ordering_is_preserved(): void
    {
        $chain = $this->builder()->canonicalDeepChain();
        $sliceKeys = array_column($chain, 'slice_key');

        // Dispatch prefix should precede runtime-oriented suffixes in the canonical order.
        $dispatchIndex = array_search('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate', $sliceKeys, true);
        $runtimeIndex = array_search('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate', $sliceKeys, true);

        $this->assertNotFalse($dispatchIndex);
        $this->assertNotFalse($runtimeIndex);
        $this->assertLessThan($runtimeIndex, $dispatchIndex);
    }

    public function test_cli_base_for_slice_normalizes_underscores(): void
    {
        $builder = $this->builder();

        $this->assertSame('agent-foo-bar', $builder->cliBaseForSlice('foo_bar'));
        $this->assertSame('', $builder->cliBaseForSlice(''));
    }

    public function test_build_shallow_chain_requires_full_quintet(): void
    {
        $builder = $this->builder();

        $fullCapability = [
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_contract',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_preflight',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_implementation_packet',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_invoker_service',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_status_projection',
        ];

        $shallow = $builder->buildShallowChain($fullCapability);

        $this->assertContains('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate', $shallow);
    }

    public function test_build_shallow_chain_ignores_incomplete_quintet(): void
    {
        $builder = $this->builder();

        $incompleteCapability = [
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_contract',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_preflight',
        ];

        $shallow = $builder->buildShallowChain($incompleteCapability);

        $this->assertSame([], $shallow);
    }
}
