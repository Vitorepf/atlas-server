<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasMultiProviderAgentOrchestrationContractService as Svc;
use Tests\TestCase;

/**
 * Pins the documented Multi-Provider Agent Orchestration rules: the Adapter Rule
 * (narrow ok, widen illegal), Evidence Normalization (closed schema + reject when
 * un-normalizable), Provider Profile routing with `generic` fallback, the L5
 * readiness gate (blocked without receipts) and the Hard Stops / disjoint
 * write-set rule.
 *
 * @see docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
 */
class AtlasMultiProviderAgentOrchestrationContractTest extends TestCase
{
    private function svc(): Svc
    {
        return new Svc();
    }

    private function basePacket(): array
    {
        return [
            'allowed_files' => ['docs/a.md', 'docs/b.md'],
            'forbidden_files' => ['runtimes/python/voice/**'],
            'required_gates' => ['docs-health'],
            'stop_conditions' => ['scope broadened'],
            'packet_hash' => 'sha256:abc',
        ];
    }

    // ---- Adapter Rule ----------------------------------------------------

    /** Narrowing allowed_files to a safer subset is the explicitly-legal path. */
    public function test_adapter_narrowing_is_legal(): void
    {
        $base = $this->basePacket();
        $adapted = array_merge($base, ['allowed_files' => ['docs/a.md']]); // dropped b.md

        $r = $this->svc()->evaluateAdapter($base, $adapted);

        $this->assertSame(Svc::ADAPTER_LEGAL, $r['verdict']);
        $this->assertTrue($r['legal']);
        $this->assertSame([], $r['violations']);
        $this->assertContains('docs/b.md', $r['narrowed_allowed_files']);
    }

    /** Adding a file to allowed_files is a widen => illegal. */
    public function test_adapter_widening_allowed_files_is_rejected(): void
    {
        $base = $this->basePacket();
        $adapted = array_merge($base, ['allowed_files' => ['docs/a.md', 'docs/b.md', 'app/secret.php']]);

        $r = $this->svc()->evaluateAdapter($base, $adapted);

        $this->assertSame(Svc::ADAPTER_REJECTED, $r['verdict']);
        $codes = array_column($r['violations'], 'code');
        $this->assertContains('widened_allowed_files', $codes);
    }

    /** Removing a forbidden file, skipping a gate, marking completion, claiming
     *  merge authority and altering the hash are each violations. */
    public function test_adapter_multiple_forbidden_mutations_are_rejected(): void
    {
        $base = $this->basePacket();
        $adapted = [
            'allowed_files' => ['docs/a.md', 'docs/b.md'],
            'forbidden_files' => [],            // removed forbidden
            'required_gates' => [],             // skipped gate
            'stop_conditions' => [],            // hid stop condition
            'packet_hash' => 'sha256:DIFFERENT', // altered hash
            'marks_completion' => true,          // marked completion
            'claims_authority' => ['merge'],     // claimed authority
        ];

        $r = $this->svc()->evaluateAdapter($base, $adapted);
        $codes = array_column($r['violations'], 'code');

        $this->assertFalse($r['legal']);
        $this->assertContains('removed_forbidden_files', $codes);
        $this->assertContains('skipped_required_gate', $codes);
        $this->assertContains('hidden_stop_condition', $codes);
        $this->assertContains('altered_packet_hash', $codes);
        $this->assertContains('adapter_marked_completion', $codes);
        $this->assertContains('adapter_claimed_authority', $codes);
    }

    // ---- Evidence Normalization -----------------------------------------

    /** A complete, clean response normalizes and completion is accepted. */
    public function test_clean_response_normalizes_and_completion_accepted(): void
    {
        $r = $this->svc()->normalizeEvidence([
            'packet_id' => 'AIP-SPLIT-0001',
            'provider' => 'codex',
            'files_changed' => ['docs/a.md'],
            'commands_run' => ['php artisan test'],
            'gates' => ['docs-health'],
            'evidence_hash' => 'sha256:deadbeef',
            'scope_deviations' => [],
            'residual_risks' => ['none'],
            'completion_claim' => 'complete',
        ]);

        $this->assertTrue($r['normalizable']);
        $this->assertTrue($r['completion_accepted']);
        $this->assertSame('codex', $r['normalized']['provider']);
        // Closed schema: every documented key present.
        foreach (['packet_id', 'provider', 'files_changed', 'commands_run', 'gates', 'evidence_hash', 'scope_deviations', 'residual_risks', 'completion_claim'] as $k) {
            $this->assertArrayHasKey($k, $r['normalized']);
        }
    }

    /** A free-form response with no packet_id / bad provider cannot be normalized;
     *  Atlas rejects completion. */
    public function test_unnormalizable_response_rejects_completion(): void
    {
        $r = $this->svc()->normalizeEvidence([
            'provider' => 'random-llm',           // not in the closed set
            'completion_claim' => 'done',          // not in complete|partial|blocked
            // packet_id missing entirely
        ]);

        $this->assertFalse($r['normalizable']);
        $this->assertFalse($r['completion_accepted']);
        $this->assertNull($r['normalized']);
        $codes = array_column($r['errors'], 'code');
        $this->assertContains('missing_packet_id', $codes);
        $this->assertContains('invalid_provider', $codes);
        $this->assertContains('invalid_completion_claim', $codes);
    }

