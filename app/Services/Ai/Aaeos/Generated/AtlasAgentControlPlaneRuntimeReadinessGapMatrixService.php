<?php

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime for the Agent Control Plane Runtime Readiness Gap Matrix v1 doc.
 *
 * The doc is an OBSERVATIONAL block-by-block matrix. Its load-bearing rule lives
 * in the "Closing Note": a Runtime cell may flip to "Y" ONLY when the promotion
 * cites ALL FIVE artifacts:
 *   1. signed promotion gate id;
 *   2. replay diff status — exactly "improved" or "passed" (never "regressed");
 *   3. release dossier hash;
 *   4. mutation guard guard_passed=true;
 *   5. the first real evidence event id (ledger or external attestation).
 * Without all five, the row STAYS "N". The matrix itself never promotes a row.
 *
 * This service enforces that gate deterministically, in pure memory, with no DB.
 * It also carries the canonical block list (Runtime column intentionally has no
 * Y rows in the audit window) and the `not_yet_runtime_capable` (4) list, so a
 * caller can never render the matrix with an unjustified Runtime=Y.
 *
 * @see docs/engineering-knowledge-base/self-construction/audits/atlas-agent-control-plane-runtime-readiness-gap-matrix-v1.md
 */
final class AtlasAgentControlPlaneRuntimeReadinessGapMatrixService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.agent_control_plane_runtime_readiness_gap_matrix.v1';

    public const MODE = 'observational_read_only_matrix';

    /** Cell legend declared by the doc. */
    public const CELL_Y = 'Y';

    public const CELL_N = 'N';

    public const CELL_PARTIAL = 'partial';

    /** The five artifacts the Closing Note requires before a Runtime cell is Y. */
    public const REQUIRED_RUNTIME_ARTIFACTS = [
        'signed_promotion_gate_id',
        'replay_diff_status',
        'release_dossier_hash',
        'mutation_guard_passed',
        'first_real_evidence_event_id',
    ];

    /** Replay diff statuses the Closing Note accepts as non-blocking. */
    public const ACCEPTABLE_REPLAY_DIFF_STATUSES = ['improved', 'passed'];

    /**
     * The four capabilities the doc lists under `not_yet_runtime_capable`.
     * These can never carry Runtime=Y while this audit window is the pointer.
     */
    public const NOT_YET_RUNTIME_CAPABLE = [
        'adapter_execution_runtime',
        'automatic_cost_import_runtime',
        'automatic_work_product_collection_runtime',
        'automatic_dispatch_scheduler_codex_real_invoker_post_start_receipt_contract_runtime',
    ];

    /** Current Pointer block from the doc. */
    public const CURRENT_NEXT_REQUIRED_SLICE = 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract';

    public const NEXT_SAFE_MACRO_BATCH = 'reentry_into_post_start_evidence_corridor';

    /**
     * The canonical matrix rows, copied from the doc's table. Every Runtime cell
     * here is "N" or "partial" — the doc states the Runtime column has no Y rows
     * in this audit window. Each row's `runtime` value is the OBSERVED state; a
     * caller must run promoteRuntimeCell() with the five artifacts to move it.
     *
     * @var array<int, array{block:string, contract:string, certification:string, dry_run:string, runtime:string, persistence:string, ui:string, tests:string}>
     */
    private const BLOCKS = [
        ['block' => 'Task Packet', 'contract' => 'Y', 'certification' => 'Y', 'dry_run' => 'Y', 'runtime' => 'N', 'persistence' => 'partial', 'ui' => 'N', 'tests' => 'partial'],
        ['block' => 'Claim/Lease', 'contract' => 'Y', 'certification' => 'Y', 'dry_run' => 'Y', 'runtime' => 'N', 'persistence' => 'partial', 'ui' => 'N', 'tests' => 'partial'],
        ['block' => 'Scope Lock', 'contract' => 'Y', 'certification' => 'Y', 'dry_run' => 'Y', 'runtime' => 'N', 'persistence' => 'N', 'ui' => 'N', 'tests' => 'partial'],
        ['block' => 'Evidence Ledger', 'contract' => 'Y', 'certification' => 'Y', 'dry_run' => 'Y', 'runtime' => 'N', 'persistence' => 'partial', 'ui' => 'N', 'tests' => 'partial'],
        ['block' => 'Continuation Summary', 'contract' => 'Y', 'certification' => 'Y', 'dry_run' => 'Y', 'runtime' => 'N', 'persistence' => 'N', 'ui' => 'N', 'tests' => 'partial'],
        ['block' => 'Work Product Manifest', 'contract' => 'Y', 'certification' => 'Y', 'dry_run' => 'Y', 'runtime' => 'N', 'persistence' => 'N', 'ui' => 'N', 'tests' => 'partial'],
        ['block' => 'Cost Import', 'contract' => 'Y', 'certification' => 'Y', 'dry_run' => 'Y', 'runtime' => 'N', 'persistence' => 'N', 'ui' => 'N', 'tests' => 'partial'],
        ['block' => 'Multi-Agent Planning', 'contract' => 'Y', 'certification' => 'Y', 'dry_run' => 'Y', 'runtime' => 'N', 'persistence' => 'N', 'ui' => 'N', 'tests' => 'partial'],
        ['block' => 'Runtime Pilot Orchestrator', 'contract' => 'Y', 'certification' => 'Y', 'dry_run' => 'partial', 'runtime' => 'N', 'persistence' => 'N', 'ui' => 'N', 'tests' => 'partial'],
        ['block' => 'Runtime Pilot Certification', 'contract' => 'Y', 'certification' => 'Y', 'dry_run' => 'partial', 'runtime' => 'N', 'persistence' => 'N', 'ui' => 'N', 'tests' => 'partial'],
        ['block' => 'Real Dispatch', 'contract' => 'Y', 'certification' => 'Y', 'dry_run' => 'Y', 'runtime' => 'N', 'persistence' => 'partial', 'ui' => 'N', 'tests' => 'partial'],
        ['block' => 'Provider/Adapter Execution', 'contract' => 'Y', 'certification' => 'Y', 'dry_run' => 'Y', 'runtime' => 'N', 'persistence' => 'N', 'ui' => 'N', 'tests' => 'partial'],
        ['block' => 'Process Start', 'contract' => 'Y', 'certification' => 'Y', 'dry_run' => 'Y', 'runtime' => 'N', 'persistence' => 'partial', 'ui' => 'N', 'tests' => 'partial'],
        ['block' => 'Kill Switch', 'contract' => 'Y', 'certification' => 'partial', 'dry_run' => 'partial', 'runtime' => 'N', 'persistence' => 'N', 'ui' => 'N', 'tests' => 'N'],
        ['block' => 'Rollback', 'contract' => 'Y', 'certification' => 'partial', 'dry_run' => 'partial', 'runtime' => 'N', 'persistence' => 'N', 'ui' => 'N', 'tests' => 'partial'],
        ['block' => 'Budget Guard', 'contract' => 'Y', 'certification' => 'partial', 'dry_run' => 'partial', 'runtime' => 'N', 'persistence' => 'N', 'ui' => 'N', 'tests' => 'partial'],
        ['block' => 'Work Product Collection', 'contract' => 'Y', 'certification' => 'Y', 'dry_run' => 'Y', 'runtime' => 'N', 'persistence' => 'N', 'ui' => 'N', 'tests' => 'partial'],
        ['block' => 'End-to-End Runtime', 'contract' => 'Y', 'certification' => 'Y', 'dry_run' => 'Y', 'runtime' => 'N', 'persistence' => 'partial', 'ui' => 'N', 'tests' => 'partial'],
        ['block' => 'UI Operator Surface', 'contract' => 'partial', 'certification' => 'N', 'dry_run' => 'N', 'runtime' => 'N', 'persistence' => 'N', 'ui' => 'partial', 'tests' => 'N'],
        ['block' => 'Forge / SelfImprovement Integration', 'contract' => 'partial', 'certification' => 'N', 'dry_run' => 'N', 'runtime' => 'N', 'persistence' => 'N', 'ui' => 'N', 'tests' => 'N'],
    ];

    /**
     * Evaluate the Closing Note gate for one Runtime cell against a set of cited
     * artifacts. Returns whether the cell is allowed to render as Y, plus the
     * concrete reasons it is blocked when not. PURE — no I/O, no DB.
     *
     * @param  array<string,mixed>  $artifacts
     * @return array{
     *   block:string,
     *   runtime_cell:string,
     *   promotion_allowed:bool,
     *   missing_artifacts:array<int,string>,
     *   invalid_artifacts:array<int,string>,
     *   present_artifacts:array<int,string>,
     *   replay_diff_status:string,
     *   blocked_reason:string
     * }
     */
    public function promoteRuntimeCell(string $block, array $artifacts): array
    {
        $present = [];
        $missing = [];
        $invalid = [];

        // Gate is forbidden outright for the four not_yet_runtime_capable rows.
        $hardBlocked = in_array($this->slugify($block), self::NOT_YET_RUNTIME_CAPABLE, true);

        foreach (self::REQUIRED_RUNTIME_ARTIFACTS as $artifact) {
            $value = $artifacts[$artifact] ?? null;

            if ($this->isEmptyArtifact($value)) {
                $missing[] = $artifact;

                continue;
            }

            // Artifact-specific validity, per the Closing Note semantics.
            if ($artifact === 'replay_diff_status'
                && ! in_array((string) $value, self::ACCEPTABLE_REPLAY_DIFF_STATUSES, true)) {
                $invalid[] = $artifact;

                continue;
            }
            if ($artifact === 'mutation_guard_passed' && $value !== true) {
                $invalid[] = $artifact;

                continue;
            }

            $present[] = $artifact;
        }

        $allFivePresent = $missing === [] && $invalid === [];
        $allowed = $allFivePresent && ! $hardBlocked;

        $blockedReason = match (true) {
            $allowed => 'promotion_allowed_all_five_artifacts_cited',
            $hardBlocked => 'block_is_not_yet_runtime_capable',
            $invalid !== [] => 'artifact_present_but_invalid',
            default => 'missing_required_artifacts',
        };

        return [
            'block' => $block,
            // The cell only renders Y when the gate passes; otherwise it STAYS N.
            'runtime_cell' => $allowed ? self::CELL_Y : self::CELL_N,
            'promotion_allowed' => $allowed,
            'missing_artifacts' => $missing,
            'invalid_artifacts' => $invalid,
            'present_artifacts' => $present,
            'replay_diff_status' => (string) ($artifacts['replay_diff_status'] ?? ''),
            'blocked_reason' => $blockedReason,
        ];
    }

    /**
     * Render the full observational matrix. By default (no promotions) every
     * Runtime cell is N/partial exactly as the doc states. A caller MAY supply
     * a map of block => artifacts; only blocks whose artifacts pass the five-item
     * gate get their Runtime cell flipped to Y. Everything else stays as observed.
     *
     * @param  array<string, array<string,mixed>>  $runtimePromotions
     * @return array<string,mixed>
     */
    public function matrix(array $runtimePromotions = []): array
    {
        $rows = [];
        $runtimeYBlocks = [];

        foreach (self::BLOCKS as $block) {
            $name = $block['block'];
            $runtimeCell = $block['runtime']; // observed value: N or partial

            $promotion = null;
            if (array_key_exists($name, $runtimePromotions)) {
                $promotion = $this->promoteRuntimeCell($name, $runtimePromotions[$name]);
                if ($promotion['promotion_allowed']) {
                    $runtimeCell = self::CELL_Y;
                    $runtimeYBlocks[] = $name;
                }
            }

            $rows[] = [
                'block' => $name,
                'contract' => $block['contract'],
                'certification' => $block['certification'],
                'dry_run' => $block['dry_run'],
                'runtime' => $runtimeCell,
                'persistence' => $block['persistence'],
                'ui' => $block['ui'],
                'tests' => $block['tests'],
                'runtime_promotion' => $promotion,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'cell_legend' => [
                self::CELL_Y => 'surface exists today, observed via read-only command or canonical doc',
                self::CELL_N => 'surface does not exist',
                self::CELL_PARTIAL => 'declared with quartet/contract/preflight/implementation-packet/status but no real end-to-end runtime/persistence/UI/tests',
            ],
            'required_runtime_artifacts' => self::REQUIRED_RUNTIME_ARTIFACTS,
            'acceptable_replay_diff_statuses' => self::ACCEPTABLE_REPLAY_DIFF_STATUSES,
            'not_yet_runtime_capable' => self::NOT_YET_RUNTIME_CAPABLE,
            'current_next_required_slice' => self::CURRENT_NEXT_REQUIRED_SLICE,
            'next_safe_macro_batch' => self::NEXT_SAFE_MACRO_BATCH,
            'block_count' => count($rows),
            'runtime_y_count' => count($runtimeYBlocks),
            'runtime_y_blocks' => $runtimeYBlocks,
            // Doc invariant: the audit window has zero Runtime=Y unless promoted.
            'all_runtime_cells_blocked' => $runtimeYBlocks === [],
            'rows' => $rows,
            'closing_note_rule' => 'A Runtime cell may render Y ONLY when all five artifacts are cited; without all five the row stays N. The matrix never auto-promotes.',
        ];
    }

    /**
     * "Regras para IA": a renderer must never show a Runtime=Y row without the
     * five artifacts. This audits a proposed rendered matrix and flags any row
     * that claims Runtime=Y without a passing promotion. PURE.
     *
     * @param  array<int, array<string,mixed>>  $renderedRows
     * @return array{compliant:bool, violations:array<int, array{block:string, reason:string}>}
     */
    public function auditRenderedMatrix(array $renderedRows): array
    {
        $violations = [];

        foreach ($renderedRows as $row) {
            $block = (string) ($row['block'] ?? '');
            $runtime = (string) ($row['runtime'] ?? '');

            if ($runtime !== self::CELL_Y) {
                continue;
            }

            $promotion = $row['runtime_promotion'] ?? null;
            $allowed = is_array($promotion) && ($promotion['promotion_allowed'] ?? false) === true;

            if (! $allowed) {
                $violations[] = [
                    'block' => $block,
                    'reason' => 'runtime_y_without_five_artifact_promotion',
                ];
            }
        }

        return [
            'compliant' => $violations === [],
            'violations' => $violations,
        ];
    }

    private function isEmptyArtifact(mixed $value): bool
    {
        if ($value === null || $value === false) {
            return true;
        }
        if (is_string($value) && trim($value) === '') {
            return true;
        }

        return false;
    }

    private function slugify(string $block): string
    {
        $slug = strtolower($block);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';

        return trim($slug, '_');
    }
}
