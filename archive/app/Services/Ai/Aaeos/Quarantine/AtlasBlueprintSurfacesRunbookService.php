<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Engineering Blueprint Surfaces Runbook — pure, deterministic surface
 * parity auditor for the Engineering Blueprint System.
 *
 * The runbook enumerates the three operating surfaces of the Blueprint System
 * (App screens, CLI commands, API routes) and states one load-bearing decision:
 *
 *   "Blueprint operations should keep app, CLI and API parity unless an omission
 *    is explicitly documented." (frontmatter decisions)
 *
 *   "If a Blueprint operation exists in one surface, the owner must decide
 *    whether CLI, API and app need equivalent access or an explicit reason for
 *    omission." (## Parity Rule)
 *
 * This service turns that rule into a deterministic audit. Given the three
 * documented surface inventories plus an optional map of explicitly documented
 * omissions, it:
 *   - canonicalizes each surface's operations to a stable operation id,
 *   - builds a parity matrix (operation × surface presence),
 *   - flags every operation that is present on at least one surface but ABSENT
 *     from another surface WITHOUT an explicit documented omission reason,
 *   - treats an absence WITH a documented reason as an accepted omission (not a
 *     violation),
 *   - reports the App maturity gap the doc itself records: the app still needs
 *     a fully unified run-detail flow outside the run-scoring context.
 *
 * Documented rules this code enforces (each is load-bearing and tested):
 *   - Three surfaces are first-class and named: app, cli, api (## App / ## CLI /
 *     ## API). The audit always reports all three.
 *   - Parity is required by default: an operation on one surface but missing on
 *     another is a violation UNLESS that (operation, surface) pair has an
 *     explicit documented omission reason.
 *   - An explicit omission converts a gap into an accepted omission; it must
 *     carry a non-empty reason, otherwise it does not count as "explicitly
 *     documented" and the gap stays a violation.
 *   - `ok` is true only when there are zero unexplained parity gaps.
 *   - The doc's own "Current maturity gap" (unified run-detail flow outside the
 *     run-scoring context) is surfaced as a known, non-blocking maturity note.
 *
 * Non-goals (read-only audit): it does not call any surface, does not mutate
 * routes/commands/screens, and does not promote status — `forbidden_changes`:
 * "Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates
 * verdes."
 *
 * @see docs/engineering-knowledge-base/engineering-blueprint/surfaces-runbook.md
 */
final class AtlasBlueprintSurfacesRunbookService
{
    /** Stable evidence schema id this audit emits. */
    public const SCHEMA = 'atlas.blueprint_surfaces.parity_audit.v1';

    /** The three first-class surfaces of the Blueprint System (## App/CLI/API). */
    public const SURFACE_APP = 'app';
    public const SURFACE_CLI = 'cli';
    public const SURFACE_API = 'api';

    /** Closed, ordered set of surfaces the audit always reports. */
    public const SURFACES = [
        self::SURFACE_APP,
        self::SURFACE_CLI,
        self::SURFACE_API,
    ];

    /** Documented finding ids. */
    public const FINDING_UNEXPLAINED_GAP = 'parity_gap_unexplained';
    public const FINDING_ACCEPTED_OMISSION = 'parity_omission_accepted';

    /**
     * The doc's own recorded maturity gap (## App): the app still needs a fully
     * unified run-detail flow outside the run-scoring context. Surfaced as a
     * known, non-blocking maturity note — it never flips `ok` to false on its own.
     */
    public const KNOWN_MATURITY_GAP = 'app_unified_run_detail_flow_outside_run_scoring_context';

