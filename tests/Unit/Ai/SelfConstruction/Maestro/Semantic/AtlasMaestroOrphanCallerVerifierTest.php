<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Semantic;

use App\Services\Ai\SelfConstruction\Maestro\Semantic\AtlasMaestroOrphanCallerVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Proves the orphan-caller verifier: accepts a wiring site whose sibling call shares a role token, rejects the
 * sibling-by-role-token mismatch (surfacing expected vs observed), and skips non-orphan-wiring packets.
 */
final class AtlasMaestroOrphanCallerVerifierTest extends TestCase
{
    /** A fake symbol resolver (the real one is final) — existence is irrelevant to the role-token check. */
    private function verifier(bool $resolves = false): AtlasMaestroOrphanCallerVerifier
    {
        $resolver = new class($resolves)
        {
            public function __construct(private bool $resolves) {}

            /** @return array<string,mixed> */
            public function resolve(string $symbol): array
            {
                return $this->resolves ? ['symbol' => $symbol, 'exists' => true, 'file' => 'app/X.php', 'line' => 1, 'kind' => 'class'] : ['symbol' => $symbol, 'exists' => false];
            }
        };

        return new AtlasMaestroOrphanCallerVerifier($resolver);
    }

    public function test_accepts_site_with_role_overlapping_sibling(): void
    {
        $out = $this->verifier(true)->verify([
            'orphan_target' => 'App\\Services\\Ai\\SelfConstruction\\AtlasTaskClaimInspector',
            'insertion_site' => [
                'file' => 'app/Foo.php',
                'line' => 10,
                'enclosing_source' => 'public function inspect(): void { $this->run(new AtlasTaskPacketQualityInspector()); }',
            ],
        ]);

        $this->assertTrue($out['ok']);
        $this->assertTrue($out['sibling_role_match']);
        $this->assertSame('AtlasTaskPacketQualityInspector', $out['sibling']);
        $this->assertContains('inspector', $out['overlap']);
    }

    public function test_rejects_sibling_role_mismatch_with_expected_vs_observed(): void
    {
        $out = $this->verifier()->verify([
            'orphan_target' => 'App\\Services\\Ai\\SelfConstruction\\Maestro\\Semantic\\AtlasMaestroSemanticAuditPanel',
            'insertion_site' => [
                'file' => 'app/Bar.php',
                'line' => 20,
                'enclosing_source' => 'public function commit(): void { (new AtlasTaskScopedCommitter())->commit(); }',
            ],
        ]);

        $this->assertFalse($out['ok']);
        $this->assertSame('sibling_role_mismatch', $out['reason']);
        $this->assertSame('AtlasTaskScopedCommitter', $out['observed_sibling']);
        $this->assertContains('audit', $out['expected_role_tokens']);
        $this->assertContains('panel', $out['expected_role_tokens']);
    }

    public function test_empty_callers_are_rejected(): void
    {
        $out = $this->verifier()->verify([
            'orphan_target' => 'App\\Services\\AtlasTaskClaimInspector',
            'callers' => [],
            'insertion_site' => ['file' => 'app/Foo.php', 'line' => 10, 'enclosing_source' => 'some source'],
        ]);

        $this->assertFalse($out['ok']);
        $this->assertSame('no_live_callers', $out['reason']);
    }

    public function test_suffix_only_caller_is_rejected(): void
    {
        $out = $this->verifier()->verify([
            'orphan_target' => 'App\\Services\\AtlasTaskClaimInspector',
            'callers' => ['ClaimInspector'],
            'insertion_site' => ['file' => 'app/Foo.php', 'line' => 10, 'enclosing_source' => 'some source'],
        ]);

        $this->assertFalse($out['ok']);
        $this->assertSame('suffix_only_match', $out['reason']);
        $this->assertSame('ClaimInspector', $out['caller']);
    }

    public function test_basename_only_caller_is_rejected(): void
    {
        $out = $this->verifier()->verify([
            'orphan_target' => 'App\\Services\\AtlasTaskClaimInspector',
            'callers' => ['AtlasTaskClaimInspector'],
            'insertion_site' => ['file' => 'app/Foo.php', 'line' => 10, 'enclosing_source' => 'some source'],
        ]);

        $this->assertFalse($out['ok']);
        $this->assertSame('basename_only_match', $out['reason']);
        $this->assertSame('AtlasTaskClaimInspector', $out['caller']);
    }

    public function test_real_caller_path_with_method_reference_passes(): void
    {
        $out = $this->verifier(true)->verify([
            'orphan_target' => 'App\\Services\\Ai\\SelfConstruction\\AtlasTaskClaimInspector',
            'callers' => ['app/Services/Ai/SelfConstruction/Orchestrator.php::handle'],
            'insertion_site' => [
                'file' => 'app/Foo.php',
                'line' => 10,
                'enclosing_source' => 'public function handle(): void { $this->run(new AtlasTaskPacketQualityInspector()); }',
            ],
        ]);

        $this->assertTrue($out['ok']);
    }

