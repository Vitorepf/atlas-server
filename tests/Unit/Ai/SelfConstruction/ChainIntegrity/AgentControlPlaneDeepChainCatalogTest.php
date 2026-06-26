<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ChainIntegrity;

use App\Services\Ai\SelfConstruction\ChainIntegrity\AgentControlPlaneDeepChainCatalog;
use Tests\TestCase;

class AgentControlPlaneDeepChainCatalogTest extends TestCase
{
    public function test_canonical_deep_chain_is_non_empty(): void
    {
        $chain = AgentControlPlaneDeepChainCatalog::canonicalDeepChain();

        self::assertGreaterThan(20, count($chain));
    }

    public function test_canonical_deep_chain_entries_have_required_keys(): void
    {
        $chain = AgentControlPlaneDeepChainCatalog::canonicalDeepChain();
        $first = $chain[0];

        foreach (['slice_key', 'method_prefix', 'invoker_class', 'prepare_method', 'doc_bullet', 'activate_key', 'runtime_key', 'contract_capability_key', 'preflight_capability_key', 'implementation_packet_capability_key', 'invoker_service_capability_key', 'status_projection_capability_key'] as $key) {
            self::assertArrayHasKey($key, $first, "Entry must have key: {$key}");
        }
    }

    public function test_canonical_deep_chain_is_deterministic(): void
    {
        $a = AgentControlPlaneDeepChainCatalog::canonicalDeepChain();
        $b = AgentControlPlaneDeepChainCatalog::canonicalDeepChain();

        self::assertSame($a, $b);
    }

    public function test_deep_chain_entry_generates_activate_key(): void
    {
        $entry = AgentControlPlaneDeepChainCatalog::deepChainEntry(
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_something_gate',
            'someMethod',
            'SomeInvoker',
            'prepareSomething',
            'doc bullet',
        );

        // preg_replace strips only 'automatic_dispatch_scheduler_one_shot_tick_' prefix
        self::assertSame('activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_something_gate_contract', $entry['activate_key']);
    }

    public function test_deep_chain_entry_strips_contract_suffix_for_contract_slices(): void
    {
        $entry = AgentControlPlaneDeepChainCatalog::deepChainEntry(
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract',
            'someMethod',
            'SomeInvoker',
            'buildSomething',
            'doc bullet',
        );

        // For _contract slices, contractSuffix is '' so no double _contract
        self::assertSame('activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract', $entry['activate_key']);
        self::assertSame('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract', $entry['contract_capability_key']);
    }

    public function test_strip_dispatch_prefix(): void
    {
        $result = AgentControlPlaneDeepChainCatalog::stripDispatchPrefix('automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_foo');

        self::assertSame('post_start_foo', $result);
    }

    public function test_strip_dispatch_prefix_no_match(): void
    {
        $result = AgentControlPlaneDeepChainCatalog::stripDispatchPrefix('some_other_key');

        self::assertSame('some_other_key', $result);
    }

    public function test_normalize_override_chain_filters_invalid(): void
    {
        $result = AgentControlPlaneDeepChainCatalog::normalizeOverrideChain([
            ['slice_key' => 'valid', 'method_prefix' => 'mp'],
            'not_an_array',
            ['missing_slice_key' => true],
            ['slice_key' => '', 'method_prefix' => 'mp'],
        ]);

        self::assertCount(1, $result);
        self::assertSame('valid', $result[0]['slice_key']);
    }

    public function test_normalize_override_chain_preserves_fields(): void
    {
        $result = AgentControlPlaneDeepChainCatalog::normalizeOverrideChain([
            ['slice_key' => 'a', 'method_prefix' => 'mp', 'invoker_class' => 'IC', 'prepare_method' => 'pm', 'doc_bullet' => 'db', 'activate_key' => 'ak', 'runtime_key' => 'rk'],
        ]);

        self::assertSame('mp', $result[0]['method_prefix']);
        self::assertSame('IC', $result[0]['invoker_class']);
        self::assertSame('ak', $result[0]['activate_key']);
    }

    public function test_derive_activate_key_from_runtime(): void
    {
        $result = AgentControlPlaneDeepChainCatalog::deriveActivateKeyFromRuntime('automatic_dispatch_scheduler_codex_real_invoker_post_start_foo_runtime');

        self::assertSame('activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_foo', $result);
    }

    public function test_derive_activate_key_from_empty(): void
    {
        self::assertSame('', AgentControlPlaneDeepChainCatalog::deriveActivateKeyFromRuntime(''));
    }

    public function test_invoker_class_has_namespace_prefix(): void
    {
        $chain = AgentControlPlaneDeepChainCatalog::canonicalDeepChain();

        foreach ($chain as $entry) {
            self::assertStringStartsWith('App\\Services\\Ai\\SelfConstruction\\', $entry['invoker_class']);
        }
    }
}