    /**
     * Documented default surface inventories taken verbatim from the runbook.
     * Each entry maps a stable operation id to the surface-native label. Callers
     * may override any surface inventory; these are the safe documented defaults.
     *
     * App (## App):
     *   - Home > Projetos                          => project/task engineering context
     *   - Engenharia panels                        => contract, blueprint, gates, evidence
     *   - Home > Atlas Engineering                 => knowledge, tool runtime, replay, runs
     *
     * @return array{app:array<string,string>,cli:array<string,string>,api:array<string,string>}
     */
    public static function documentedSurfaces(): array
    {
        return [
            self::SURFACE_APP => [
                'project_engineering_context' => 'Home > Projetos',
                'engineering_panels' => 'Engenharia panels (contract/blueprint/gates/evidence)',
                'engineering_home' => 'Home > Atlas Engineering (knowledge/tool runtime/replay/runs)',
            ],
            self::SURFACE_CLI => [
                'dev' => 'atlas dev --task-id=<task-id>',
                'run' => 'atlas engineering run --task-id=<task-id> --workspace=... --sandbox=worktree --auto-test',
                'replay' => 'atlas engineering replay --run-id=<run-id>',
                'knowledge_sync' => 'atlas engineering knowledge sync --prune',
                'knowledge_index_code' => 'atlas engineering knowledge index-code --prune --workspace=...',
            ],
            self::SURFACE_API => [
                'engineering_read' => 'GET /tasks/{task}/engineering',
                'blueprint_freeze' => 'POST /tasks/{task}/engineering/blueprint/freeze',
                'evidence_write' => 'POST /tasks/{task}/engineering/evidence',
                'runs_create' => 'POST /tasks/{task}/engineering/runs',
                'run_read' => 'GET /engineering/runs/{run}',
            ],
        ];
    }

    /**
     * Audit surface parity per the runbook's Parity Rule.
     *
     * @param  array{app?:array<string,string>,cli?:array<string,string>,api?:array<string,string>}|null  $surfaces
     *         per-surface inventory mapping operation id => native label; null = documented defaults.
     * @param  array<int,array{operation:string,surface:string,reason?:string}>  $documentedOmissions
     *         explicitly documented omissions (each must carry a non-empty reason to be accepted).
     * @return array{
     *     schema:string,
     *     ok:bool,
     *     surfaces:array<int,string>,
     *     operations:array<int,string>,
     *     matrix:array<string,array{present_in:array<int,string>,missing_from:array<int,string>}>,
     *     violations:array<int,array{finding:string,operation:string,surface:string,detail:string}>,
     *     accepted_omissions:array<int,array{finding:string,operation:string,surface:string,reason:string}>,
     *     counts:array{operations:int,gaps:int,unexplained:int,accepted:int},
     *     known_maturity_gap:string
     * }
     */
    public function auditParity(?array $surfaces = null, array $documentedOmissions = []): array
    {
        $inventory = $this->normalizeInventory($surfaces);
        $omissionIndex = $this->indexOmissions($documentedOmissions);

        // Union of every operation that appears on at least one surface.
        $operations = $this->operationUniverse($inventory);

        $matrix = [];
        $violations = [];
        $accepted = [];

        foreach ($operations as $operation) {
            $presentIn = [];
            $missingFrom = [];

            foreach (self::SURFACES as $surface) {
                if (array_key_exists($operation, $inventory[$surface])) {
                    $presentIn[] = $surface;
                } else {
                    $missingFrom[] = $surface;
                }
            }

            $matrix[$operation] = [
                'present_in' => $presentIn,
                'missing_from' => $missingFrom,
            ];

            // An operation present on every surface has full parity — nothing to do.
            // Otherwise each surface it is missing from is a gap: a violation
            // unless an explicit, non-empty documented omission covers it.
            foreach ($missingFrom as $surface) {
                $reason = $omissionIndex[$operation][$surface] ?? null;

                if ($reason !== null && $reason !== '') {
                    $accepted[] = [
                        'finding' => self::FINDING_ACCEPTED_OMISSION,
                        'operation' => $operation,
                        'surface' => $surface,
                        'reason' => $reason,
                    ];

                    continue;
                }

                $violations[] = [
                    'finding' => self::FINDING_UNEXPLAINED_GAP,
                    'operation' => $operation,
                    'surface' => $surface,
                    'detail' => sprintf(
                        'Operation "%s" exists on [%s] but is missing from "%s" with no documented omission reason. '
                        . 'Per the Parity Rule the owner must add equivalent access or an explicit reason for omission.',
                        $operation,
                        implode(', ', $presentIn),
                        $surface,
                    ),
                ];
            }
        }

        $gapCount = count($violations) + count($accepted);

        return [
            'schema' => self::SCHEMA,
            'ok' => $violations === [],
            'surfaces' => self::SURFACES,
            'operations' => $operations,
            'matrix' => $matrix,
            'violations' => $violations,
            'accepted_omissions' => $accepted,
            'counts' => [
                'operations' => count($operations),
                'gaps' => $gapCount,
                'unexplained' => count($violations),
                'accepted' => count($accepted),
            ],
            'known_maturity_gap' => self::KNOWN_MATURITY_GAP,
        ];
    }

