<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasLegacyDocumentationCleanupPlanService;
use Tests\TestCase;

/**
 * Pins the documented Legacy Documentation Cleanup Plan decision rules.
 *
 * @see docs/engineering-knowledge-base/legacy-documentation-cleanup-plan.md
 */
class AtlasLegacyDocumentationCleanupPlanTest extends TestCase
{
    private function service(): AtlasLegacyDocumentationCleanupPlanService
    {
        return new AtlasLegacyDocumentationCleanupPlanService();
    }

    /** Full promotion facts present and clean => promote is allowed. */
    public function test_promotion_allowed_when_full_definition_of_done_is_met(): void
    {
        $d = $this->service()->decideAction([
            'intent' => 'promote',
            'kind' => 'source_material',
            'from_vault' => false,
            'promotion_dod' => [
                'has_frontmatter' => true,
                'declares_authority_and_related_paths' => true,
                'synthesizes_decisions' => true,
                'preserves_source_link' => true,
                'within_line_limits' => true,
                'passes_docs_health' => true,
            ],
        ]);

        $this->assertTrue($d['allowed']);
        $this->assertSame(AtlasLegacyDocumentationCleanupPlanService::ACTION_PROMOTE, $d['action']);
        $this->assertSame([], $d['blockers']);
    }

    /**
     * Safety Rule 4 / Invariant "Privacy before promotion": vault content
     * without a privacy review cannot promote and falls back to quarantine.
     */
    public function test_vault_content_without_privacy_review_cannot_promote(): void
    {
        $d = $this->service()->decideAction([
            'intent' => 'promote',
            'kind' => 'source_material',
            'from_vault' => true,
            'privacy_reviewed' => false,
            'promotion_dod' => [
                'has_frontmatter' => true,
                'declares_authority_and_related_paths' => true,
                'synthesizes_decisions' => true,
                'preserves_source_link' => true,
                'within_line_limits' => true,
                'passes_docs_health' => true,
            ],
        ]);

        $this->assertFalse($d['allowed']);
        $this->assertContains('vault_content_promoted_without_privacy_review', $d['blockers']);
        // Preserve-first: a blocked promotion degrades to quarantine, not loss.
        $this->assertSame(AtlasLegacyDocumentationCleanupPlanService::ACTION_QUARANTINE, $d['action']);
    }

    /**
     * Safety Rule 5: prompts / provider bootstrap / task plans are never source
     * of truth, so they can never be promoted.
     */
    public function test_non_authoritative_material_cannot_be_promoted(): void
    {
        $d = $this->service()->decideAction([
            'intent' => 'promote',
            'kind' => 'task_plan',
            'promotion_dod' => [
                'has_frontmatter' => true,
                'declares_authority_and_related_paths' => true,
                'synthesizes_decisions' => true,
                'preserves_source_link' => true,
                'within_line_limits' => true,
                'passes_docs_health' => true,
            ],
        ]);

        $this->assertFalse($d['allowed']);
        $this->assertContains('non_authoritative_material_cannot_be_promoted', $d['blockers']);
    }

    /**
     * Safety Rule 2 / Invariant "Redirect before delete": a redirect/archive
     * move without a redirect or archived source path is blocked.
     */
    public function test_move_without_redirect_or_archived_source_is_blocked(): void
    {
        $d = $this->service()->decideAction([
            'intent' => 'redirect',
            'has_redirect_or_archived_source' => false,
        ]);

        $this->assertFalse($d['allowed']);
        $this->assertContains('move_without_redirect_or_archived_source', $d['blockers']);
    }

    /**
     * Safety Rule 1: deletion is forbidden in a first wave even if every other
     * Delete Definition Of Done gate is satisfied.
     */
    public function test_delete_forbidden_in_first_wave_even_with_all_gates_true(): void
    {
        $d = $this->service()->evaluateDelete([
            'first_wave' => true,
            'delete_dod' => [
                'had_redirect_or_archive_for_a_release' => true,
                'no_live_references' => true,
                'git_log_follow_reviewed' => true,
                'valuable_material_resolved' => true,
                'vitor_approved_delete' => true,
            ],
        ]);

        $this->assertFalse($d['allowed']);
        $this->assertContains('delete_forbidden_in_first_wave', $d['blockers']);
        // All five DoD gates are green, so the only failure is the first-wave rule.
        $this->assertSame(0, $d['gates_failed']);
    }

    /**
     * Delete Definition Of Done: deletion is blocked unless ALL five gates are
     * true; one missing gate (no Vitor approval) still blocks even outside a
     * first wave.
     */
    public function test_delete_blocked_until_all_five_gates_pass(): void
    {
        $service = $this->service();

        $missingApproval = $service->evaluateDelete([
            'first_wave' => false,
            'delete_dod' => [
                'had_redirect_or_archive_for_a_release' => true,
                'no_live_references' => true,
                'git_log_follow_reviewed' => true,
                'valuable_material_resolved' => true,
                'vitor_approved_delete' => false,
            ],
        ]);
        $this->assertFalse($missingApproval['allowed']);
        $this->assertSame(1, $missingApproval['gates_failed']);
        $this->assertContains('vitor_did_not_explicitly_approve_delete', $missingApproval['blockers']);

        $allGreen = $service->evaluateDelete([
            'first_wave' => false,
            'delete_dod' => [
                'had_redirect_or_archive_for_a_release' => true,
                'no_live_references' => true,
                'git_log_follow_reviewed' => true,
                'valuable_material_resolved' => true,
                'vitor_approved_delete' => true,
            ],
        ]);
        $this->assertTrue($allGreen['allowed']);
        $this->assertTrue($service->canDelete([
            'first_wave' => false,
            'delete_dod' => [
                'had_redirect_or_archive_for_a_release' => true,
                'no_live_references' => true,
                'git_log_follow_reviewed' => true,
                'valuable_material_resolved' => true,
                'vitor_approved_delete' => true,
            ],
        ]));
    }

    /**
     * Required Preflight: a wave that does not know its canonical doc / source /
     * work type / validation is not ready to run.
     */
    public function test_wave_preflight_requires_canonical_doc_source_and_validation(): void
    {
        $notReady = $this->service()->preflightWave([
            'canonical_doc_known' => false,
            'legacy_source_known' => true,
            'work_type' => 'merge',
            'validation_planned' => true,
        ]);
        $this->assertFalse($notReady['ready']);
        $this->assertContains('canonical_doc_unknown', $notReady['missing']);

        $ready = $this->service()->preflightWave([
            'canonical_doc_known' => true,
            'legacy_source_known' => true,
            'work_type' => 'merge',
            'validation_planned' => true,
        ]);
        $this->assertTrue($ready['ready']);
        $this->assertSame([], $ready['missing']);
    }
}
