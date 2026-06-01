<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Vault Cartography Schema Runbook — pure, deterministic cartography decider.
 *
 * The runbook is the authoring boundary for HOW Atlas Vault Cartography reads,
 * resolves, renders and migrates source-aware graph pieces. This service turns
 * the runbook's documented rules into runtime. It is read-only: it classifies a
 * source binding, resolves a piece's truth status, gates the migration phases
 * and orders the live-documentation flow. It never walks the filesystem, never
 * opens a file, never emits evidence and never relaxes a rule — it only decides.
 *
 * Four documented decision surfaces are implemented, one cluster of methods each:
 *
 *   1. Reader Model ("Reader Model" table). The Atlas App consumes cartography
 *      through two source-aware readers. Each concern (Walk/Parse/Index/Watch/
 *      Open/Write) has a different binding for the `repo` reader vs the `vault`
 *      reader. The vault reader is explicitly "deduped against repo" on Index.
 *      Every resolved piece must keep its `source`, source-relative path and
 *      resolution status. An unknown source never silently binds — it is blocked.
 *
 *   2. Missing Source ("Missing Source"). "Never render a missing file as healthy
 *      truth." If a referenced file is missing or stale: render `missing_source`
 *      visibly, show the expected path (+ last cached content if any), DISABLE the
 *      "open source" action that would lie, emit documentation-drift evidence and
 *      allow Atlas AI to propose repair through the Inbox. Only a present, fresh
 *      source resolves healthy with an enabled open action.
 *
 *   3. Migration Phases ("Migration Phases"). Phases 0-6 are an ordered sequence.
 *      The hard gate is "Phase 6: Deprecate Hardcoded Data" — inline mockup arrays
 *      may be removed ONLY "after the API is source of graph data", which requires
 *      Phase 2 (backend readers / merged graph API) AND Phase 3 (frontend JSON
 *      consumption) to be done. Deprecating hardcoded data before that is blocked.
 *
 *   4. Live Documentation Flow ("Live Documentation Flow"). The 7-step sequence
 *      that fires when Atlas AI edits a canonical repo doc, "keeping the AI as
 *      operator, not source truth". The watcher detects the change; cartography
 *      re-renders; the inspector shows the real file; the human reads the real
 *      file, not an agent narration; any gap becomes a revision request / Inbox
 *      item.
 *
 * Non-goals honoured (from "Regras para IA" / "Escopo de Implementacao"):
 *   - does NOT walk the repo or vault filesystem (no IO here);
 *   - does NOT open a source / never produces a lying "open" action;
 *   - does NOT write a doc, a note or an evidence record (it only flags that a
 *     drift evidence is REQUIRED);
 *   - does NOT promote a missing source to healthy truth.
 *
 * @see docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-runbook.md
 */
final class AtlasVaultCartographySchemaRunbookService
{
    /** Stable evidence schema id this decider emits. */
    public const SCHEMA = 'atlas.vault.cartography.runbook.v1';

    /** The two source-aware readers (closed set). */
    public const SOURCE_REPO = 'repo';
    public const SOURCE_VAULT = 'vault';

    /**
     * The two canonical sources, in documented column order (repo reader, vault
     * reader).
     *
     * @var list<string>
     */
    public const SOURCES = [
        self::SOURCE_REPO,
        self::SOURCE_VAULT,
    ];

    /** Resolution verdicts for a cartography piece (closed set). */
    public const RESOLUTION_HEALTHY = 'healthy';
    public const RESOLUTION_MISSING_SOURCE = 'missing_source';

    /** Reader binding verdicts (closed set). */
    public const BINDING_RESOLVED = 'resolved';
    public const BINDING_BLOCKED = 'blocked';

    /** Migration-phase verdicts (closed set). */
    public const PHASE_ALLOWED = 'allowed';
    public const PHASE_BLOCKED = 'blocked';

