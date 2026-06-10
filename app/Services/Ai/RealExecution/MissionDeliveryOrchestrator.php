<?php

declare(strict_types=1);

namespace App\Services\Ai\RealExecution;

use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use Throwable;

/**
 * Mission e2e — "Atlas delivers from natural language", end to end.
 *
 * Chains the pieces built across this program into one governed flow:
 *
 *   request (natural language)
 *     → [S2.F1] BRAIN context  (AURG provider-bound unified context with
 *        provenance threaded into the code-gen prompt; flag-gated; fail-open)
 *     → AtlasLiveCodeDeliveryService  (provider generates code WITH the wired
 *        code-graph auto-context; isolated sandbox; php -l / self-test; CERTIFIED)
 *     → diff (built from the certified sandbox files)
 *     → GovernedBranchMaterializationService  (real branch; NEVER main; gated)
 *     → [S2.F1] OUTCOME recorded back INTO the brain  (mission node + generated
 *        edge to the branch + evidence node + references to touched modules /
 *        memories; fail-open) so the NEXT mission's brain query sees this one
 *     → ready-to-merge artifact (branch + diff + review commands)
 *
 * The operator merges (their sovereignty). This orchestrator stops at the branch:
 * it never merges, never pushes, never touches main. Delivery's own certification
 * is the gate credential handed to the materializer (no self-certification beyond
 * what php -l / the verifier already proved).
 *
 * S2.F1 — THE CLOSED MISSION LOOP (cognition fused with execution):
 *   - the brain query is ALWAYS provider_bound (it is threaded into a provider
 *     prompt) — sensitive/secret domains are excluded by construction, and the
 *     section is only added when atlas.mission.brain_context_enabled is true;
 *   - the recorded outcome is the BRANCH ref, NEVER a merge — and carries
 *     ids/hashes/labels only, no source code, no diffs;
 *   - BOTH bridges are fail-open: a brain outage degrades the loop (no context /
 *     no recording) but NEVER breaks a delivery.
 */
class MissionDeliveryOrchestrator
{
    public const SCHEMA = 'atlas.ai.mission_delivery.v1';

