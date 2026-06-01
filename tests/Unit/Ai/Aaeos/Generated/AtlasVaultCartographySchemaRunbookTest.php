<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasVaultCartographySchemaRunbookService;
use Tests\TestCase;

/**
 * Pins the documented Atlas Vault Cartography Schema Runbook rules.
 *
 * @see docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-runbook.md
 */
class AtlasVaultCartographySchemaRunbookTest extends TestCase
{
    private function service(): AtlasVaultCartographySchemaRunbookService
    {
        return new AtlasVaultCartographySchemaRunbookService();
    }

    /**
     * Reader Model: only the vault reader dedupes its index against the repo,
     * walks the AtlasVault path and opens via obsidian; the repo reader walks the
     * engineering-knowledge-base and opens via the code editor / OS handler.
     */
    public function test_reader_model_binds_repo_and_vault_with_vault_dedupe(): void
    {
        $svc = $this->service();

        $repo = $svc->resolveReaderBinding(['source' => 'repo']);
        $this->assertSame(AtlasVaultCartographySchemaRunbookService::BINDING_RESOLVED, $repo['verdict']);
        $this->assertFalse($repo['deduped_against_repo']);
        $this->assertSame('docs/engineering-knowledge-base/', $repo['binding']['walk']);
        $this->assertSame('code_editor_or_os_handler', $repo['binding']['open']);

        $vault = $svc->resolveReaderBinding(['source' => 'VAULT']); // case-insensitive
        $this->assertSame(AtlasVaultCartographySchemaRunbookService::BINDING_RESOLVED, $vault['verdict']);
        $this->assertTrue($vault['deduped_against_repo']);
        $this->assertSame('~/AtlasVault/', $vault['binding']['walk']);
        $this->assertSame('obsidian_open', $vault['binding']['open']);
        $this->assertSame('keyed_by_graph_id_deduped_against_repo', $vault['binding']['index']);
    }

    /**
     * Fail-safe: an unknown / missing source never binds a default reader — it is
     * blocked with no binding, so cartography cannot read/open through the wrong
     * handler.
     */
    public function test_unknown_source_is_blocked_not_defaulted(): void
    {
        $svc = $this->service();

        $bad = $svc->resolveReaderBinding(['source' => 'sharepoint']);
        $this->assertSame(AtlasVaultCartographySchemaRunbookService::BINDING_BLOCKED, $bad['verdict']);
        $this->assertNull($bad['binding']);
        $this->assertContains('unknown_source_cannot_bind_reader', $bad['reasons']);

        $none = $svc->resolveReaderBinding([]);
        $this->assertSame(AtlasVaultCartographySchemaRunbookService::BINDING_BLOCKED, $none['verdict']);
        $this->assertNull($none['source']);
    }

    /**
     * Missing Source: "Never render a missing file as healthy truth." A missing
     * source renders missing_source, disables the lying open action, surfaces the
     * expected path, shows cached content when available and requires drift
     * evidence + Inbox repair.
     */
    public function test_missing_source_disables_open_and_requires_drift_evidence(): void
    {
        $svc = $this->service();

        $missing = $svc->resolveSource([
            'exists' => false,
            'expected_path' => 'docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema.md',
            'cached_content' => '# cached body',
        ]);

        $this->assertSame(AtlasVaultCartographySchemaRunbookService::RESOLUTION_MISSING_SOURCE, $missing['resolution']);
        $this->assertTrue($missing['render_missing_source']);
        $this->assertFalse($missing['open_source_enabled']);
        $this->assertTrue($missing['show_cached_content']);
        $this->assertTrue($missing['emit_drift_evidence']);
        $this->assertTrue($missing['allow_inbox_repair']);
        $this->assertSame('docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema.md', $missing['expected_path']);
    }