    /**
     * Convenience: audit the documented default surfaces with no omissions.
     * Models the runbook exactly as written — used as the command's safe default.
     *
     * @return array{
     *     schema:string,
     *     ok:bool,
     *     surfaces:array<int,string>,
     *     operations:array<int,string>,
     *     matrix:array<string,array{present_in:array<int,string>,missing_from:array<int,string>}>,
     *     violations:array<int,array{finding:string,operation:string,surface:string,detail:string}>,
     *     accepted_omissions:array<int,array{finding:string,operation:string,surface:string,reason:string}>,
     *     counts:array{operations:int,gaps:int,unexplained:int,accepted:int},
     *     known_maturity_gap:string
     * }
     */
    public function auditDocumented(): array
    {
        return $this->auditParity(self::documentedSurfaces());
    }

    /**
     * Normalize the per-surface inventory: documented defaults for any surface
     * the caller omits; string keys only; always exactly the three surfaces.
     *
     * @param  array{app?:array<string,string>,cli?:array<string,string>,api?:array<string,string>}|null  $surfaces
     * @return array<string,array<string,string>>
     */
    private function normalizeInventory(?array $surfaces): array
    {
        $defaults = self::documentedSurfaces();
        $out = [];

        foreach (self::SURFACES as $surface) {
            $raw = $surfaces[$surface] ?? $defaults[$surface];
            $clean = [];

            foreach ($raw as $operation => $label) {
                $opId = is_string($operation) ? trim($operation) : '';

                if ($opId === '') {
                    continue;
                }

                $clean[$opId] = is_string($label) ? $label : $opId;
            }

            $out[$surface] = $clean;
        }

        return $out;
    }

    /**
     * Index documented omissions as operation => surface => reason. A blank or
     * unknown surface is ignored; the last non-empty reason for a pair wins.
     *
     * @param  array<int,array{operation:string,surface:string,reason?:string}>  $omissions
     * @return array<string,array<string,string>>
     */
    private function indexOmissions(array $omissions): array
    {
        $index = [];

        foreach ($omissions as $omission) {
            $operation = isset($omission['operation']) && is_string($omission['operation'])
                ? trim($omission['operation'])
                : '';
            $surface = isset($omission['surface']) && is_string($omission['surface'])
                ? trim($omission['surface'])
                : '';
            $reason = isset($omission['reason']) && is_string($omission['reason'])
                ? trim($omission['reason'])
                : '';

            if ($operation === '' || ! in_array($surface, self::SURFACES, true)) {
                continue;
            }

            $index[$operation][$surface] = $reason;
        }

        return $index;
    }

    /**
     * The sorted union of all operation ids present on any surface.
     *
     * @param  array<string,array<string,string>>  $inventory
     * @return array<int,string>
     */
    private function operationUniverse(array $inventory): array
    {
        $seen = [];

        foreach (self::SURFACES as $surface) {
            foreach (array_keys($inventory[$surface]) as $operation) {
                $seen[$operation] = true;
            }
        }

        $operations = array_keys($seen);
        sort($operations);

        return $operations;
    }
}
