<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModuleBoundaryTightener;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainModuleBoundaryTightenerTest extends TestCase
{
    private function tightener(): AtlasExternalBrainModuleBoundaryTightener
    {
        return new AtlasExternalBrainModuleBoundaryTightener;
    }

    // ── AC: leaked_internal_case ────────────────────────────────────────────

    public function test_leaked_internal_case_identifies_owner_and_contract_test(): void
    {
        $r = $this->tightener()->tighten(['leaks' => [
            ['internal' => 'FooService::internalHelper', 'proposed_owner' => 'FooModule', 'consumer_count' => 0],
        ]]);

        $this->assertCount(1, $r['actions']);
        $action = $r['actions'][0];
        $this->assertSame('FooService::internalHelper', $action['internal']);
        $this->assertSame('FooModule', $action['proposed_owner']);
        $this->assertSame('tighten_boundary', $action['action']);
        $this->assertSame('contract_test_for:FooService::internalHelper', $action['required_contract_test']);
        $this->assertContains('contract_test_for:FooService::internalHelper', $r['required_contract_tests']);
    }

    public function test_leaked_internal_with_replacement_entrypoint_tightens_despite_consumers(): void
    {
        $r = $this->tightener()->tighten(['leaks' => [
            ['internal' => 'BarInternal', 'proposed_owner' => 'BarModule', 'consumer_count' => 2, 'has_replacement_entrypoint' => true],
        ]]);

        $this->assertCount(1, $r['actions']);
        $this->assertSame([], $r['held']);
    }

    // ── AC: consumer_without_replacement_hold_case ──────────────────────────

    public function test_consumer_without_replacement_hold_case(): void
    {
        $r = $this->tightener()->tighten(['leaks' => [
            ['internal' => 'BazInternal', 'proposed_owner' => 'BazModule', 'consumer_count' => 3, 'has_replacement_entrypoint' => false],
        ]]);

        $this->assertSame([], $r['actions']);
        $this->assertCount(1, $r['held']);
        $this->assertSame('BazInternal', $r['held'][0]['internal']);
        $this->assertContains('replacement_entrypoint_proof', $r['held'][0]['required_proof']);
    }

    public function test_zero_consumers_without_replacement_still_tightens(): void
    {
        // No consumers depend on it at all — nothing to preserve, safe to tighten.
        $r = $this->tightener()->tighten(['leaks' => [
            ['internal' => 'DeadInternal', 'consumer_count' => 0, 'has_replacement_entrypoint' => false],
        ]]);

        $this->assertCount(1, $r['actions']);
        $this->assertSame([], $r['held']);
    }

    // ── mixed batch ──────────────────────────────────────────────────────────

    public function test_mixed_batch_splits_actions_and_held(): void
    {
        $r = $this->tightener()->tighten(['leaks' => [
            ['internal' => 'Safe', 'consumer_count' => 0],
            ['internal' => 'Unsafe', 'consumer_count' => 2, 'has_replacement_entrypoint' => false],
        ]]);

        $this->assertSame('Safe', $r['actions'][0]['internal']);
        $this->assertSame('Unsafe', $r['held'][0]['internal']);
    }

    // ── Determinism ────────────────────────────────────────────────────────

    public function test_tighten_is_deterministic(): void
    {
        $facts = ['leaks' => [['internal' => 'X', 'consumer_count' => 0]]];
        $a = $this->tightener()->tighten($facts);
        $b = $this->tightener()->tighten($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_version_present(): void
    {
        $r = $this->tightener()->tighten([]);
        $this->assertSame(AtlasExternalBrainModuleBoundaryTightener::SCHEMA, $r['schema']);
    }
}
