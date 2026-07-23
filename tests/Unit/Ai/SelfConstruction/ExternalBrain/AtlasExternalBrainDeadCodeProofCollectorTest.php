<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDeadCodeProofCollector;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDeadCodeProofCollectorTest extends TestCase
{
    private function collector(): AtlasExternalBrainDeadCodeProofCollector
    {
        return new AtlasExternalBrainDeadCodeProofCollector;
    }

    private function fullProofCandidate(array $overrides = []): array
    {
        return array_merge([
            'symbol' => 'App\\Services\\Foo\\DeadService',
            'search_evidence' => 'rg "DeadService" app tests → 0 matches outside its own file',
            'route_reference_found' => false,
            'command_reference_found' => false,
            'consumer_reference_found' => false,
            'dynamic_reference_suspected' => false,
            'search_ambiguous' => false,
            'replacement_proof' => 'callers migrated to NewService in commit abc123',
        ], $overrides);
    }

    // ── AC: confirmed_dead_code_case — every required proof present ──────────

    public function test_confirmed_dead_code_case_when_all_required_proofs_present(): void
    {
        $r = $this->collector()->collect(['candidate' => $this->fullProofCandidate()]);

        $this->assertTrue($r['dead_code_confirmed']);
        $this->assertFalse($r['needs_manual_or_runtime_probe']);
        $this->assertSame([], $r['missing_proofs']);
    }

    public function test_missing_search_evidence_blocks_confirmation(): void
    {
        $r = $this->collector()->collect(['candidate' => $this->fullProofCandidate(['search_evidence' => ''])]);

        $this->assertFalse($r['dead_code_confirmed']);
        $this->assertContains('missing_search_evidence', $r['missing_proofs']);
    }

    public function test_route_reference_present_blocks_confirmation(): void
    {
        $r = $this->collector()->collect(['candidate' => $this->fullProofCandidate(['route_reference_found' => true])]);

        $this->assertFalse($r['dead_code_confirmed']);
        $this->assertContains('route_reference_present', $r['missing_proofs']);
    }

    public function test_command_reference_present_blocks_confirmation(): void
    {
        $r = $this->collector()->collect(['candidate' => $this->fullProofCandidate(['command_reference_found' => true])]);

        $this->assertFalse($r['dead_code_confirmed']);
        $this->assertContains('command_reference_present', $r['missing_proofs']);
    }

    public function test_consumer_reference_present_blocks_confirmation(): void
    {
        $r = $this->collector()->collect(['candidate' => $this->fullProofCandidate(['consumer_reference_found' => true])]);

        $this->assertFalse($r['dead_code_confirmed']);
        $this->assertContains('consumer_reference_present', $r['missing_proofs']);
    }

    public function test_missing_replacement_proof_blocks_confirmation(): void
    {
        $r = $this->collector()->collect(['candidate' => $this->fullProofCandidate(['replacement_proof' => ''])]);

        $this->assertFalse($r['dead_code_confirmed']);
        $this->assertContains('missing_replacement_proof', $r['missing_proofs']);
    }

    // ── AC: dynamic_reference_guard_case — ambiguous/dynamic → manual probe ──

    public function test_dynamic_reference_guard_case_never_confirms_deletion(): void
    {
        $r = $this->collector()->collect(['candidate' => $this->fullProofCandidate(['dynamic_reference_suspected' => true])]);

        $this->assertFalse($r['dead_code_confirmed']);
        $this->assertTrue($r['needs_manual_or_runtime_probe']);
        $this->assertStringContainsString('dynamic_reference_suspected', $r['reason']);
    }

    public function test_ambiguous_search_never_confirms_deletion(): void
    {
        $r = $this->collector()->collect(['candidate' => $this->fullProofCandidate(['search_ambiguous' => true])]);

        $this->assertFalse($r['dead_code_confirmed']);
        $this->assertTrue($r['needs_manual_or_runtime_probe']);
        $this->assertStringContainsString('search_ambiguous', $r['reason']);
    }

    public function test_dynamic_reference_guard_overrides_otherwise_full_proof(): void
    {
        // Even with every other proof satisfied, a dynamic reference must still block confirmation.
        $r = $this->collector()->collect(['candidate' => $this->fullProofCandidate([
            'dynamic_reference_suspected' => true,
        ])]);

        $this->assertFalse($r['dead_code_confirmed']);
        $this->assertTrue($r['needs_manual_or_runtime_probe']);
    }

    // ── Determinism ─────────────────────────────────────────────────────────

    public function test_collect_is_deterministic(): void
    {
        $facts = ['candidate' => $this->fullProofCandidate()];
        $a = $this->collector()->collect($facts);
        $b = $this->collector()->collect($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_version_present(): void
    {
        $r = $this->collector()->collect([]);
        $this->assertSame(AtlasExternalBrainDeadCodeProofCollector::SCHEMA, $r['schema']);
    }

    public function test_empty_candidate_reports_all_missing_proofs(): void
    {
        $r = $this->collector()->collect([]);

        $this->assertFalse($r['dead_code_confirmed']);
        $this->assertContains('missing_search_evidence', $r['missing_proofs']);
        $this->assertContains('missing_replacement_proof', $r['missing_proofs']);
    }
}