    /** A "complete" claim that carries scope deviations is NOT accepted. */
    public function test_complete_claim_with_scope_deviation_not_accepted(): void
    {
        $r = $this->svc()->normalizeEvidence([
            'packet_id' => 'AIP-SPLIT-0002',
            'provider' => 'claude',
            'completion_claim' => 'complete',
            'scope_deviations' => ['touched app/Foo.php outside packet'],
        ]);

        $this->assertTrue($r['normalizable']);        // shape is valid
        $this->assertFalse($r['completion_accepted']); // but deviation blocks acceptance
    }

    // ---- Provider Profile routing ---------------------------------------

    public function test_routing_picks_profiles_and_falls_back_to_generic(): void
    {
        $svc = $this->svc();

        $this->assertSame('codex', $svc->routeProfile('implementation')['profile']);
        $this->assertSame('claude', $svc->routeProfile('documentation review')['profile']);
        $this->assertSame('gemini', $svc->routeProfile('broad context synthesis')['profile']);
        $this->assertSame('local_agent', $svc->routeProfile('lint and format')['profile']);

        $fallback = $svc->routeProfile('something nobody catalogued');
        $this->assertSame('generic', $fallback['profile']);
        $this->assertTrue($fallback['is_fallback']);
    }

    // ---- Readiness gate --------------------------------------------------

    /** L5 must stay BLOCKED without signed authority + reservations + gates. */
    public function test_l5_blocked_without_receipts(): void
    {
        $r = $this->svc()->gateReadiness('L5', [
            'signed_authority' => false,
            'reservations' => false,
            'gates_green' => false,
        ]);

        $this->assertTrue($r['blocked']);
        $this->assertFalse($r['allowed']);
        $this->assertTrue($r['requires_receipts']);
        $codes = array_column($r['blockers'], 'code');
        $this->assertContains('missing_signed_authority', $codes);
        $this->assertContains('missing_reservations', $codes);
        $this->assertContains('gates_not_green', $codes);
    }

    /** With all three receipts, L5 opens. */
    public function test_l5_allowed_with_full_receipts(): void
    {
        $r = $this->svc()->gateReadiness('L5', [
            'signed_authority' => true,
            'reservations' => true,
            'gates_green' => true,
        ]);

        $this->assertTrue($r['allowed']);
        $this->assertSame([], $r['blockers']);
    }

    /** L4 (multi-provider governed) is open without receipts. */
    public function test_l4_allowed_without_receipts(): void
    {
        $r = $this->svc()->gateReadiness('L4', []);

        $this->assertTrue($r['allowed']);
        $this->assertFalse($r['requires_receipts']);
        $this->assertSame('multi_provider_governed', $r['meaning']);
    }

    // ---- Hard Stops / disjoint write sets -------------------------------

    /** Two providers touching the same write set is a hard stop (Splitter rule #1). */
    public function test_overlapping_write_sets_force_halt(): void
    {
        $r = $this->svc()->detectHardStops([
            'assignments' => [
                ['provider' => 'codex', 'write_set' => ['docs/a.md', 'docs/shared.md']],
                ['provider' => 'gemini', 'write_set' => ['docs/shared.md']],
            ],
            'signals' => [],
        ]);

        $this->assertTrue($r['must_halt']);
        $codes = array_column($r['stops'], 'code');
        $this->assertContains('write_set_collision', $codes);
    }

    /** Disjoint write sets with clean signals => no halt. */
    public function test_disjoint_write_sets_are_clear(): void
    {
        $r = $this->svc()->detectHardStops([
            'assignments' => [
                ['provider' => 'codex', 'write_set' => ['docs/a.md']],
                ['provider' => 'gemini', 'write_set' => ['docs/b.md']],
            ],
            'signals' => [],
        ]);

        $this->assertTrue($r['clear']);
        $this->assertFalse($r['must_halt']);
        $this->assertSame([], $r['stops']);
    }

    /** Each documented hard-stop signal triggers a halt. */
    public function test_signal_hard_stops_trigger_halt(): void
    {
        $r = $this->svc()->detectHardStops([
            'assignments' => [],
            'signals' => [
                'provider_requests_broader_scope' => true,
                'provider_edited_forbidden_file' => true,
                'provider_cannot_report_gates' => true,
                'evidence_free_form_only' => true,
                'provider_claims_merge_authority' => true,
            ],
        ]);

        $codes = array_column($r['stops'], 'code');
        $this->assertTrue($r['must_halt']);
        $this->assertContains('scope_broadening_requested', $codes);
        $this->assertContains('forbidden_file_edited', $codes);
        $this->assertContains('gates_unreportable', $codes);
        $this->assertContains('evidence_free_form_only', $codes);
        $this->assertContains('authority_claimed', $codes);
    }
}