    /**
     * A present-but-stale source is ALSO a drift state (open disabled, drift
     * evidence required); only a present AND fresh source resolves healthy with an
     * enabled open action and no cached fallback / drift.
     */
    public function test_stale_is_drift_but_present_and_fresh_is_healthy(): void
    {
        $svc = $this->service();

        $stale = $svc->resolveSource(['exists' => true, 'stale' => true, 'expected_path' => 'x.md']);
        $this->assertSame(AtlasVaultCartographySchemaRunbookService::RESOLUTION_MISSING_SOURCE, $stale['resolution']);
        $this->assertFalse($stale['open_source_enabled']);
        $this->assertTrue($stale['emit_drift_evidence']);

        $healthy = $svc->resolveSource(['exists' => true, 'stale' => false, 'expected_path' => 'y.md']);
        $this->assertSame(AtlasVaultCartographySchemaRunbookService::RESOLUTION_HEALTHY, $healthy['resolution']);
        $this->assertTrue($healthy['open_source_enabled']);
        $this->assertFalse($healthy['render_missing_source']);
        $this->assertFalse($healthy['emit_drift_evidence']);
        $this->assertFalse($healthy['show_cached_content']);
    }

    /**
     * Migration Phases: "Phase 6: Deprecate Hardcoded Data" may run ONLY after the
     * graph API is the source of data — Phase 2 (backend readers) AND Phase 3
     * (frontend JSON consumption) done. Phase 2 alone is not enough.
     */
    public function test_deprecate_hardcoded_blocked_until_phase2_and_phase3_done(): void
    {
        $svc = $this->service();

        $onlyPhase2 = $svc->canDeprecateHardcoded([0, 1, 2]);
        $this->assertSame(AtlasVaultCartographySchemaRunbookService::PHASE_BLOCKED, $onlyPhase2['verdict']);
        $this->assertFalse($onlyPhase2['allowed']);
        $this->assertSame([3], $onlyPhase2['missing_phases']);

        $bothDone = $svc->canDeprecateHardcoded([0, 1, 2, 3]);
        $this->assertSame(AtlasVaultCartographySchemaRunbookService::PHASE_ALLOWED, $bothDone['verdict']);
        $this->assertTrue($bothDone['allowed']);
        $this->assertSame([], $bothDone['missing_phases']);

        $none = $svc->canDeprecateHardcoded([]);
        $this->assertFalse($none['allowed']);
        $this->assertSame([2, 3], $none['missing_phases']);
    }

    /**
     * Migration ordering: phases are strictly 0..6; nextPhase returns the lowest
     * not-yet-done index and reports completion when all seven are done.
     */
    public function test_next_phase_follows_documented_order(): void
    {
        $svc = $this->service();

        $this->assertCount(7, AtlasVaultCartographySchemaRunbookService::MIGRATION_PHASES);

        $next = $svc->nextPhase([0, 1]);
        $this->assertSame(2, $next['next_phase']);
        $this->assertSame('backend_readers', $next['next_phase_name']);
        $this->assertFalse($next['complete']);

        $all = $svc->nextPhase([0, 1, 2, 3, 4, 5, 6]);
        $this->assertNull($all['next_phase']);
        $this->assertTrue($all['complete']);
    }

    /**
     * Live Documentation Flow: 7 ordered steps; step 1 advances to step 2, the
     * terminal step 7 has no successor, and the AI is never source truth.
     */
    public function test_live_doc_flow_is_ordered_and_ai_is_never_source_truth(): void
    {
        $svc = $this->service();

        $step1 = $svc->liveDocFlowStep(1);
        $this->assertSame('ai_edits_canonical_repo_doc', $step1['current_step_name']);
        $this->assertSame(2, $step1['next_step']);
        $this->assertSame('watcher_detects_filesystem_change', $step1['next_step_name']);
        $this->assertFalse($step1['terminal']);
        $this->assertFalse($step1['ai_is_source_truth']);

        $step7 = $svc->liveDocFlowStep(7);
        $this->assertTrue($step7['terminal']);
        $this->assertNull($step7['next_step']);
        $this->assertSame('gap_becomes_revision_request_or_proposal_inbox_item', $step7['current_step_name']);
    }
}
