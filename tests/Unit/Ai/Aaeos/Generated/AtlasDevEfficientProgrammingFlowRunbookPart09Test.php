<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowRunbookPart09Service;
use Tests\TestCase;

/**
 * Pins the operator decision rules from Atlas Dev Efficient Programming Flow
 * Runbook v1 · Parte 9 (15.1.10 release checklist, 15.1.12 project profiles,
 * 15.1.15 visual QA). Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-09.md
 */
class AtlasDevEfficientProgrammingFlowRunbookPart09Test extends TestCase
{
    private function service(): AtlasDevEfficientProgrammingFlowRunbookPart09Service
    {
        return new AtlasDevEfficientProgrammingFlowRunbookPart09Service;
    }

    /**
     * Doc 15.1.10: a release is releasable only when every mandatory item is
     * confirmed; a missing mandatory item names itself and blocks the release.
     */
    public function test_release_checklist_requires_all_mandatory_items(): void
    {
        $service = $this->service();

        $allConfirmed = [
            'app_key_base64_min_32_bytes' => true,
            'migrations_applied' => true,
            'readiness_passed' => true,
            'smoke_no_absolute_path_leak' => true,
            'plan_enabled_validated_before_run_enabled' => true,
            'run_enabled' => true,
        ];
        $ok = $service->evaluateReleaseChecklist($allConfirmed);
        $this->assertTrue($ok['releasable']);
        $this->assertSame([], $ok['unmet_mandatory']);
        $this->assertNull($ok['reason']);

        // Drop readiness -> not releasable, the unmet item is named explicitly.
        $missingReadiness = $allConfirmed;
        $missingReadiness['readiness_passed'] = false;
        $blocked = $service->evaluateReleaseChecklist($missingReadiness);
        $this->assertFalse($blocked['releasable']);
        $this->assertContains('readiness_passed', $blocked['unmet_mandatory']);
        $this->assertSame('mandatory_checklist_items_unmet', $blocked['reason']);

        // An absolute-path leak (smoke item false) also blocks.
        $leaky = $allConfirmed;
        $leaky['smoke_no_absolute_path_leak'] = false;
        $this->assertFalse($service->evaluateReleaseChecklist($leaky)['releasable']);
    }

    /**
     * Doc 15.1.10: plan_enabled must be validated BEFORE run_enabled. Setting
     * run_enabled without that validation is a sequencing violation.
     */
    public function test_release_checklist_blocks_run_enabled_before_plan_validated(): void
    {
        $service = $this->service();

        $result = $service->evaluateReleaseChecklist([
            'app_key_base64_min_32_bytes' => true,
            'migrations_applied' => true,
            'readiness_passed' => true,
            'smoke_no_absolute_path_leak' => true,
            'plan_enabled_validated_before_run_enabled' => false,
            'run_enabled' => true,
        ]);

        $this->assertTrue($result['sequencing_violation']);
        $this->assertFalse($result['releasable']);
        $this->assertSame('run_enabled_set_before_plan_enabled_validated', $result['reason']);
    }

    /**
     * Doc 15.1.12: a Desktop slug whose workspace_path does not exist on host
     * returns 422 BEFORE minting a token — no side effect, no receipt.
     */
    public function test_desktop_slug_with_inexistent_workspace_returns_422_no_token(): void
    {
        $service = $this->service();

        $profiles = [
            'atlas-server' => ['workspace_path' => '/Users/op/code/atlas-server', 'workspace_exists' => true],
            'ghost' => ['workspace_path' => '/Users/op/code/ghost', 'workspace_exists' => false],
        ];

        // Happy path: existing workspace resolves and is allowed to mint.
        $ok = $service->resolveProjectWorkspace(
            ['surface_id' => 'atlas_desktop_ai', 'project_slug' => 'atlas-server'],
            $profiles,
        );
        $this->assertTrue($ok['resolved']);
        $this->assertSame('/Users/op/code/atlas-server', $ok['workspace_path']);
        $this->assertTrue($ok['mints_token']);
        $this->assertSame(200, $ok['http_status']);

        // Inexistent workspace_path -> 422, no token, no side effect.
        $dead = $service->resolveProjectWorkspace(
            ['surface_id' => 'atlas_desktop_ai', 'project_slug' => 'ghost'],
            $profiles,
        );
        $this->assertFalse($dead['resolved']);
        $this->assertSame(422, $dead['http_status']);
        $this->assertFalse($dead['mints_token']);
        $this->assertFalse($dead['side_effect']);
        $this->assertSame('workspace_path_inexistent', $dead['error']);

        // Unknown slug also 422.
        $unknown = $service->resolveProjectWorkspace(
            ['surface_id' => 'atlas_desktop_ai', 'project_slug' => 'nope'],
            $profiles,
        );
        $this->assertSame(422, $unknown['http_status']);
        $this->assertSame('unknown_project_slug', $unknown['error']);
    }

