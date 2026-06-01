<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCyberSecurityRemediationPatternsService;
use Tests\TestCase;

/**
 * Pins the documented Cyber Remediation Patterns contract: CWE -> owning pattern
 * resolution (one pattern per primary CWE, sub-CWEs folded in), the canonical fix
 * + anti-fixes surfaced, the mandatory Negative-PoC variant taxonomy, the Patch
 * kernel gate (Negative PoC + Regression test obligatory), the cross-pattern anti-
 * patterns (uncovered variants, anti-fix applied, < 100% coverage on security-
 * critical), and the standalone regression-script naming + exit contract.
 *
 * @see docs/engineering-knowledge-base/cyber-security/remediation-patterns.md
 */
class AtlasCyberSecurityRemediationPatternsTest extends TestCase
{
    private function service(): AtlasCyberSecurityRemediationPatternsService
    {
        return new AtlasCyberSecurityRemediationPatternsService();
    }

    /**
     * CWE-89 resolves to cyber-rem-sqli with the canonical parameterized-query fix
     * and the SQLi variant taxonomy the Negative PoC must cover. A bare "639" input
     * is normalized to CWE-639 and resolves to the IDOR/BOLA pattern (decision:
     * "Um pattern por CWE primario").
     */
    public function test_resolves_cwe_to_owning_pattern_with_fix_and_variants(): void
    {
        $svc = $this->service();

        $sqli = $svc->resolve(['cwe' => 'CWE-89']);
        $this->assertTrue($sqli['matched']);
        $this->assertSame('cyber-rem-sqli', $sqli['pattern_id']);
        $this->assertTrue($sqli['remediable']);
        $this->assertStringContainsString('parameterized', $sqli['canonical_fix']);
        $this->assertContains('time_based_blind', $sqli['required_variants']);
        $this->assertContains('second_order', $sqli['required_variants']);

        // Loose numeric input is normalized to the canonical CWE token.
        $idor = $svc->resolve(['cwe' => '639']);
        $this->assertSame('cyber-rem-idor', $idor['pattern_id']);
        $this->assertSame('CWE-639', $idor['cwe']);
    }

    /**
     * Sub-CWEs fold into the owning pattern (CWE-862/CWE-863 are listed under both
     * IDOR and authz; the catalog keeps a single owner per token) and an
     * uncatalogued CWE is NOT remediable — the doc forbids a Patch without an
     * owning pattern that carries the mandatory taxonomy.
     */
    public function test_subcwe_folding_and_uncatalogued_cwe_is_not_remediable(): void
    {
        $svc = $this->service();

        // CWE-639 is owned by IDOR (declared first), so the shared index points there.
        $index = $svc->cweIndex();
        $this->assertSame('cyber-rem-idor', $index['CWE-639']);
        $this->assertArrayHasKey('CWE-89', $index);
        $this->assertArrayHasKey('LLM01', $index);

        $unknown = $svc->resolve(['cwe' => 'CWE-99999']);
        $this->assertFalse($unknown['matched']);
        $this->assertFalse($unknown['remediable']);
        $this->assertNull($unknown['pattern_id']);
    }

    /**
     * missingVariants() returns exactly the taxonomy variants a Negative PoC has
     * not yet covered (doc: "cada variant DEVE aparecer em Negative PoC"). For XSS,
     * covering reflected+dom leaves the stored/mutation/svg/polyglot variants open.
     */
    public function test_missing_variants_reports_uncovered_taxonomy(): void
    {
        $svc = $this->service();

        $missing = $svc->missingVariants('cyber-rem-xss', ['reflected', 'dom']);

        $this->assertContains('stored', $missing);
        $this->assertContains('mutation_xss', $missing);
        $this->assertContains('polyglot', $missing);
        $this->assertNotContains('reflected', $missing);
        $this->assertNotContains('dom', $missing);

        // Full coverage leaves nothing missing.
        $full = $svc->missingVariants('cyber-rem-xss', [
            'reflected', 'stored', 'dom', 'mutation_xss', 'svg_mathml_payload', 'polyglot',
        ]);
        $this->assertSame([], $full);
    }

