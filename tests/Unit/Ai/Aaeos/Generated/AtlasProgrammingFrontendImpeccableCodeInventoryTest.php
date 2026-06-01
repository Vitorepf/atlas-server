<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendImpeccableCodeInventoryService;
use Tests\TestCase;

/**
 * Pins the load-bearing rules from the doc: the audited file split (1691 total,
 * 670 authorial after excluding generated provider bundles), the generated-dir
 * classifier (generated bundles never count toward capacity), the primary-area
 * audit gate, and the pinned-commit re-audit window. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-code-inventory.md
 */
class AtlasProgrammingFrontendImpeccableCodeInventoryTest extends TestCase
{
    private function service(): AtlasProgrammingFrontendImpeccableCodeInventoryService
    {
        return new AtlasProgrammingFrontendImpeccableCodeInventoryService;
    }

    public function test_inventory_carries_the_verified_contratos_table(): void
    {
        // Doc "Contratos": 11 audited areas; tests is the largest at 320,
        // .codex the smallest at 1.
        $inv = $this->service()->inventory();

        $this->assertSame(1691, $inv['total_files']);
        $this->assertSame(670, $inv['authorial_files']);
        $this->assertCount(11, $inv['inventory']);
        $this->assertSame(11, $inv['area_count']);

        $byArea = [];
        foreach ($inv['inventory'] as $row) {
            $byArea[$row['area']] = $row['files'];
        }
        $this->assertSame(320, $byArea['tests']);
        $this->assertSame(197, $byArea['site']);
        $this->assertSame(62, $byArea['skill']);
        $this->assertSame(1, $byArea['.codex']);
    }

    public function test_authorial_reconciliation_is_total_minus_generated(): void
    {
        // Doc "Resumo" + decision[0]: 1691 total - 1021 generated = 670 authorial.
        $r = $this->service()->reconcileAuthorialFiles();

        $this->assertSame(1691, $r['total_files']);
        $this->assertSame(1021, $r['generated_files']);
        $this->assertSame(670, $r['authorial_files']);
        $this->assertTrue($r['matches_documented_split']);
        // 13 generated provider bundle directories are excluded.
        $this->assertSame(13, $r['generated_dir_count']);

        // A recount that disagrees with the documented split flips the verdict.
        $bad = $this->service()->reconcileAuthorialFiles(1691, 900); // -> 791 authorial
        $this->assertFalse($bad['matches_documented_split']);
        $this->assertSame(791, $bad['authorial_files']);
        $this->assertSame('re_audit_recount_does_not_match_documented_670_of_1691', $bad['conclusion']);
    }

    public function test_generated_provider_bundles_never_count_toward_capacity(): void
    {
        // Doc "Regras para IA" rule 2 + forbidden_changes + Riscos: generated
        // provider dirs are excluded; .trae and .trae-cn are distinct segments.
        $svc = $this->service();

        $claude = $svc->classifyDirectory('.claude');
        $this->assertTrue($claude['is_generated']);
        $this->assertFalse($claude['is_authorial']);
        $this->assertFalse($claude['counts_toward_capacity']);
        $this->assertSame('generated_provider_bundle', $claude['classification']);

        $traeCn = $svc->classifyDirectory('.trae-cn');
        $this->assertTrue($traeCn['is_generated']);

        // `plugin` is generated, but `.claude-plugin` is an authorial area.
        $this->assertTrue($svc->classifyDirectory('plugin')['is_generated']);
        $this->assertFalse($svc->classifyDirectory('.claude-plugin')['is_generated']);
        $this->assertTrue($svc->classifyDirectory('.claude-plugin')['counts_toward_capacity']);
    }

    public function test_primary_authorial_areas_are_recognized_and_audited_first(): void
    {
        // Doc "Regras para IA" rule 3 + "Papel no Atlas": skill/cli/extension/
        // scripts/tests are primary authorial source, audited before bundles.
        $svc = $this->service();

        $tests = $svc->classifyDirectory('tests');
        $this->assertSame('primary_authorial_area', $tests['classification']);
        $this->assertTrue($tests['is_primary_area']);

        // A nested primary subsystem is recognised by its leading segment.
        $this->assertTrue($svc->classifyDirectory('cli/engine/rules/checks.mjs')['is_primary_area']);

        // The gate: nothing audited -> all primary areas missing.
        $empty = $svc->evaluatePrimaryCoverage();
        $this->assertFalse($empty['all_primary_audited']);
        $this->assertCount(7, $empty['missing']);
        $this->assertSame('primary_audit_incomplete_audit_source_before_bundles', $empty['status']);

        // All audited -> gate passes.
        $full = $svc->evaluatePrimaryCoverage($empty['primary_areas']);
        $this->assertTrue($full['all_primary_audited']);
        $this->assertSame([], $full['missing']);
    }

    public function test_audit_window_is_valid_only_for_the_pinned_commit(): void
    {
        // Doc "maintenance"/"next_actions" + Riscos: a different commit invalidates
        // the inventory and forces a recount.
        $svc = $this->service();

        $ok = $svc->validateAuditWindow('84135db0e6bdd58d22828f7bc8331cae7bde3e7f');
        $this->assertTrue($ok['audit_window_valid']);
        $this->assertSame('audit_window_valid_inventory_counts_trustable', $ok['action']);

        $stale = $svc->validateAuditWindow('deadbeefdeadbeefdeadbeefdeadbeefdeadbeef');
        $this->assertFalse($stale['audit_window_valid']);
        $this->assertSame('re_audit_required_recount_inventory_for_new_commit', $stale['action']);
    }
}