    public function test_skips_non_orphan_wiring_packets(): void
    {
        $out = $this->verifier()->verify([
            'orphan_target' => 'App\\Services\\Ai\\AutonomousEvolution\\Generated\\NewThing',
            // no insertion_site ⇒ a pure new-file packet
        ]);

        $this->assertTrue($out['ok']);
        $this->assertTrue($out['skipped']);
    }

    // ── AC: no live caller + no symbol grounding ⇒ true_orphan ────────────────

    public function test_no_caller_and_no_symbol_grounding_is_classified_true_orphan(): void
    {
        $out = $this->verifier(false)->verify([
            'orphan_target' => 'App\\Services\\Ai\\SelfConstruction\\Maestro\\Semantic\\AtlasMaestroSemanticAuditPanel',
            'insertion_site' => [
                'file' => 'app/Bar.php',
                'line' => 20,
                'enclosing_source' => 'public function commit(): void { (new AtlasTaskScopedCommitter())->commit(); }',
            ],
        ]);

        $this->assertFalse($out['caller_evidence']);
        $this->assertFalse($out['target_resolved']);
        $this->assertSame('true_orphan', $out['classification']);
        $this->assertSame('retire', $out['safe_action']);
    }

    public function test_empty_callers_and_no_symbol_grounding_is_classified_true_orphan(): void
    {
        $out = $this->verifier(false)->verify([
            'orphan_target' => 'App\\Services\\AtlasTaskClaimInspector',
            'callers' => [],
            'insertion_site' => ['file' => 'app/Foo.php', 'line' => 10, 'enclosing_source' => 'some source'],
        ]);

        $this->assertFalse($out['caller_evidence']);
        $this->assertSame('true_orphan', $out['classification']);
        $this->assertSame('retire', $out['safe_action']);
    }

    // ── AC: live caller evidence ⇒ not_orphan ──────────────────────────────────

    public function test_live_caller_evidence_via_sibling_role_match_is_classified_not_orphan(): void
    {
        $out = $this->verifier(true)->verify([
            'orphan_target' => 'App\\Services\\Ai\\SelfConstruction\\AtlasTaskClaimInspector',
            'insertion_site' => [
                'file' => 'app/Foo.php',
                'line' => 10,
                'enclosing_source' => 'public function inspect(): void { $this->run(new AtlasTaskPacketQualityInspector()); }',
            ],
        ]);

        $this->assertTrue($out['caller_evidence']);
        $this->assertSame('not_orphan', $out['classification']);
        $this->assertSame('serve', $out['safe_action']);
    }

    public function test_live_caller_evidence_via_validated_explicit_caller_is_classified_not_orphan(): void
    {
        $out = $this->verifier()->verify([
            'orphan_target' => 'App\\Services\\AtlasTaskClaimInspector',
            'callers' => ['app/Services/Ai/SelfConstruction/Orchestrator.php::handle'],
            'insertion_site' => ['file' => 'app/Foo.php', 'line' => 10, 'enclosing_source' => 'no relevant sibling here'],
        ]);

        $this->assertTrue($out['caller_evidence']);
        $this->assertSame('not_orphan', $out['classification']);
        $this->assertSame('serve', $out['safe_action']);
    }

    // ── AC: output includes caller_evidence and safe_action ────────────────────

    public function test_verifier_output_always_includes_caller_evidence_and_safe_action_when_not_skipped(): void
    {
        $out = $this->verifier()->verify([
            'orphan_target' => 'App\\Services\\Ai\\SelfConstruction\\Maestro\\Semantic\\AtlasMaestroSemanticAuditPanel',
            'insertion_site' => [
                'file' => 'app/Bar.php',
                'line' => 20,
                'enclosing_source' => 'public function commit(): void { (new AtlasTaskScopedCommitter())->commit(); }',
            ],
        ]);

        $this->assertArrayHasKey('caller_evidence', $out);
        $this->assertArrayHasKey('safe_action', $out);
        $this->assertArrayHasKey('classification', $out);
    }

    public function test_symbol_grounded_but_uncalled_is_ambiguous_not_true_orphan(): void
    {
        $out = $this->verifier(true)->verify([
            'orphan_target' => 'App\\Services\\Ai\\SelfConstruction\\Maestro\\Semantic\\AtlasMaestroSemanticAuditPanel',
            'insertion_site' => [
                'file' => 'app/Bar.php',
                'line' => 20,
                'enclosing_source' => 'public function commit(): void { (new AtlasTaskScopedCommitter())->commit(); }',
            ],
        ]);

        $this->assertFalse($out['caller_evidence']);
        $this->assertTrue($out['target_resolved']);
        $this->assertSame('ambiguous', $out['classification']);
        $this->assertSame('needs_review', $out['safe_action']);
    }

    public function test_traversal_file_read_in_read_enclosing_returns_empty(): void
    {
        $out = $this->verifier()->verify([
            'orphan_target' => 'App\\Services\\AtlasTaskClaimInspector',
            'callers' => ['app/Services/SomeCaller.php::someMethod'],
            'insertion_site' => [
                'file' => '../../etc/passwd',
                'line' => 10,
            ],
        ]);

        $this->assertSame('', $out['observed_sibling'] ?? '');
    }
}