    /**
     * Doc 15.1.12: omitting the slug on Desktop falls back to
     * ATLAS_CODE_DEFAULT_PROJECT; non-Desktop surfaces may pass an absolute
     * path directly and bypass slug resolution.
     */
    public function test_default_project_fallback_and_non_desktop_absolute_path(): void
    {
        $service = $this->service();
        $profiles = ['atlas-server' => ['workspace_path' => '/Users/op/code/atlas-server', 'workspace_exists' => true]];

        // Desktop with no slug -> default fallback resolves it.
        $fallback = $service->resolveProjectWorkspace(
            ['surface_id' => 'atlas_desktop_ai'],
            $profiles,
            'atlas-server',
        );
        $this->assertTrue($fallback['resolved']);
        $this->assertTrue($fallback['used_default_fallback']);
        $this->assertSame('atlas-server', $fallback['used_slug']);

        // Non-Desktop surface with an absolute workspace path bypasses slugs.
        $cli = $service->resolveProjectWorkspace(
            ['surface_id' => 'atlas_cli_dev', 'workspace' => '/abs/path/repo'],
            [],
        );
        $this->assertTrue($cli['resolved']);
        $this->assertSame('/abs/path/repo', $cli['workspace_path']);

        // Non-Desktop surface with a relative path is rejected (422).
        $rel = $service->resolveProjectWorkspace(
            ['surface_id' => 'atlas_cli_dev', 'workspace' => './repo'],
            [],
        );
        $this->assertSame(422, $rel['http_status']);
        $this->assertFalse($rel['resolved']);
    }

    /**
     * Doc 15.1.15: with no Browser/Playwright the profile is generic_no_test and
     * a `passed` proposal is NEVER honored silently — it downgrades to
     * needs_review. Frontend visual-dependent changes escalate to >= R3 and
     * require human review.
     */
    public function test_visual_qa_never_silently_passes_and_escalates_frontend(): void
    {
        $service = $this->service();

        // Frontend + visual required + no browser tool + proposed "passed".
        $verdict = $service->evaluateVisualQa([
            'is_frontend' => true,
            'visual_qa_required' => true,
            'browser_tooling_available' => false,
            'proposed_verdict' => 'passed',
            'declared_risk_level' => 'R1',
        ]);
        $this->assertSame('generic_no_test', $verdict['verification_profile']);
        $this->assertNotSame('passed', $verdict['verdict']);
        $this->assertSame('needs_review', $verdict['verdict']);
        $this->assertTrue($verdict['silent_pass_blocked']);
        $this->assertTrue($verdict['requires_human_review']);
        // Escalated from R1 up to the R3 floor.
        $this->assertSame('R3', $verdict['effective_risk_level']);

        // A higher declared risk is preserved, not lowered to R3.
        $high = $service->evaluateVisualQa([
            'is_frontend' => true,
            'visual_qa_required' => true,
            'browser_tooling_available' => false,
            'proposed_verdict' => 'no_patch_needed',
            'declared_risk_level' => 'R5',
        ]);
        $this->assertSame('R5', $high['effective_risk_level']);

        // No-patch-needed is a legal verdict when no human review is forced
        // (non-frontend, tooling absent).
        $clean = $service->evaluateVisualQa([
            'is_frontend' => false,
            'visual_qa_required' => false,
            'browser_tooling_available' => false,
            'proposed_verdict' => 'no_patch_needed',
            'declared_risk_level' => 'R0',
        ]);
        $this->assertSame('no_patch_needed', $clean['verdict']);
        $this->assertFalse($clean['requires_human_review']);
        // 'passed' is the forbidden silent verdict — never in the allowed set.
        $this->assertNotContains('passed', $clean['allowed_verdicts']);
    }

    /**
     * The manifest pins the doc's enumerations verbatim so drift is caught.
     */
    public function test_manifest_pins_doc_enumerations(): void
    {
        $manifest = $this->service()->manifest();

        $this->assertSame('atlas_desktop_ai', $manifest['desktop_surface_id']);
        $this->assertSame(422, $manifest['slug_unresolved_http_status']);
        $this->assertSame('generic_no_test', $manifest['visual_profile_generic_no_test']);
        $this->assertSame('passed', $manifest['visual_forbidden_silent_verdict']);
        $this->assertSame('R3', $manifest['frontend_visual_risk_floor']);
        $this->assertSame(
            ['no_patch_needed', 'needs_review'],
            $manifest['visual_allowed_verdicts'],
        );
        $this->assertSame(
            ['app_key_base64_min_32_bytes', 'migrations_applied', 'readiness_passed', 'smoke_no_absolute_path_leak'],
            $manifest['mandatory_checklist_items'],
        );
    }
}