    /**
     * Patch kernel gate: a Patch missing the Negative PoC or the Regression test is
     * BLOCKED (decision "Negative PoC + Regression test sao obrigatorios em cada
     * Patch (gate kernel)"). A Patch that satisfies the kernel, covers the full
     * taxonomy and applies no anti-fix PASSES.
     */
    public function test_patch_gate_enforces_kernel_negative_poc_and_regression_test(): void
    {
        $svc = $this->service();

        $allVariants = [
            'is_admin_true', 'email_verified_true', 'arbitrary_account_balance', 'role_admin',
        ];

        $missingPoc = $svc->gatePatch([
            'pattern_id' => 'cyber-rem-mass-assignment',
            'has_negative_poc' => false,
            'has_regression_test' => true,
            'covered_variants' => $allVariants,
        ]);
        $this->assertSame('blocked', $missingPoc['verdict']);
        $this->assertTrue($missingPoc['blocked']);
        $this->assertContains('missing_negative_poc', array_column($missingPoc['blockers'], 'id'));

        $missingRegression = $svc->gatePatch([
            'pattern_id' => 'cyber-rem-mass-assignment',
            'has_negative_poc' => true,
            'has_regression_test' => false,
            'covered_variants' => $allVariants,
        ]);
        $this->assertSame('blocked', $missingRegression['verdict']);
        $this->assertContains('missing_regression_test', array_column($missingRegression['blockers'], 'id'));

        $clean = $svc->gatePatch([
            'pattern_id' => 'cyber-rem-mass-assignment',
            'has_negative_poc' => true,
            'has_regression_test' => true,
            'covered_variants' => $allVariants,
        ]);
        $this->assertSame('pass', $clean['verdict']);
        $this->assertFalse($clean['blocked']);
        $this->assertSame([], $clean['blockers']);
    }

    /**
     * Cross-pattern anti-patterns: a Patch that leaves taxonomy variants uncovered,
     * applies a documented anti-fix, or has < 100% coverage on a touched
     * @security-critical path is BLOCKED with the corresponding blocker ids.
     */
    public function test_patch_gate_blocks_cross_pattern_anti_patterns(): void
    {
        $svc = $this->service();

        $gate = $svc->gatePatch([
            'pattern_id' => 'cyber-rem-sqli',
            'has_negative_poc' => true,
            'has_regression_test' => true,
            'covered_variants' => ['union'], // taxonomy NOT fully covered.
            'touches_security_critical' => true,
            'security_critical_coverage_pct' => 80, // below the required 100%.
            'applied_anti_fixes' => ['manually escape quotes'], // documented anti-fix.
        ]);

        $ids = array_column($gate['blockers'], 'id');
        $this->assertSame('blocked', $gate['verdict']);
        $this->assertContains('uncovered_variants', $ids);
        $this->assertContains('security_critical_coverage_below_100', $ids);
        $this->assertContains('anti_fix_applied', $ids);

        // 100% coverage on the security-critical path clears that one blocker.
        $covered = $svc->gatePatch([
            'pattern_id' => 'cyber-rem-sqli',
            'has_negative_poc' => true,
            'has_regression_test' => true,
            'covered_variants' => ['union', 'boolean_blind', 'time_based_blind', 'error_based', 'second_order', 'stacked_queries'],
            'touches_security_critical' => true,
            'security_critical_coverage_pct' => 100,
        ]);
        $this->assertSame('pass', $covered['verdict']);
    }

    /**
     * Standalone regression script: canonical "check_F-<finding_id>.<ext>" name,
     * stack-aware extension, and the binary exit contract (0 = fix valid,
     * 1 = regression). A raw id without the "F-" prefix is normalized.
     */
    public function test_standalone_script_name_and_exit_contract(): void
    {
        $svc = $this->service();

        $py = $svc->standaloneScriptName('1234');
        $this->assertSame('check_F-1234.py', $py['filename']);
        $this->assertSame('F-1234', $py['finding_id']);
        $this->assertSame(0, $py['exit_codes']['fix_valid']);
        $this->assertSame(1, $py['exit_codes']['regression']);
        $this->assertTrue($py['self_contained']);

        // Prefix already present + a different stack.
        $go = $svc->standaloneScriptName('F-9', 'go');
        $this->assertSame('check_F-9.go', $go['filename']);
    }
}
