<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCyberRecipesCatalogService;
use Tests\TestCase;

/**
 * Pins the documented Atlas Cyber Recipes Catalog admission rules:
 * Required Recipe Contract + Hard Safety Rules + anti-duplication.
 *
 * @see docs/engineering-knowledge-base/cyber-security/recipes-catalog.md
 */
class AtlasCyberRecipesCatalogTest extends TestCase
{
    private function service(): AtlasCyberRecipesCatalogService
    {
        return new AtlasCyberRecipesCatalogService();
    }

    /**
     * A fully-compliant offensive recipe (all required fields, dry_run default
     * true, evidence on, approval recorded for an extra-approval category).
     *
     * @return array<string,mixed>
     */
    private function compliantOffensiveRecipe(): array
    {
        return [
            'tool_slug' => 'atlas-active-exploit-runner',
            'recipe_name' => 'web-exploit-validate',
            'category' => 'active_exploit',
            'argv' => ['--target', '{{scope.in}}'],
            'dry_run_default' => true,
            'creates_evidence' => true,
            'blocking_capable' => false,
            'execution_tier' => 'T2',
            'sandbox' => 'dedicated_vm',
            'privacy_level' => 'secret',
            'task_type' => 'offensive_validation',
            'authority_group' => 'cyber_offense',
            'offensive' => true,
            'approval_recorded' => true,
        ];
    }

    /** Full contract + safety satisfied => admit, with mandatory pre-exec obligations surfaced. */
    public function test_fully_compliant_offensive_recipe_is_admitted(): void
    {
        $result = $this->service()->evaluateRecipe($this->compliantOffensiveRecipe());

        $this->assertSame('admit', $result['verdict']);
        $this->assertTrue($result['admitted']);
        $this->assertSame([], $result['missing_fields']);
        $this->assertSame([], $result['violations']);
        // "Scope proof and refusal matrix run before execution" + Evidence Ledger.
        $this->assertSame(
            ['scope_proof', 'refusal_matrix', 'evidence_ledger'],
            $result['pre_execution_obligations'],
        );
    }

    /** Required Recipe Contract: dropping mandatory fields => reject + names them. */
    public function test_missing_required_contract_fields_are_rejected_and_listed(): void
    {
        $recipe = $this->compliantOffensiveRecipe();
        unset($recipe['execution_tier'], $recipe['authority_group'], $recipe['privacy_level']);

        $result = $this->service()->evaluateRecipe($recipe);

        $this->assertSame('reject', $result['verdict']);
        $this->assertEqualsCanonicalizing(
            ['execution_tier', 'authority_group', 'privacy_level'],
            $result['missing_fields'],
        );
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('missing_required_fields', $codes);
    }

    /** Hard Safety Rule: an offensive recipe must default dry_run_default=true. */
    public function test_offensive_recipe_without_dry_run_default_is_rejected(): void
    {
        $recipe = $this->compliantOffensiveRecipe();
        $recipe['dry_run_default'] = false;

        $result = $this->service()->evaluateRecipe($recipe);

        $this->assertSame('reject', $result['verdict']);
        $this->assertContains('offensive_requires_dry_run_default', array_column($result['violations'], 'code'));
    }

    /** Extra-approval category (active exploit/C2/distributed scan/external MCP) needs approval. */
    public function test_extra_approval_category_without_approval_is_rejected(): void
    {
        $recipe = $this->compliantOffensiveRecipe();
        $recipe['approval_recorded'] = false;

        $result = $this->service()->evaluateRecipe($recipe);

        $this->assertTrue($result['requires_extra_approval']);
        $this->assertSame('reject', $result['verdict']);
        $this->assertContains('extra_approval_required', array_column($result['violations'], 'code'));
    }

    /** Anti-duplication: re-registering a known defensive tool is rejected (osv-scanner form too). */
    public function test_reregistering_existing_defensive_tool_is_rejected(): void
    {
        $service = $this->service();

        $this->assertTrue($service->isExistingDefensiveTool('osv-scanner'));
        $this->assertTrue($service->isExistingDefensiveTool('osv_scanner'));
        $this->assertTrue($service->isExistingDefensiveTool('semgrep'));
        $this->assertFalse($service->isExistingDefensiveTool('atlas-active-exploit-runner'));

        $recipe = $this->compliantOffensiveRecipe();
        $recipe['tool_slug'] = 'gitleaks';
        $recipe['category'] = 'secret_scan';
        $recipe['offensive'] = false;
        unset($recipe['approval_recorded']);

        $result = $service->evaluateRecipe($recipe);

        $this->assertSame('reject', $result['verdict']);
        $this->assertContains('reregisters_existing_defensive_tool', array_column($result['violations'], 'code'));
    }

    /** Hard Safety Rule: every run must emit Evidence Ledger evidence. */
    public function test_recipe_without_evidence_is_rejected(): void
    {
        $recipe = $this->compliantOffensiveRecipe();
        $recipe['creates_evidence'] = false;

        $result = $this->service()->evaluateRecipe($recipe);

        $this->assertSame('reject', $result['verdict']);
        $this->assertContains('creates_evidence_must_be_true', array_column($result['violations'], 'code'));
    }

    /** A defensive, non-extra-approval recipe on a NEW tool admits without approval evidence. */
    public function test_defensive_new_tool_recipe_admits_without_extra_approval(): void
    {
        $recipe = [
            'tool_slug' => 'atlas-cloud-posture-scan',
            'recipe_name' => 'cspm-baseline',
            'category' => 'cloud',
            'argv' => ['--provider', 'aws'],
            'dry_run_default' => true,
            'creates_evidence' => true,
            'blocking_capable' => true,
            'execution_tier' => 'T1',
            'sandbox' => 'workspace',
            'privacy_level' => 'sensitive',
            'task_type' => 'defensive_scan',
            'authority_group' => 'cyber_defense',
            'offensive' => false,
        ];

        $result = $this->service()->evaluateRecipe($recipe);

        $this->assertSame('admit', $result['verdict']);
        $this->assertFalse($result['requires_extra_approval']);
    }
}
