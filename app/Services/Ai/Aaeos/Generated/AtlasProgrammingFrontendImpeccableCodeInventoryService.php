<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime for the "Impeccable Code Inventory For Atlas Frontend" doc.
 *
 * The doc is a FILE INVENTORY of an audited external repo (`pbakaus/impeccable`
 * at a pinned commit), used to teach Atlas Frontend which areas are real product
 * versus generated provider output. Its load-bearing, deterministic rules
 * (frontmatter `decisions`/`forbidden_changes`, "Resumo", "Contratos", "Fluxo",
 * "Regras para IA", "Riscos"):
 *
 *   1. Capacity claims must be measured on AUTHORIAL source only. The audited
 *      clone has 1691 total files; after excluding the generated provider
 *      bundle directories, 670 authorial/operational files remain. Counting the
 *      generated bundles as architecture is a forbidden change ("Inflar
 *      capacidade por contar bundles gerados").
 *   2. A fixed set of directories are GENERATED provider outputs and must never
 *      be treated as primary authorial source: `.claude`, `.agents`, `.cursor`,
 *      `.gemini`, `.github`, `.kiro`, `.opencode`, `.pi`, `.qoder`, `.rovodev`,
 *      `.trae`, `.trae-cn`, `plugin`.
 *   3. A fixed set of areas are PRIMARY authorial subsystems to audit first
 *      (skill source/reference/scripts, cli/detector engine, extension, scripts,
 *      tests). Source is audited before provider bundles ("Regras para IA" 1/3).
 *   4. The inventory is a coverage/architecture map, NOT an Atlas runtime claim
 *      ("ai_usage_notes": use as evidence of audit coverage, not runtime).
 *   5. The audit window is valid only for the pinned commit; a different commit
 *      requires re-auditing before the counts are trusted ("maintenance",
 *      "next_actions", Riscos "Repo externo muda").
 *
 * This service enforces those rules in pure memory with no DB. It carries the
 * verified "Contratos" inventory table, the generated-dir exclusion set and the
 * primary-area set verbatim, and exposes: the authorial-vs-total reconciliation,
 * a per-directory authorial/generated classifier, the primary-area gate, and the
 * re-audit window guard.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-code-inventory.md
 */
final class AtlasProgrammingFrontendImpeccableCodeInventoryService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.programming_frontend_impeccable_code_inventory.v1';

    public const MODE = 'audited_external_file_inventory_read_only';

    /** Audited external subject + pinned audit window ("Resumo"/"Evidencias"). */
    public const EXTERNAL_SUBJECT = 'pbakaus/impeccable';

    public const PINNED_COMMIT = '84135db0e6bdd58d22828f7bc8331cae7bde3e7f';

    /** Total files in the audited clone ("Resumo"). */
    public const TOTAL_FILES = 1691;

    /** Authorial/operational files after excluding generated bundles ("Resumo"). */
    public const AUTHORIAL_FILES = 670;

    /** docs-health is the doc's `required_tests` / `quality_gates` signal. */
    public const REQUIRED_QUALITY_GATE = 'php artisan atlas:engineering:knowledge docs-health --json';

    /**
     * The generated provider bundle directories, verbatim from "Resumo" /
     * "Regras para IA" rule 2. These are excluded from the authorial count and
     * must never be treated as primary authorial source (forbidden change).
     *
     * @var array<int, string>
     */
    private const GENERATED_DIRS = [
        '.claude', '.agents', '.cursor', '.gemini', '.github', '.kiro',
        '.opencode', '.pi', '.qoder', '.rovodev', '.trae', '.trae-cn', 'plugin',
    ];

    /**
     * Primary authorial subsystems to audit first ("Papel no Atlas" +
     * "Regras para IA" rule 3). Source areas, audited before provider bundles.
     *
     * @var array<int, string>
     */
    private const PRIMARY_AREAS = [
        'skill/SKILL.md', 'skill/reference', 'skill/scripts',
        'cli/engine', 'extension', 'scripts', 'tests',
    ];

    /**
     * The verified inventory table, copied verbatim from the doc's "Contratos"
     * section: each audited area with its file count and role. These are the
     * authorial/operational areas that survive the generated-bundle exclusion.
     *
     * @var array<int, array{area:string, files:int, role:string}>
     */
    private const INVENTORY = [
        ['area' => 'tests', 'files' => 320, 'role' => 'regressao, detector, live mode, provider transforms, fixtures'],
        ['area' => 'site', 'files' => 197, 'role' => 'site produto, docs, exemplos, demos publicos'],
        ['area' => 'skill', 'files' => 62, 'role' => 'fonte da skill, referencias e scripts runtime'],
        ['area' => 'extension', 'files' => 21, 'role' => 'Chrome extension, devtools panel, popup, content/background'],
        ['area' => 'cli', 'files' => 20, 'role' => 'detector CLI, engines, rules, browser script, package bin'],
        ['area' => 'scripts', 'files' => 15, 'role' => 'build, release, zips, provider transforms, assets'],
        ['area' => 'demos', 'files' => 6, 'role' => 'exemplo de landing/demo'],
        ['area' => 'notes', 'files' => 2, 'role' => 'ADR/plano de live session recovery'],
        ['area' => 'functions', 'files' => 2, 'role' => 'endpoints Cloudflare Pages de download'],
        ['area' => '.codex', 'files' => 1, 'role' => 'subagente impeccable_asset_producer'],
        ['area' => '.claude-plugin', 'files' => 2, 'role' => 'manifest e marketplace Claude plugin'],
    ];

    /**
     * The documented "Fluxo": the ordered authorship pipeline. Source skill is
     * first; tests/fixtures guard regressions at the end.
     *
     * @var array<int, string>
     */
    private const FLOW = [
        'source skill/reference/scripts',
        'build transforms',
        'provider bundles',
        'cli/detector package',
        'site and extension artifacts',
        'tests and fixtures guard regressions',
    ];

    /**
     * Return the full verified inventory plus the audit window metadata and the
     * sum of the catalogued areas.
     *
     * @return array{
     *   schema_version:string,
     *   mode:string,
     *   external_subject:string,
     *   pinned_commit:string,
     *   total_files:int,
     *   authorial_files:int,
     *   area_count:int,
     *   catalogued_file_count:int,
     *   inventory:array<int, array{area:string, files:int, role:string}>
     * }
     */
    public function inventory(): array
    {
        $catalogued = 0;
        foreach (self::INVENTORY as $row) {
            $catalogued += $row['files'];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'external_subject' => self::EXTERNAL_SUBJECT,
            'pinned_commit' => self::PINNED_COMMIT,
            'total_files' => self::TOTAL_FILES,
            'authorial_files' => self::AUTHORIAL_FILES,
            'area_count' => count(self::INVENTORY),
            'catalogued_file_count' => $catalogued,
            'inventory' => array_values(self::INVENTORY),
        ];
    }

    /**
     * The documented authorship flow, in order. Source skill is the first step,
     * never a provider bundle.
     *
     * @return array<int, string>
     */
    public function flow(): array
    {
        return self::FLOW;
    }

    /**
     * Core reconciliation (doc "Resumo" + decision[0] + Riscos): authorial count
     * is total minus generated provider bundles. With no argument it returns the
     * doc's verified split (1691 total, 670 authorial, 1021 generated). Passing
     * an explicit total/generated pair recomputes the same way, so a recount can
     * be validated against the documented invariant.
     *
     * @return array{
     *   total_files:int,
     *   generated_dir_count:int,
     *   generated_files:int,
     *   authorial_files:int,
     *   matches_documented_split:bool,
     *   conclusion:string
     * }
     */
    public function reconcileAuthorialFiles(?int $totalFiles = null, ?int $generatedFiles = null): array
    {
        $total = $totalFiles ?? self::TOTAL_FILES;
        // The documented authorial split implies this many generated files.
        $generated = $generatedFiles ?? (self::TOTAL_FILES - self::AUTHORIAL_FILES);
        $authorial = $total - $generated;

        $matches = $total === self::TOTAL_FILES && $authorial === self::AUTHORIAL_FILES;

        return [
            'total_files' => $total,
            'generated_dir_count' => count(self::GENERATED_DIRS),
            'generated_files' => $generated,
            'authorial_files' => $authorial,
            'matches_documented_split' => $matches,
            'conclusion' => $matches
                ? 'authorial_split_matches_documented_audit'
                : 're_audit_recount_does_not_match_documented_670_of_1691',
        ];
    }

    /**
     * Classify a top-level directory (doc "Resumo" generated set + "Regras para
     * IA" rule 2/3 + forbidden_changes). A generated provider-bundle dir is
     * NEVER primary authorial source; it must be excluded from capacity claims.
     * A primary area is authorial AND flagged as a first-audit subsystem.
     *
     * Matching is exact on the leading path segment, so `.trae-cn` and `.trae`
     * are distinct, and `skill/reference` is recognised as a primary area.
     *
     * @return array{
     *   path:string,
     *   segment:string,
     *   classification:string,
     *   is_generated:bool,
     *   is_authorial:bool,
     *   is_primary_area:bool,
     *   counts_toward_capacity:bool,
     *   reason:string
     * }
     */
    public function classifyDirectory(string $path): array
    {
        $trimmed = trim($path);
        $normalized = ltrim(str_replace('\\', '/', $trimmed), '/');
        $segment = $normalized === '' ? '' : explode('/', $normalized)[0];

        $isGenerated = in_array($segment, self::GENERATED_DIRS, true);

        // A path is a primary area when it equals a primary path or sits under
        // one (e.g. `cli/engine/rules/checks.mjs` is under `cli/engine`, and
        // `skill/reference/foo` is under `skill/reference`). Single-segment
        // primary areas like `tests`/`extension`/`scripts` also match their
        // children.
        $isPrimary = false;
        foreach (self::PRIMARY_AREAS as $primary) {
            if ($normalized === $primary || str_starts_with($normalized.'/', $primary.'/')) {
                $isPrimary = true;

                break;
            }
        }

        // Generated wins: a provider bundle is never authorial even if its name
        // collides with an authorial-sounding segment.
        $isAuthorial = ! $isGenerated;

        if ($isGenerated) {
            $classification = 'generated_provider_bundle';
            $reason = 'generated_output_excluded_from_authorial_count_and_capacity';
        } elseif ($isPrimary) {
            $classification = 'primary_authorial_area';
            $reason = 'first_audit_authorial_subsystem';
        } else {
            $classification = 'authorial_area';
            $reason = 'authorial_or_operational_source';
        }

        return [
            'path' => $trimmed,
            'segment' => $segment,
            'classification' => $classification,
            'is_generated' => $isGenerated,
            'is_authorial' => $isAuthorial,
            'is_primary_area' => $isPrimary && ! $isGenerated,
            // Only authorial source counts toward capacity (decision[0] + Riscos).
            'counts_toward_capacity' => $isAuthorial,
            'reason' => $reason,
        ];
    }

    /**
     * Primary-area audit gate ("Regras para IA" rule 1/3): the inventory is only
     * trustworthy when every primary authorial subsystem has been audited. With
     * no argument it reports the canonical primary set as still-to-audit; pass
     * the set of audited paths to find what is missing.
     *
     * @param  array<int, string>  $auditedPaths
     * @return array{
     *   primary_areas:array<int, string>,
     *   audited:array<int, string>,
     *   missing:array<int, string>,
     *   all_primary_audited:bool,
     *   status:string
     * }
     */
    public function evaluatePrimaryCoverage(array $auditedPaths = []): array
    {
        $audited = [];
        $missing = [];

        foreach (self::PRIMARY_AREAS as $area) {
            if (in_array($area, $auditedPaths, true)) {
                $audited[] = $area;
            } else {
                $missing[] = $area;
            }
        }

        $all = $missing === [];

        return [
            'primary_areas' => array_values(self::PRIMARY_AREAS),
            'audited' => $audited,
            'missing' => $missing,
            'all_primary_audited' => $all,
            'status' => $all
                ? 'primary_authorial_areas_fully_audited'
                : 'primary_audit_incomplete_audit_source_before_bundles',
        ];
    }

    /**
     * Re-audit guard (doc "maintenance"/"next_actions" + Riscos): the inventory
     * counts are valid only for the pinned commit. A different commit invalidates
     * the audit and requires a recount before the numbers are trusted.
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
        $valid = trim($observedCommit) === self::PINNED_COMMIT;

        return [
            'observed_commit' => trim($observedCommit),
            'pinned_commit' => self::PINNED_COMMIT,
            'audit_window_valid' => $valid,
            'action' => $valid
                ? 'audit_window_valid_inventory_counts_trustable'
                : 're_audit_required_recount_inventory_for_new_commit',
        ];
    }
}
