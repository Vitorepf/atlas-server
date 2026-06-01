<?php

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime for the Impeccable Teardown Coverage Audit doc.
 *
 * The doc is a COVERAGE MATRIX proving which areas of the external teardown
 * subject were dissected and where each is documented in Atlas. Its load-bearing
 * rules (frontmatter `decisions`, "Papel no Atlas", "Regras para IA",
 * `forbidden_changes`):
 *
 *   1. The audit conclusion is valid (teardown "complete") ONLY when EVERY
 *      important external area has an owner doc in Atlas with status "covered".
 *      A single uncovered area means the teardown CANNOT be called complete.
 *   2. "covered" means DOCUMENTED in Atlas, never IMPLEMENTED in Atlas. This
 *      matrix must NEVER be used to declare Atlas runtime ready (forbidden
 *      change). Any request to derive runtime-readiness from coverage is refused.
 *   3. Re-validate the pinned external commit/counts when the external repo
 *      changes; a different commit invalidates the audit window.
 *
 * This service enforces those rules deterministically, in pure memory, with no
 * DB. It carries the canonical area->owner-doc matrix from the doc's Contratos
 * table and the documented Fluxo, and exposes the completeness gate plus an
 * explicit runtime-readiness refusal.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-coverage-audit.md
 */
final class AtlasProgrammingFrontendCoverageAuditService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.programming_frontend_coverage_audit.v1';

    public const MODE = 'documental_coverage_matrix_read_only';

    /** The only status the doc treats as satisfying coverage. */
    public const STATUS_COVERED = 'covered';

    /** The external teardown subject's pinned audit window (from "Resumo"/"Evidencias"). */
    public const EXTERNAL_SUBJECT = 'pbakaus/impeccable';

    public const PINNED_COMMIT = '84135db0e6bdd58d22828f7bc8331cae7bde3e7f';

    public const TOTAL_FILES = 1691;

    public const SOURCE_FILES = 670;

    /** docs-health is the doc's `required_tests` / `quality_gates` signal. */
    public const REQUIRED_QUALITY_GATE = 'php artisan atlas:engineering:knowledge docs-health --json';

    /**
     * The canonical coverage matrix, copied verbatim from the doc's "Contratos"
     * table. Every important external area maps to the Atlas owner doc(s) that
     * dissect it, with the documented status. The doc lists all rows as
     * "covered"; this service still verifies that invariant rather than assuming.
     *
     * @var array<int, array{area:string, atlas_coverage:string, status:string}>
     */
    private const MATRIX = [
        ['area' => 'repo/file inventory', 'atlas_coverage' => 'programming-frontend-impeccable-code-inventory.md', 'status' => 'covered'],
        ['area' => 'skill source', 'atlas_coverage' => 'programming-frontend-impeccable-skill-command-flow.md', 'status' => 'covered'],
        ['area' => 'references', 'atlas_coverage' => 'skill command flow + competitive teardown', 'status' => 'covered'],
        ['area' => 'context docs PRODUCT/DESIGN', 'atlas_coverage' => 'skill command flow + competitive teardown', 'status' => 'covered'],
        ['area' => 'command metadata', 'atlas_coverage' => 'skill command flow', 'status' => 'covered'],
        ['area' => 'asset producer subagent', 'atlas_coverage' => 'code inventory + skill command flow', 'status' => 'covered'],
        ['area' => 'detector CLI/rules/engines', 'atlas_coverage' => 'detector extension doc', 'status' => 'covered'],
        ['area' => 'browser injected detector', 'atlas_coverage' => 'detector extension doc', 'status' => 'covered'],
        ['area' => 'Chrome extension', 'atlas_coverage' => 'detector extension doc', 'status' => 'covered'],
        ['area' => 'Live Mode scripts', 'atlas_coverage' => 'live mode doc', 'status' => 'covered'],
        ['area' => 'notes/adr-live-variant-mode.md', 'atlas_coverage' => 'live mode doc', 'status' => 'covered'],
        ['area' => 'build/provider transforms', 'atlas_coverage' => 'build test release doc', 'status' => 'covered'],
        ['area' => 'HARNESSES/provider matrix', 'atlas_coverage' => 'build test release doc', 'status' => 'covered'],
        ['area' => 'tests and fixtures', 'atlas_coverage' => 'build test release + subsystem docs', 'status' => 'covered'],
        ['area' => 'site/docs/tutorials/demos/assets', 'atlas_coverage' => 'product site assets doc', 'status' => 'covered'],
        ['area' => 'download functions', 'atlas_coverage' => 'product site assets + build test release', 'status' => 'covered'],
        ['area' => 'release flow', 'atlas_coverage' => 'build test release doc', 'status' => 'covered'],
        ['area' => 'design competitive blueprint', 'atlas_coverage' => 'competitive teardown', 'status' => 'covered'],
    ];

    /**
     * The documented "Fluxo" — the ordered teardown audit pipeline. The coverage
     * matrix is the LAST step; it can only be produced after the prior steps.
     *
     * @var array<int, string>
     */
    private const FLOW = [
        'clone external repo',
        'separate generated provider bundles from source',
        'inventory source roots',
        'inspect core files and tests',
        'document by subsystem',
        'docs-health and diff-check',
        'coverage matrix',
    ];

    /**
     * Return the canonical coverage matrix plus the audit window metadata.
     *
     * @return array{
     *   schema_version:string,
     *   mode:string,
     *   external_subject:string,
     *   pinned_commit:string,
     *   total_files:int,
     *   source_files:int,
     *   area_count:int,
     *   covered_count:int,
     *   matrix:array<int, array{area:string, atlas_coverage:string, status:string}>
     * }
     */
    public function matrix(): array
    {
        $covered = array_filter(self::MATRIX, static fn (array $row): bool => $row['status'] === self::STATUS_COVERED);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'external_subject' => self::EXTERNAL_SUBJECT,
            'pinned_commit' => self::PINNED_COMMIT,
            'total_files' => self::TOTAL_FILES,
            'source_files' => self::SOURCE_FILES,
            'area_count' => count(self::MATRIX),
            'covered_count' => count($covered),
            'matrix' => array_values(self::MATRIX),
        ];
    }

    /**
     * The documented audit flow, in order. The coverage matrix is the terminal
     * step and is never the first.
     *
     * @return array<int, string>
     */
    public function flow(): array
    {
        return self::FLOW;
    }

    /**
     * Core decision (doc `decisions[0]` + Rule 1): the teardown is "complete"
     * ONLY when every area in `$importantAreas` is present in the matrix AND its
     * status is "covered". With no argument it evaluates the canonical matrix
     * against its own area set (which the doc declares fully covered).
     *
     * Passing an extra important area that is NOT in the matrix flips the audit
     * to incomplete — exactly the doc's "se uma area importante nao aparece aqui,
     * a disseccao nao pode ser chamada completa".
     *
     * @param  array<int, string>|null  $importantAreas
     * @return array{
     *   complete:bool,
     *   total_important:int,
     *   covered:array<int, string>,
     *   missing_areas:array<int, string>,
     *   uncovered_status_areas:array<int, string>,
     *   conclusion:string,
     *   asserts_runtime_ready:bool
     * }
     */
    public function evaluateCompleteness(?array $importantAreas = null): array
    {
        $byArea = [];
        foreach (self::MATRIX as $row) {
            $byArea[$row['area']] = $row['status'];
        }

        $important = $importantAreas ?? array_keys($byArea);

        $covered = [];
        $missing = [];
        $uncoveredStatus = [];

        foreach ($important as $area) {
            if (! array_key_exists($area, $byArea)) {
                $missing[] = $area;

                continue;
            }

            if ($byArea[$area] === self::STATUS_COVERED) {
                $covered[] = $area;
            } else {
                $uncoveredStatus[] = $area;
            }
        }

        $complete = $missing === [] && $uncoveredStatus === [];

        return [
            'complete' => $complete,
            'total_important' => count($important),
            'covered' => $covered,
            'missing_areas' => array_values($missing),
            'uncovered_status_areas' => array_values($uncoveredStatus),
            'conclusion' => $complete
                ? 'teardown_coverage_complete_documented'
                : 'teardown_coverage_incomplete_cannot_claim_complete',
            // Rule 2/4 + forbidden_changes: coverage is documental, never runtime.
            'asserts_runtime_ready' => false,
        ];
    }

    /**
     * Explicit refusal gate for the forbidden change (`forbidden_changes`:
     * "Usar esta matriz para declarar Atlas runtime implementado"; Rules 2 & 4).
     * "covered" == documented in Atlas, NOT implemented. This always refuses to
     * translate documental coverage into a runtime-readiness claim.
     *
     * @return array{
     *   runtime_ready:bool,
     *   allowed:bool,
     *   reason:string,
     *   meaning_of_covered:string,
     *   next_step:string
     * }
     */
    public function assertRuntimeReadiness(): array
    {
        return [
            'runtime_ready' => false,
            'allowed' => false,
            'reason' => 'coverage_matrix_proves_documentation_not_implementation',
            'meaning_of_covered' => 'documented_in_atlas_not_implemented_in_atlas',
            // doc "Escopo de Implementacao" / "Proximas Acoes".
            'next_step' => 'create_ap_for_AtlasFrontendDesignRuntime_then_functional_parity_per_subsystem',
        ];
    }

    /**
     * Re-audit guard (doc `decisions`/Rule 3 + Riscos "Repo externo muda"): the
     * audit window is only valid for the pinned commit. A different commit means
     * the matrix must be re-validated before its "covered" claims are trusted.
     *
     * @return array{
     *   observed_commit:string,
     *   pinned_commit:string,
     *   audit_window_valid:bool,
     *   action:string
     * }
     */
    public function validateAuditWindow(string $observedCommit): array
    {
        $valid = $observedCommit === self::PINNED_COMMIT;

        return [
            'observed_commit' => $observedCommit,
            'pinned_commit' => self::PINNED_COMMIT,
            'audit_window_valid' => $valid,
            'action' => $valid
                ? 'audit_window_valid_coverage_claims_trustable'
                : 're_audit_required_recount_and_rebuild_matrix_for_new_commit',
        ];
    }
}
