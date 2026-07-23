<?php

namespace App\Services\Ai\OpenBrainMcp;

use App\Models\AtlasAurgNode;
use App\Services\Ai\AtlasAobgBlackboardService;
use App\Services\Ai\AtlasAobgWorkspaceOnboardingService;
use App\Services\Ai\AtlasOpenBrainWriteBackService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GOD-DEBULK FASE C: workspace / blackboard / mission-history / obra-status tool
 * family extracted verbatim from AtlasOpenBrainMcpService. Covers the write-back
 * adapters (atlas_record_outcome / atlas_propose_learning — governed, never
 * auto-promote), the multi-project workspace tools (status/map/fleet_map/activate),
 * the multi-agent blackboard (atlas_claim_task / atlas_blackboard_status) and the
 * read-only reality-graph history reports (atlas_mission_history / atlas_obra_status).
 * Bodies byte-identical to the pre-split service; the façade delegates here.
 */
class WorkspaceTools
{
    public function __construct(
        private readonly AtlasOpenBrainWriteBackService $writeBack,
        private readonly AtlasAobgWorkspaceOnboardingService $workspaceOnboarding,
        private readonly AtlasAobgBlackboardService $blackboard,
    ) {}
    /**
     * AOBG N1.F2 — the record_outcome WRITE-BACK tool. An external session records WHAT
     * IT DID back into the brain (provider-safe mission/evidence node, never a merge,
     * idempotent, fail-open). Input is UNTRUSTED; the governed write-back service applies
     * the hostile-input floor (provider-safety + size caps) before delegating to the
     * existing recorder, and writes an audit receipt. This handler is a thin adapter.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function recordOutcome(array $arguments): array
    {
        $tool = 'atlas_record_outcome';
        if ($this->string($arguments['id'] ?? null) === null || $this->string($arguments['request'] ?? null) === null) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'id_and_request_required'];
        }

        return ['tool' => $tool] + $this->writeBack->recordOutcome($arguments);
    }

    /**
     * AOBG N1.F2 — the propose_learning WRITE-BACK tool. An external session proposes a
     * learning/decision back into the brain. Input is UNTRUSTED; the governed write-back
     * service runs the canonical capture quality gate + provider-safety and lands a
     * PROPOSAL status=pending_review — NEVER auto-promotes, NEVER mutates canonical
     * memory. Returns proposal_id + status. This handler is a thin adapter.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function proposeLearning(array $arguments): array
    {
        $tool = 'atlas_propose_learning';
        if ($this->string($arguments['kind'] ?? null) === null || $this->string($arguments['summary'] ?? null) === null) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'kind_and_summary_required'];
        }

        return ['tool' => $tool] + $this->writeBack->proposeLearning($arguments);
    }

    /**
     * AOBG N1.F3 — the atlas_workspace_status tool. Multi-project: given the caller's
     * `cwd` (the external tool's project dir) OR an explicit `workspace`, resolve the
     * workspace id and answer HONESTLY what the brain knows about THIS project
     * ({workspace_id, indexed, symbols, last_index, needs_onboarding}). Scoped to that
     * workspace ONLY (no cross-project leak). Read-only, local DB, zero provider spend.
     * Auto-onboarding stays GATED in the service — this read tool only ever reports +
     * offers; it never triggers a heavy index. Thin adapter.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function workspaceStatus(array $arguments): array
    {
        $tool = 'atlas_workspace_status';

        $opts = [];
        $cwd = $this->string($arguments['cwd'] ?? null);
        if ($cwd !== null) {
            $opts['cwd'] = $cwd;
        }
        $workspace = $this->string($arguments['workspace'] ?? null);
        if ($workspace !== null) {
            $opts['workspace'] = $workspace;
        } elseif ($cwd === null) {
            $defaultWorkspace = $this->workspace(null);
            if ($defaultWorkspace !== null) {
                $opts['workspace'] = $defaultWorkspace;
            }
        }

        $status = $this->workspaceOnboarding->status($opts);

        return ['ok' => true, 'tool' => $tool] + $status;
    }

    /**
     * AOBG workspace map: bounded inventory of the already-indexed Code Intelligence
     * read-model for one workspace. Read-only; does not reindex.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function workspaceMap(array $arguments): array
    {
        $tool = 'atlas_workspace_map';

        $opts = [];
        $cwd = $this->string($arguments['cwd'] ?? null);
        if ($cwd !== null) {
            $opts['cwd'] = $cwd;
        }
        $workspace = $this->string($arguments['workspace'] ?? null);
        if ($workspace !== null) {
            $opts['workspace'] = $workspace;
        } elseif ($cwd === null) {
            $defaultWorkspace = $this->workspace(null);
            if ($defaultWorkspace !== null) {
                $opts['workspace'] = $defaultWorkspace;
            }
        }
        $limit = $this->positiveInt($arguments['limit'] ?? null);
        if ($limit !== null) {
            $opts['limit'] = $limit;
        }
        $detail = $this->string($arguments['detail'] ?? null);
        if ($detail !== null) {
            $opts['detail'] = $detail;
        }

        return ['tool' => $tool] + $this->workspaceOnboarding->map($opts);
    }

    /**
     * AOBG workspace fleet map: compact readiness inventory for every configured
     * workspace. Read-only; does not reindex and defers samples to atlas_workspace_map.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function workspaceFleetMap(array $arguments): array
    {
        $tool = 'atlas_workspace_fleet_map';

        $opts = [];
        $limit = $this->positiveInt($arguments['limit'] ?? null);
        if ($limit !== null) {
            $opts['limit'] = $limit;
        }
        $detail = $this->string($arguments['detail'] ?? null);
        if ($detail !== null) {
            $opts['detail'] = $detail;
        }

        return ['tool' => $tool] + $this->workspaceOnboarding->mapAll($opts);
    }

    /**
     * AOBG workspace activation: explicit local bootstrap + index for a newly opened
     * folder. This writes provider bootstrap files and may run the local CodeGraph
     * index, so it is not a read-only tool. It still spends zero provider tokens.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function workspaceActivate(array $arguments): array
    {
        $tool = 'atlas_workspace_activate';

        $opts = [];
        $cwd = $this->string($arguments['cwd'] ?? null);
        if ($cwd !== null) {
            $opts['cwd'] = $cwd;
        }
        $workspace = $this->string($arguments['workspace'] ?? null);
        if ($workspace !== null) {
            $opts['workspace'] = $workspace;
        } elseif ($cwd === null) {
            $defaultWorkspace = $this->workspace(null);
            if ($defaultWorkspace !== null) {
                $opts['workspace'] = $defaultWorkspace;
            }
        }
        $force = $arguments['force'] ?? null;
        if (is_bool($force)) {
            $opts['force'] = $force;
        }

        return ['tool' => $tool] + $this->workspaceOnboarding->activate($opts);
    }

    /**
     * AOBG N2.F4 — the atlas_claim_task tool. The BLACKBOARD: an engine (Claude Code /
     * Codex / Cursor, all MCP) CLAIMS a target (file path or task ref) so a second
     * engine can see "codex is editing fileX" and step around it. Idempotent + conflict-
     * aware + TTL-expiring + fail-open. When `release` is present it RELEASES that claim
     * id instead. Input is untrusted; the service normalises + caps everything and never
     * throws. Provider-safe, local DB only, zero provider spend. Thin adapter.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function claimTask(array $arguments): array
    {
        $tool = 'atlas_claim_task';

        // RELEASE path: an explicit claim id to free (the engine is done with it).
        $release = $this->string($arguments['release'] ?? null);
        if ($release !== null) {
            return ['tool' => $tool] + $this->blackboard->release($release);
        }

        $engine = $this->string($arguments['engine'] ?? null);
        $target = $this->string($arguments['target'] ?? null);
        if ($engine === null || $target === null) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'engine_and_target_required'];
        }

        $kind = $this->string($arguments['kind'] ?? null) ?? 'file';

        $opts = [];
        if (is_numeric($arguments['ttl'] ?? null)) {
            $opts['ttl'] = (int) $arguments['ttl'];
        }
        $workspace = $this->string($arguments['workspace'] ?? null);
        if ($workspace !== null) {
            $opts['workspace'] = $workspace;
        }
        $cwd = $this->string($arguments['cwd'] ?? null);
        if ($cwd !== null) {
            $opts['cwd'] = $cwd;
        }
        if (is_array($arguments['meta'] ?? null)) {
            $opts['meta'] = $arguments['meta'];
        }

        return ['tool' => $tool] + $this->blackboard->claim($engine, $kind, $target, $opts);
    }

    /**
     * AOBG N2.F4 — the atlas_blackboard_status tool. Reads the BLACKBOARD: the ACTIVE
     * work claims for THIS workspace (stale ones expired by TTL on read). With `target`
     * it answers "who else is editing this?" (the cross-engine conflict read); with
     * `except_engine` it excludes the asker's own claim. Read-only, workspace-scoped,
     * provider-safe, local DB only, zero provider spend. Fail-open to an empty list.
     * Thin adapter.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function blackboardStatus(array $arguments): array
    {
        $tool = 'atlas_blackboard_status';

        $opts = [];
        $workspace = $this->string($arguments['workspace'] ?? null);
        if ($workspace !== null) {
            $opts['workspace'] = $workspace;
        }
        $cwd = $this->string($arguments['cwd'] ?? null);
        if ($cwd !== null) {
            $opts['cwd'] = $cwd;
        }

        $target = $this->string($arguments['target'] ?? null);
        if ($target !== null) {
            $except = $this->string($arguments['except_engine'] ?? null);
            if ($except !== null) {
                $opts['except_engine'] = $except;
            }

            return ['ok' => true, 'tool' => $tool] + $this->blackboard->conflictsFor($target, $opts);
        }

        return ['ok' => true, 'tool' => $tool] + $this->blackboard->active($opts);
    }

    /**
     * Salto-2 F3 — the CLOSED MISSION LOOP read surface. Lists the most recent
     * missions Atlas delivered by reading the 'mission' source_kind nodes out of the
     * AURG fused store, each paired with its evidence node (status) and its branch
     * ref. Read-only.
     *
     * provider_bound is FORCED on this surface (MCP output can land in a provider
     * prompt): only provider_safe && !sensitive mission nodes are returned. The
     * recorded outcome is the BRANCH (atlas/materialize/<id>) — never a merge; only
     * ids/hashes/labels/branch/paths ride out (the recorder stored nothing else).
     *
     * No deliver tool is exposed via MCP — delivering spends + writes, so it stays on
     * the CLI (atlas:mission:deliver). This tool is the after-the-fact "what did the
     * loop do, and did it feed the brain back?" view.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function missionHistory(array $arguments): array
    {
        $tool = 'atlas_mission_history';
        if (! (bool) config('atlas.aurg.enabled', true)) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'aurg_disabled'];
        }
        if (! Schema::hasTable('atlas_aurg_nodes')) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'store_missing'];
        }

        $limit = $this->positiveInt($arguments['limit'] ?? null) ?? 20;
        $limit = min($limit, 100);

        // Mission nodes — provider-bound (structural; never relaxable via MCP), most
        // recent first. Evidence nodes share the source_id, so we load them keyed by
        // mission id to pair the status without an N+1 per row.
        $missions = AtlasAurgNode::query()
            ->where('source_kind', 'mission')
            ->where('kind', 'mission')
            ->where('provider_safe', true)
            ->where('sensitive', false)
            ->orderByDesc('updated_at')
            ->orderByDesc('source_id')
            ->limit($limit)
            ->get();

        $missionIds = $missions->pluck('source_id')->all();

        $evidenceByMission = [];
        if ($missionIds !== []) {
            foreach (
                AtlasAurgNode::query()
                    ->where('source_kind', 'mission')
                    ->where('kind', 'evidence')
                    ->where('provider_safe', true)
                    ->where('sensitive', false)
                    ->whereIn('source_id', $missionIds)
                    ->get() as $evidence
            ) {
                $evidenceByMission[(string) $evidence->source_id] = $evidence;
            }
        }

        $rows = [];
        foreach ($missions as $mission) {
            $meta = (array) ($mission->meta ?? []);
            $missionSourceId = (string) $mission->source_id;
            $evidence = $evidenceByMission[$missionSourceId] ?? null;
            $evidenceMeta = $evidence !== null ? (array) ($evidence->meta ?? []) : [];

            $rows[] = [
                'mission_id' => $missionSourceId,
                'node_id' => (string) $mission->id,
                // Already-redacted label (the recorder redacts the request downstream).
                'request' => (string) $mission->label,
                'branch' => is_string($meta['branch'] ?? null) ? $meta['branch'] : null,
                'delivered' => (bool) ($meta['delivered'] ?? false),
                'provider' => is_string($meta['provider'] ?? null) ? $meta['provider'] : null,
                // The evidence node's status (passed/failed/delivered/blocked); honest
                // 'unrecorded' when no evidence node exists for this mission.
                'status' => is_string($evidenceMeta['status'] ?? null) ? $evidenceMeta['status'] : 'unrecorded',
                'never_merged' => (bool) ($meta['never_merged'] ?? true),
                'touched_paths' => array_values(array_filter((array) ($meta['touched_paths'] ?? []), 'is_string')),
                'recorded_at' => $mission->updated_at?->toJSON(),
            ];
        }

        return [
            'ok' => true,
            'tool' => $tool,
            'provider_bound' => true,
            'count' => count($rows),
            'missions' => $rows,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * AOBG N3.F4 — the read surface for the OPERATOR SURFACE (atlas_obra_status MCP).
     *
     * THE INVERSION's after-the-fact view: lists the OBRAS the Atlas commissioned —
     * reads the AURG 'obra' nodes (the brain's first-class unit-of-work, recorded by
     * {@see AtlasRealityGraphIngestionService::recordObraOutcome()})
     * each paired with its 'evidence' node (the integrated-certification verdict) and
     * the BRANCH ref (atlas/obra/<id>) — never a merge.
     *
     * PROVIDER-BOUND is FORCED (structural, never relaxable via MCP; the output can land
     * in a provider prompt): only provider_safe && !sensitive obra nodes are returned.
     * Only ids/labels/branch/flags/counts/hashes ride out (the recorder stored nothing
     * else — never source, never diffs).
     *
     * No deliver tool is exposed via MCP — commissioning an obra SPENDS + writes, so it
     * stays on the CLI (atlas:obra:deliver). This tool is the "what obras did Atlas
     * build, and did the outcome feed the brain back?" view (compounding).
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function obraStatus(array $arguments): array
    {
        $tool = 'atlas_obra_status';
        if (! (bool) config('atlas.aurg.enabled', true)) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'aurg_disabled'];
        }
        if (! Schema::hasTable('atlas_aurg_nodes')) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'store_missing'];
        }

        $limit = $this->positiveInt($arguments['limit'] ?? null) ?? 20;
        $limit = min($limit, 100);

        // Obra nodes — provider-bound (structural; never relaxable via MCP), most recent
        // first. Evidence nodes share the source_id, so we load them keyed by obra id to
        // pair the certification verdict without an N+1 per row.
        $obras = AtlasAurgNode::query()
            ->where('source_kind', 'obra')
            ->where('kind', 'obra')
            ->where('provider_safe', true)
            ->where('sensitive', false)
            ->orderByDesc('updated_at')
            ->orderByDesc('source_id')
            ->limit($limit)
            ->get();

        $obraIds = $obras->pluck('source_id')->all();

        $evidenceByObra = [];
        if ($obraIds !== []) {
            foreach (
                AtlasAurgNode::query()
                    ->where('source_kind', 'obra')
                    ->where('kind', 'evidence')
                    ->where('provider_safe', true)
                    ->where('sensitive', false)
                    ->whereIn('source_id', $obraIds)
                    ->get() as $evidence
            ) {
                $evidenceByObra[(string) $evidence->source_id] = $evidence;
            }
        }

        $rows = [];
        foreach ($obras as $obra) {
            $meta = (array) ($obra->meta ?? []);
            $obraSourceId = (string) $obra->source_id;
            $evidence = $evidenceByObra[$obraSourceId] ?? null;
            $evidenceMeta = $evidence !== null ? (array) ($evidence->meta ?? []) : [];

            $rows[] = [
                'obra_id' => $obraSourceId,
                'node_id' => (string) $obra->id,
                // Already-redacted label (the recorder redacts the intent downstream).
                'intent' => (string) $obra->label,
                'branch' => is_string($meta['branch'] ?? null) ? $meta['branch'] : null,
                'certified' => (bool) ($meta['certified'] ?? false),
                // The obra's honest whole-status (certified/needs_review); the evidence
                // node's status is the integrated-check verdict (passed/failed/unrunnable/
                // absent). Honest 'unrecorded' when no evidence node exists.
                'status' => is_string($meta['status'] ?? null) ? $meta['status'] : 'unrecorded',
                'integrated_status' => is_string($evidenceMeta['status'] ?? null) ? $evidenceMeta['status'] : 'unrecorded',
                'delivered_steps' => (int) ($meta['delivered_steps'] ?? 0),
                'total_steps' => (int) ($meta['total_steps'] ?? 0),
                'never_merged' => (bool) ($meta['never_merged'] ?? true),
                'recorded_at' => $obra->updated_at?->toJSON(),
            ];
        }

        return [
            'ok' => true,
            'tool' => $tool,
            'provider_bound' => true,
            'count' => count($rows),
            'obras' => $rows,
            'generated_at' => now()->toJSON(),
        ];
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function workspace(mixed $workspace): ?string
    {
        $workspace = $this->string($workspace) ?: (config('atlas.ai.workdir') ?: null);
        if ($workspace === null) {
            return base_path();
        }

        return realpath($workspace) ?: $workspace;
    }
}
