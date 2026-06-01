<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasLegacyDocumentationCleanupReportService;
use Tests\TestCase;

/**
 * Pins the documented Legacy Documentation Cleanup Report rules: the six
 * Cleanup Classes + active locations, the Canonical Authority ordering and the
 * five Non-Negotiables.
 *
 * @see docs/engineering-knowledge-base/legacy-documentation-cleanup-report.md
 */
class AtlasLegacyDocumentationCleanupReportTest extends TestCase
{
    private function service(): AtlasLegacyDocumentationCleanupReportService
    {
        return new AtlasLegacyDocumentationCleanupReportService();
    }

    /**
     * Cleanup policy 4 + class `human_vault_only`: Obsidian/AtlasVault content
     * is a Human Knowledge Surface and routes to obsidian-atlas-vault.md, even
     * when it would otherwise look like a current authority.
     */
    public function test_vault_content_is_classified_human_vault_only(): void
    {
        $c = $this->service()->classify([
            'kind' => 'source_material',
            'from_vault' => true,
            'is_current_authority' => true,
        ]);

        $this->assertSame(
            AtlasLegacyDocumentationCleanupReportService::CLASS_HUMAN_VAULT_ONLY,
            $c['class'],
        );
        $this->assertSame('obsidian-atlas-vault.md', $c['active_location']);
    }

    /**
     * Non-Negotiable 4: prompts / AGENTS.md / CLAUDE.md / provider bootstrap are
     * never source of truth, so they can never be kept canonical — they
     * quarantine and route to the inventory summary.
     */
    public function test_non_authoritative_source_cannot_be_kept_canonical(): void
    {
        $c = $this->service()->classify([
            'kind' => 'claude_md',
            'is_current_authority' => true,
        ]);

        $this->assertSame(
            AtlasLegacyDocumentationCleanupReportService::CLASS_ARCHIVED_QUARANTINE,
            $c['class'],
        );
        $this->assertTrue($c['non_authoritative_source']);
        $this->assertSame('legacy-cleanup/inventory-summary.md', $c['active_location']);
    }

    /**
     * Cleanup policy 5 ("Use quarantine before delete"): a delete candidate is
     * never deleted by this index — it routes to archived_quarantine.
     */
    public function test_delete_candidate_is_quarantined_not_deleted(): void
    {
        $c = $this->service()->classify([
            'kind' => 'source_material',
            'delete_candidate' => true,
        ]);

        $this->assertSame(
            AtlasLegacyDocumentationCleanupReportService::CLASS_ARCHIVED_QUARANTINE,
            $c['class'],
        );
        $this->assertSame('delete_candidate_quarantined_not_deleted', $c['reason']);
    }

    /**
     * Cleanup Classes table: a historical source that already has a canonical
     * replacement is archive_with_redirect, routed to executed-promotions.md.
     */
    public function test_historical_source_with_replacement_is_archive_with_redirect(): void
    {
        $c = $this->service()->classify([
            'kind' => 'source_material',
            'has_canonical_replacement' => true,
        ]);

        $this->assertSame(
            AtlasLegacyDocumentationCleanupReportService::CLASS_ARCHIVE_WITH_REDIRECT,
            $c['class'],
        );
        $this->assertSame('legacy-cleanup/executed-promotions.md', $c['active_location']);
    }

    /**
     * Canonical Authority: "Legacy cleanup docs never override these files."
     * In a conflict the canonical architecture index wins over a legacy doc.
     */
    public function test_legacy_doc_never_overrides_canonical_authority(): void
    {
        $r = $this->service()->resolveAuthority(
            'legacy_cleanup_report',
            'canonical_architecture_index',
        );

        $this->assertSame('canonical_architecture_index', $r['winner']);
        $this->assertSame('legacy_never_overrides_canonical_authority', $r['reason']);
    }

    /**
     * Canonical Authority ordering: README/START_HERE outrank a deeper
     * authority like master_architecture; two non-authority docs tie with no
     * winner.
     */
    public function test_authority_ordering_and_non_authority_tie(): void
    {
        $service = $this->service();

        $higher = $service->resolveAuthority('readme', 'master_architecture');
        $this->assertSame('readme', $higher['winner']);
        $this->assertSame('higher_canonical_authority_wins', $higher['reason']);

        $tie = $service->resolveAuthority('legacy_cleanup_plan', 'legacy_cleanup_report');
        $this->assertNull($tie['winner']);
        $this->assertSame('both_non_authority_no_override', $tie['reason']);
    }

    /**
     * Non-Negotiable 1: never delete a legacy document in the same wave that
     * first archives it.
     */
    public function test_delete_in_first_archive_wave_violates_non_negotiable(): void
    {
        $r = $this->service()->checkNonNegotiables([
            'archives_now' => true,
            'deletes_now' => true,
            'first_archive_wave' => true,
        ]);

        $this->assertFalse($r['allowed']);
        $this->assertContains('delete_in_same_wave_as_first_archive', $r['violations']);
    }

    /**
     * Non-Negotiables 2, 3 and 5: promoting vault content without privacy
     * review, copying a long legacy file into a canonical doc, and creating a
     * second master architecture are each independent violations; a clean
     * action passes.
     */
    public function test_remaining_non_negotiables_and_clean_action(): void
    {
        $service = $this->service();

        $r = $service->checkNonNegotiables([
            'promotes_vault_content' => true,
            'privacy_reviewed' => false,
            'copies_long_legacy_file' => true,
            'creates_master_architecture' => true,
            'master_architecture_exists' => true,
        ]);
        $this->assertFalse($r['allowed']);
        $this->assertContains('vault_content_promoted_without_privacy_review', $r['violations']);
        $this->assertContains('long_legacy_file_copied_into_canonical_doc', $r['violations']);
        $this->assertContains('second_master_architecture_created', $r['violations']);

        // A first master architecture (none exists yet) is NOT a violation.
        $firstMaster = $service->checkNonNegotiables([
            'creates_master_architecture' => true,
            'master_architecture_exists' => false,
        ]);
        $this->assertTrue($firstMaster['allowed']);

        $this->assertTrue($service->isActionAllowed([
            'archives_now' => true,
            'deletes_now' => false,
            'promotes_vault_content' => true,
            'privacy_reviewed' => true,
        ]));
    }
}
