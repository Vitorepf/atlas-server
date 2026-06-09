<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphWorkspacePrivacy;
use Tests\TestCase;

/**
 * AP-815 · G-1 — workspace privacy classification.
 *
 * Pure unit test (NO database). Proves the resolution order (explicit map →
 * keyword heuristic → validated default), the always-valid-class contract, and the
 * fail-safe direction: any ambiguity or malformed input can only escalate privacy
 * or fall back to a safe class — never emit an invalid class or under-classify a
 * sovereign workspace.
 */
class CodeGraphWorkspacePrivacyTest extends TestCase
{
    private function svc(): CodeGraphWorkspacePrivacy
    {
        return new CodeGraphWorkspacePrivacy();
    }

    // --- Happy path -------------------------------------------------------

    public function test_explicit_map_returns_the_configured_class(): void
    {
        config(['atlas.code_graph.workspace_privacy' => [
            'finance-engine' => 'secret',
            'marketing-site' => 'public',
        ]]);

        $svc = $this->svc();

        $this->assertSame('secret', $svc->classOf('finance-engine'));
        $this->assertTrue($svc->isSensitive('finance-engine'));
        $this->assertSame('public', $svc->classOf('marketing-site'));
        $this->assertFalse($svc->isSensitive('marketing-site'));
    }

    public function test_cyber_keyword_heuristic_classifies_and_is_sensitive(): void
    {
        $svc = $this->svc();

        $this->assertSame('cyber', $svc->classOf('blackink-cyber'));
        $this->assertTrue($svc->isSensitive('blackink-cyber'));
        // 'security' is the other cyber keyword.
        $this->assertSame('cyber', $svc->classOf('acme-security-scanner'));
    }

    public function test_secret_and_sensitive_keyword_families(): void
    {
        $svc = $this->svc();

        // secret family
        $this->assertSame('secret', $svc->classOf('app-secret-store'));
        $this->assertSame('secret', $svc->classOf('hashicorp-vault'));
        $this->assertSame('secret', $svc->classOf('signing-keys'));

        // sensitive family
        $this->assertSame('sensitive', $svc->classOf('finance-ledger'));
        $this->assertSame('sensitive', $svc->classOf('patient-health-records'));
        $this->assertSame('sensitive', $svc->classOf('stripe-payment-svc'));
        $this->assertSame('sensitive', $svc->classOf('personal-notes'));

        foreach (['app-secret-store', 'hashicorp-vault', 'finance-ledger', 'personal-notes'] as $id) {
            $this->assertTrue($svc->isSensitive($id), "{$id} must read as sensitive");
        }
    }

    // --- Edge cases -------------------------------------------------------

    public function test_unknown_workspace_falls_back_to_internal_and_not_sensitive(): void
    {
        $svc = $this->svc();

        $this->assertSame('internal', $svc->classOf('some-random-app'));
        $this->assertFalse($svc->isSensitive('some-random-app'));
    }

    public function test_invalid_configured_class_in_map_falls_back_to_default(): void
    {
        config(['atlas.code_graph.workspace_privacy' => [
            'weird-ws' => 'top-double-secret', // not a valid class
        ]]);

        // No keyword in 'weird-ws' → invalid mapped class drops to the default.
        $this->assertSame('internal', $this->svc()->classOf('weird-ws'));
    }

    public function test_empty_or_whitespace_id_resolves_to_default(): void
    {
        $svc = $this->svc();

        $this->assertSame('internal', $svc->classOf(''));
        $this->assertSame('internal', $svc->classOf('    '));
        $this->assertFalse($svc->isSensitive(''));
    }

    public function test_lookup_is_case_and_whitespace_insensitive(): void
    {
        config(['atlas.code_graph.workspace_privacy' => [
            'Finance-Engine' => 'SECRET',
        ]]);

        $svc = $this->svc();

        // Both id and class are normalized on lookup + validation.
        $this->assertSame('secret', $svc->classOf('  finance-ENGINE  '));
        // Heuristic is also case-insensitive.
        $this->assertSame('cyber', $svc->classOf('BLACKINK-CYBER'));
    }

    public function test_explicit_map_overrides_keyword_heuristic(): void
    {
        // 'cyber' keyword would heuristically yield 'cyber', but the operator pins
        // it down to 'internal' — the explicit map is canonical and wins.
        config(['atlas.code_graph.workspace_privacy' => [
            'demo-cyber-playground' => 'internal',
        ]]);

        $svc = $this->svc();

        $this->assertSame('internal', $svc->classOf('demo-cyber-playground'));
        $this->assertFalse($svc->isSensitive('demo-cyber-playground'));
    }

    public function test_most_restrictive_keyword_wins_on_collision(): void
    {
        $svc = $this->svc();

        // id matches both the cyber ('security') and sensitive ('finance') families
        // → cyber wins (privacy escalates, never relaxes).
        $this->assertSame('cyber', $svc->classOf('finance-security-tool'));
        // secret beats sensitive.
        $this->assertSame('secret', $svc->classOf('payment-vault'));
    }

    public function test_configured_default_class_is_honoured_when_valid(): void
    {
        config(['atlas.code_graph.default_privacy_class' => 'sensitive']);

        $svc = $this->svc();

        // An id with no map entry and no keyword now defaults to the configured class.
        $this->assertSame('sensitive', $svc->classOf('nondescript-app'));
        $this->assertTrue($svc->isSensitive('nondescript-app'));
    }

    public function test_invalid_configured_default_collapses_to_internal_floor(): void
    {
        config(['atlas.code_graph.default_privacy_class' => 'banana']);

        $this->assertSame('internal', $this->svc()->classOf('nondescript-app'));

        // Non-string default is equally safe.
        config(['atlas.code_graph.default_privacy_class' => ['not', 'a', 'string']]);
        $this->assertSame('internal', $this->svc()->classOf('nondescript-app'));
    }

    public function test_malformed_map_config_never_throws_and_falls_through(): void
    {
        // Non-array map → ignored, heuristic still applies.
        config(['atlas.code_graph.workspace_privacy' => 'not-an-array']);
        $this->assertSame('cyber', $this->svc()->classOf('blackink-cyber'));
        $this->assertSame('internal', $this->svc()->classOf('plain-app'));

        // Map with a non-string value for the matching id → falls through to default.
        config(['atlas.code_graph.workspace_privacy' => ['plain-app' => ['secret']]]);
        $this->assertSame('internal', $this->svc()->classOf('plain-app'));

        // Map with non-string KEYS must be skipped, not crash.
        config(['atlas.code_graph.workspace_privacy' => [0 => 'secret', 'plain-app' => 'public']]);
        $this->assertSame('public', $this->svc()->classOf('plain-app'));
    }

    public function test_classOf_always_returns_a_member_of_the_allowed_set(): void
    {
        $svc = $this->svc();
        $ids = ['', '  ', 'plain', 'blackink-cyber', 'app-vault', 'finance', 'weird/id with spaces'];

        foreach ($ids as $id) {
            $this->assertContains(
                $svc->classOf($id),
                CodeGraphWorkspacePrivacy::CLASSES,
                "classOf({$id}) must be a valid class"
            );
        }
    }
}