    public function __construct(
        private readonly AtlasLiveCodeDeliveryService $delivery,
        private readonly GovernedBranchMaterializationService $materializer,
        private readonly ?AtlasRealityGraphQueryService $brainQuery = null,
        private readonly ?AtlasRealityGraphIngestionService $brainIngestion = null,
        // S2.F2 — the dedicated "hands feed the brain" recorder. Preferred over the
        // raw ingestion service when both are present (it owns the delivery→outcome
        // shaping + its own fail-open boundary). Nullable so `new` in tests stays
        // backward-compatible; falls back to a recorder wrapping $brainIngestion.
        private readonly ?AtlasMissionOutcomeRecorder $outcomeRecorder = null,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function deliver(string $request, array $options = []): array
    {
        $request = trim($request);
        if ($request === '') {
            return $this->blocked('request_required', 'request');
        }

        // STAGE 0 — BRAIN context (S2.F1). Flag-gated, ALWAYS provider_bound,
        // fail-open: a brain outage or a disabled flag simply yields no context
        // and the delivery proceeds exactly as before. Threaded into the
        // code-gen prompt as provenance-cited reference material.
        $brainContext = $this->brainContextFor($request);
        if ($brainContext !== '') {
            $options['brain_context'] = $brainContext;
        }

        // STAGE 1 — request → certified code (real provider, isolated sandbox, php -l).
        $delivery = $this->delivery->deliver($request, $options);
        if (($delivery['status'] ?? '') !== AtlasLiveCodeDeliveryService::STATUS_CERTIFIED) {
            return $this->blocked((string) ($delivery['reason'] ?? 'delivery_not_certified'), 'delivery', ['delivery' => $delivery]);
        }

        // STAGE 2 — certified sandbox files → {path, content} (git computes modify-vs-new).
        $contentFiles = $this->filesFromDelivery($delivery);
        if ($contentFiles === []) {
            return $this->blocked('no_files_from_delivery', 'files', ['delivery' => $delivery]);
        }

        // STAGE 3 — the certification IS the gate credential (sha256 over the proven facts).
        $files = array_values(array_filter(array_map(
            static fn ($f): string => (string) ($f['path'] ?? ''),
            (array) ($delivery['files'] ?? []),
        )));
        $id = (string) ($options['id'] ?? ('mission-'.substr(hash('sha256', $request), 0, 10)));
        $receipt = hash('sha256', (string) json_encode([
            'certified' => true, 'request' => $request, 'files' => $files,
            'provider' => $delivery['provider'] ?? null, 'syntax' => $delivery['syntax_check'] ?? null,
        ], JSON_UNESCAPED_SLASHES));

        // STAGE 4 — materialize to a real branch (NEVER main; gated; reversible).
        $materialization = $this->materializer->materialize([
            'id' => $id,
            'files' => $contentFiles,
            'repo_dir' => (string) ($options['repo_dir'] ?? base_path()),
            'certified' => true,
            'gate_receipt' => $receipt,
            'measure_cmd' => isset($options['measure_cmd']) ? (string) $options['measure_cmd'] : null,
        ]);

        $delivered = (bool) ($materialization['materialized'] ?? false);
        $branch = $materialization['branch'] ?? null;

        // STAGE 5 — OUTCOME recorded back INTO the brain (S2.F1). Fail-open: a
        // recording failure NEVER changes the delivery result. Branch ref only —
        // never a merge; ids/hashes/labels/paths only — never source code, never
        // the measure's output_tail (only its pass/fail boolean rides the brain).
        $measure = (array) ($materialization['measure'] ?? []);
        $brainOutcome = $this->recordOutcome([
            'id' => $id,
            'request' => $request,
            'branch' => is_string($branch) ? $branch : '',
            'delivered' => $delivered,
            'provider' => is_string($delivery['provider'] ?? null) ? $delivery['provider'] : null,
            'receipt' => $receipt,
            'files' => $files,
            // Map the materializer's measure to a payload-free pass/fail signal.
            'measure' => array_key_exists('passed', $measure) ? ['ok' => (bool) $measure['passed']] : [],
            'memory_refs' => array_values(array_filter((array) ($options['memory_refs'] ?? []), 'is_string')),
        ]);

        return [
            'schema_version' => self::SCHEMA,
            'delivered' => $delivered,
            'stage' => 'complete',
            'request' => $request,
            'delivery' => [
                'certified' => true,
                'provider' => $delivery['provider'] ?? null,
                'files' => $files,
            ],
            'materialization' => $materialization,
            'branch' => $branch,
            'main_untouched' => (bool) ($materialization['main_untouched'] ?? true),
            'never_merged' => true,
            'review_commands' => $materialization['review_commands'] ?? [],
            'brain' => [
                'context_used' => $brainContext !== '',
                'outcome_recorded' => (bool) ($brainOutcome['recorded'] ?? false),
                'mission_node' => $brainOutcome['mission_node'] ?? null,
            ],
        ];
    }

    /**
     * S2.F1 brain query → formatted, provenance-cited context string. ALWAYS
     * provider_bound (it rides a provider prompt). Flag-gated and FAIL-OPEN:
     * returns '' when the flag is off, the brain is unavailable, or the query
     * throws — the delivery then proceeds with a byte-identical prompt.
     */
    private function brainContextFor(string $request): string
    {
        if (! (bool) config('atlas.mission.brain_context_enabled', false)) {
            return '';
        }
        if (! $this->brainQuery instanceof AtlasRealityGraphQueryService) {
            return '';
        }

        try {
            $maxPaths = max(1, (int) config('atlas.mission.brain_context_paths', 6));
            $result = $this->brainQuery->query($request, ['provider_bound' => true]);

            return $this->formatBrainContext((array) ($result['paths'] ?? []), (array) ($result['nodes'] ?? []), $maxPaths);
        } catch (Throwable) {
            // Honest degrade — no context, never a broken delivery.
            return '';
        }
    }

    /**
     * Render the top AURG paths as human-readable cross-layer chains with [src=]
     * provenance. Deterministic over the query result's own order; bounded.
     *
     * @param  list<array<string,mixed>>  $paths
     * @param  list<array<string,mixed>>  $nodes
     */
    private function formatBrainContext(array $paths, array $nodes, int $maxPaths): string
    {
        // Label/kind/source by node id, for readable chains.
        $byId = [];
        foreach ($nodes as $node) {
            $id = (string) ($node['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $node;
            }
        }

        $lines = [];
        foreach (array_slice($paths, 0, $maxPaths) as $path) {
            $chain = array_values(array_filter((array) ($path['nodes'] ?? []), 'is_string'));
            if ($chain === []) {
                continue;
            }
            $rendered = [];
            $sources = [];
            foreach ($chain as $nodeId) {
                $node = $byId[$nodeId] ?? null;
                $label = $node !== null ? (string) ($node['label'] ?? $nodeId) : $nodeId;
                $kind = $node !== null ? (string) ($node['kind'] ?? '') : '';
                $rendered[] = $kind !== '' ? $label.' ('.$kind.')' : $label;
                if ($node !== null && is_string($node['source_kind'] ?? null) && $node['source_kind'] !== '') {
                    $sources[(string) $node['source_kind']] = true;
                }
            }
            $src = $sources !== [] ? ' [src='.implode(',', array_keys($sources)).']' : '';
            $lines[] = '- '.implode(' → ', $rendered).$src;
        }

        return $lines === [] ? '' : implode("\n", $lines);
    }

    /**
     * S2.F1/F2 outcome write-back (EXECUTION feeds the BRAIN). FAIL-OPEN: any
     * failure returns a non-recorded marker and NEVER propagates — the delivery
     * result is unaffected. Gated by atlas.mission.record_outcome_enabled (default
     * on; only ever writes mission-source nodes/edges, never touches main).
     *
     * Prefers the dedicated S2.F2 {@see AtlasMissionOutcomeRecorder} (the named
     * "hands feed the brain" seam, which owns the flag + fail-open boundary). When
     * only the raw ingestion service was injected (legacy 4-arg construction), wraps
     * it in a recorder so there is ONE recording path regardless of how the
     * orchestrator was built.
     *
     * @param  array<string,mixed>  $outcome
     * @return array<string,mixed>
     */
    private function recordOutcome(array $outcome): array
    {
        $recorder = $this->outcomeRecorder
            ?? ($this->brainIngestion instanceof AtlasRealityGraphIngestionService
                ? new AtlasMissionOutcomeRecorder($this->brainIngestion)
                : null);

        if (! $recorder instanceof AtlasMissionOutcomeRecorder) {
            return ['recorded' => false, 'reason' => 'ingestion_unavailable'];
        }

        // The recorder is itself fail-open + flag-gated; this stays defence-in-depth.
        try {
            return $recorder->record($outcome);
        } catch (Throwable) {
            return ['recorded' => false, 'reason' => 'record_failed'];
        }
    }

    /**
     * @param  array<string,mixed>  $delivery
     * @return array<int,array{path:string,content:string}>
     */
    private function filesFromDelivery(array $delivery): array
    {
        $files = [];
        foreach ((array) ($delivery['files'] ?? []) as $f) {
            $path = (string) ($f['path'] ?? '');
            $sandboxPath = (string) ($f['sandbox_path'] ?? '');
            if ($path === '' || $sandboxPath === '' || ! is_file($sandboxPath)) {
                continue;
            }
            $files[] = ['path' => $path, 'content' => (string) @file_get_contents($sandboxPath)];
        }

        return $files;
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(string $reason, string $stage, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA,
            'delivered' => false,
            'stage' => $stage,
            'reason' => $reason,
            'branch' => null,
            'main_untouched' => true,
            'never_merged' => true,
        ], $extra);
    }
}