    /**
     * Reader Model bindings, keyed by source. Each concern maps exactly to the
     * documented "Reader Model" table cell. Read-only: `write` names the single
     * managed service permitted to write that source.
     *
     * @var array<string, array{walk: string, parse: string, index: string, watch: string, open: string, write: string}>
     */
    public const READER_BINDINGS = [
        self::SOURCE_REPO => [
            'walk' => 'docs/engineering-knowledge-base/',
            'parse' => 'frontmatter+markdown_body',
            'index' => 'keyed_by_graph_id',
            'watch' => 'filesystem_watcher_on_repo_path',
            'open' => 'code_editor_or_os_handler',
            'write' => 'managed_repo_doc_services_only',
        ],
        self::SOURCE_VAULT => [
            'walk' => '~/AtlasVault/',
            'parse' => 'frontmatter+markdown_body',
            'index' => 'keyed_by_graph_id_deduped_against_repo',
            'watch' => 'watcher_on_icloud_synced_vault_path',
            'open' => 'obsidian_open',
            'write' => 'managed_note_service_only',
        ],
    ];

    /**
     * The 7 ordered migration phases ("Migration Phases" headings, in order).
     *
     * @var array<int, string>
     */
    public const MIGRATION_PHASES = [
        0 => 'live_doc_tooling',
        1 => 'visual_layer_in_repo_kernel_docs',
        2 => 'backend_readers',
        3 => 'frontend_json_consumption',
        4 => 'other_continents',
        5 => 'validation_tooling',
        6 => 'deprecate_hardcoded_data',
    ];

    /**
     * Phases that must be done before "Phase 6: Deprecate Hardcoded Data" may run.
     * The runbook removes inline arrays only "after the API is source of graph
     * data": that is the merged graph API (Phase 2) consumed by the frontend
     * (Phase 3).
     *
     * @var list<int>
     */
    public const DEPRECATE_HARDCODED_PREREQUISITES = [2, 3];

    /**
     * The 7 ordered live-documentation flow steps ("Live Documentation Flow").
     *
     * @var array<int, string>
     */
    public const LIVE_DOC_FLOW = [
        1 => 'ai_edits_canonical_repo_doc',
        2 => 'watcher_detects_filesystem_change',
        3 => 'cartography_rerenders_affected_pieces',
        4 => 'inspector_shows_real_updated_file_and_path',
        5 => 'piece_shows_recent_update_badge',
        6 => 'human_reads_real_file_not_agent_narration',
        7 => 'gap_becomes_revision_request_or_proposal_inbox_item',
    ];

    // ---------------------------------------------------------------------
    // 1. Reader Model
    // ---------------------------------------------------------------------

    /**
     * Resolve the source-aware reader binding for a piece.
     *
     * An unknown / missing source never silently binds to a default reader — it
     * is blocked so cartography cannot read or open it through the wrong handler.
     *
     * @param array<string,mixed> $piece
     *        source : string  one of SOURCES (unknown => blocked, fail-safe)
     *
     * @return array<string,mixed>
     */
    public function resolveReaderBinding(array $piece): array
    {
        $rawSource = $piece['source'] ?? null;
        $source = is_string($rawSource) ? strtolower(trim($rawSource)) : '';
        $known = in_array($source, self::SOURCES, true);

        $reasons = [];

        if (! $known) {
            $reasons[] = 'unknown_source_cannot_bind_reader';

            return [
                'schema' => self::SCHEMA,
                'surface' => 'reader_model',
                'source' => $source === '' ? null : $source,
                'known_source' => false,
                'verdict' => self::BINDING_BLOCKED,
                'binding' => null,
                'deduped_against_repo' => false,
                'reasons' => $reasons,
            ];
        }

        $binding = self::READER_BINDINGS[$source];
        // Only the vault reader dedupes its index against the repo index.
        $dedupe = $source === self::SOURCE_VAULT;
        $reasons[] = "reader_bound:{$source}";
        if ($dedupe) {
            $reasons[] = 'vault_index_deduped_against_repo';
        }

        return [
            'schema' => self::SCHEMA,
            'surface' => 'reader_model',
            'source' => $source,
            'known_source' => true,
            'verdict' => self::BINDING_RESOLVED,
            'binding' => $binding,
            'deduped_against_repo' => $dedupe,
            'reasons' => $reasons,
        ];
    }

    // ---------------------------------------------------------------------
    // 2. Missing Source
    // ---------------------------------------------------------------------

    /**
     * Resolve a referenced source into a render decision.
     *
     * "Never render a missing file as healthy truth." A piece resolves healthy
     * ONLY when its source file exists and is not stale; otherwise it renders
     * `missing_source` with the open action disabled (it would lie), the expected
     * path surfaced, last cached content shown if available, and a documentation
     * drift evidence required.
     *
     * @param array<string,mixed> $piece
     *        exists          : bool    the referenced file is present (default false)
     *        stale           : bool    present but out of date (default false)
     *        expected_path   : string  the source-relative path the piece points to
     *        cached_content  : ?string last known cached body, if any
     *
     * @return array<string,mixed>
     */
    public function resolveSource(array $piece): array
    {
        $exists = (bool) ($piece['exists'] ?? false);
        $stale = (bool) ($piece['stale'] ?? false);
        $expectedPath = is_string($piece['expected_path'] ?? null) ? trim((string) $piece['expected_path']) : '';
        $hasCache = array_key_exists('cached_content', $piece)
            && is_string($piece['cached_content'])
            && trim((string) $piece['cached_content']) !== '';

        $reasons = [];

        // Healthy ONLY when present and fresh.
        if ($exists && ! $stale) {
            $reasons[] = 'source_present_and_fresh';

            return [
                'schema' => self::SCHEMA,
                'surface' => 'missing_source',
                'resolution' => self::RESOLUTION_HEALTHY,
                'render_missing_source' => false,
                'open_source_enabled' => true,
                'expected_path' => $expectedPath === '' ? null : $expectedPath,
                'show_cached_content' => false,
                'emit_drift_evidence' => false,
                'allow_inbox_repair' => false,
                'reasons' => $reasons,
            ];
        }

        // Missing or stale => drift state. Open is disabled because opening would
        // lie about a file that is absent or out of date.
        $reasons[] = $exists ? 'source_present_but_stale' : 'source_missing';
        if ($hasCache) {
            $reasons[] = 'last_known_cached_content_available';
        }

        return [
            'schema' => self::SCHEMA,
            'surface' => 'missing_source',
            'resolution' => self::RESOLUTION_MISSING_SOURCE,
            'render_missing_source' => true,
            'open_source_enabled' => false,
            'expected_path' => $expectedPath === '' ? null : $expectedPath,
            'show_cached_content' => $hasCache,
            'emit_drift_evidence' => true,
            'allow_inbox_repair' => true,
            'reasons' => $reasons,
        ];
    }

    // ---------------------------------------------------------------------
    // 3. Migration Phases
    // ---------------------------------------------------------------------

    /**
     * Decide whether "Phase 6: Deprecate Hardcoded Data" may run.
     *
     * Inline mockup arrays (`pipeline`, `regions`, `otherWorlds`) may be removed
     * ONLY after the graph API is the source of graph data: that requires the
     * backend readers / merged graph API (Phase 2) AND frontend JSON consumption
     * (Phase 3) to be done. Otherwise the mockup would have no data source and is
     * blocked.
     *
     * @param array<int|string,mixed> $donePhases list of completed phase indices
     *
     * @return array<string,mixed>
     */
    public function canDeprecateHardcoded(array $donePhases): array
    {
        $done = $this->normalizePhaseSet($donePhases);

        $missing = [];
        foreach (self::DEPRECATE_HARDCODED_PREREQUISITES as $required) {
            if (! in_array($required, $done, true)) {
                $missing[] = $required;
            }
        }

        $allowed = $missing === [];
        $reasons = [];
        if ($allowed) {
            $reasons[] = 'graph_api_is_source_of_data';
        } else {
            foreach ($missing as $m) {
                $reasons[] = 'requires_phase:' . $m . ':' . (self::MIGRATION_PHASES[$m] ?? 'unknown');
            }
        }

        return [
            'schema' => self::SCHEMA,
            'surface' => 'migration_phases',
            'phase' => 6,
            'phase_name' => self::MIGRATION_PHASES[6],
            'verdict' => $allowed ? self::PHASE_ALLOWED : self::PHASE_BLOCKED,
            'allowed' => $allowed,
            'required_phases' => self::DEPRECATE_HARDCODED_PREREQUISITES,
            'missing_phases' => array_values($missing),
            'reasons' => $reasons,
        ];
    }

    /**
     * Return the next migration phase to run given the completed phases. Phases
     * are strictly ordered 0..6; the next phase is the lowest index not yet done.
     * Returns null when every phase is complete.
     *
     * @param array<int|string,mixed> $donePhases
     *
     * @return array<string,mixed>
     */
    public function nextPhase(array $donePhases): array
    {
        $done = $this->normalizePhaseSet($donePhases);

        $next = null;
        foreach (array_keys(self::MIGRATION_PHASES) as $idx) {
            if (! in_array($idx, $done, true)) {
                $next = $idx;
                break;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'surface' => 'migration_phases',
            'next_phase' => $next,
            'next_phase_name' => $next === null ? null : self::MIGRATION_PHASES[$next],
            'complete' => $next === null,
            'done_phases' => $done,
        ];
    }

    // ---------------------------------------------------------------------
    // 4. Live Documentation Flow
    // ---------------------------------------------------------------------

    /**
     * Resolve the next live-documentation flow step given the current 1-based
     * step. The flow is a fixed 7-step sequence; step 1 follows a doc edit and
     * step 7 (gap -> revision request / Inbox) is terminal.
     *
     * @param int $currentStep 1-based step just completed (0 => before the flow)
     *
     * @return array<string,mixed>
     */
    public function liveDocFlowStep(int $currentStep): array
    {
        $total = count(self::LIVE_DOC_FLOW);
        $current = max(0, min($currentStep, $total));
        $next = $current + 1;
        $isTerminal = $current >= $total;

        return [
            'schema' => self::SCHEMA,
            'surface' => 'live_documentation_flow',
            'current_step' => $current === 0 ? null : $current,
            'current_step_name' => ($current >= 1 && $current <= $total) ? self::LIVE_DOC_FLOW[$current] : null,
            'next_step' => $isTerminal ? null : $next,
            'next_step_name' => $isTerminal ? null : self::LIVE_DOC_FLOW[$next],
            'terminal' => $isTerminal,
            'total_steps' => $total,
            // The runbook's invariant: the AI is the operator, never source truth.
            'ai_is_source_truth' => false,
        ];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Coerce a loose list of phase markers into a sorted, unique list of valid
     * phase indices (0..6). Strings of digits are accepted; out-of-range and
     * non-numeric values are dropped.
     *
     * @param array<int|string,mixed> $phases
     *
     * @return list<int>
     */
    private function normalizePhaseSet(array $phases): array
    {
        $valid = array_keys(self::MIGRATION_PHASES);
        $out = [];
        foreach ($phases as $p) {
            if (is_int($p)) {
                $idx = $p;
            } elseif (is_string($p) && ctype_digit(trim($p))) {
                $idx = (int) trim($p);
            } else {
                continue;
            }
            if (in_array($idx, $valid, true) && ! in_array($idx, $out, true)) {
                $out[] = $idx;
            }
        }
        sort($out);

        return $out;
    }
}
