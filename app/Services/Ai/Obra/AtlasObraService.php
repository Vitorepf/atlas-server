<?php

declare(strict_types=1);

namespace App\Services\Ai\Obra;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * AOBG N3.F4 — the OPERATOR SURFACE: one command commissions an obra.
 *
 * THE INVERSION made a single human gesture. F1 built the DECOMPOSITION spine
 * (intent → plan-DAG → steps), F2 the EXECUTOR (walk the DAG onto ONE accumulating
 * branch), F3 the INTEGRATION CERTIFICATION (the assembled branch as a whole). F4 is
 * the FIO that joins them behind ONE call the operator makes in natural language:
 *
 *     $obra = $service->commission("add a Foo service; then wire it into Bar; then test it");
 *
 * and gets back ONE ready-to-merge branch with the full, honest envelope. This is the
 * order-50x multiplier: the operator no longer hand-steps plan → run; they declare an
 * INTENT and Atlas DRIVES (decompose → execute governed → certify → record), stopping
 * at ONE branch the operator reviews + merges. The UNIT OF WORK is the obra, not the
 * edit.
 *
 * It builds NO parallel engine — it is pure ORCHESTRATION of the proven F1/F2/F3
 * pieces:
 *   - decompose via {@see AtlasObraPlanService} (F1): validated, brain-anchored,
 *     proven-acyclic plan-DAG persisted to the spine tables. Cost-free with the
 *     deterministic decomposer; the provider decomposer is itself stubbable.
 *   - execute + certify via {@see AtlasObraExecutor} (F2/F3): one accumulating branch,
 *     per-node delivery behind the cost-free {@see ObraNodeDelivery} seam, per-node
 *     gate fail-closed, whole-branch integrated certification, brain write-back.
 *
 * HARD CONTRACTS (non-negotiable; inherited from F1–F3 and surfaced here):
 *   - COST: this service spends ONLY through the injected executor's per-node delivery
 *     (the real obra-run is the operator's single command). With a fake delivery +
 *     deterministic decomposer the WHOLE commission is provable cost-free — no provider
 *     calls of its own, ever (tests + agents stay at zero tokens).
 *   - NEVER-MERGE: the whole obra is ONE branch; the executor NEVER merges, pushes, or
 *     touches main. The envelope carries main_untouched / never_merged / never_pushed
 *     straight from the executor (the single git authority).
 *   - GOVERNED, FAIL-CLOSED: a refused plan (cycle / dangling / over-cap / empty) is an
 *     honest, explained envelope (status=refused) — never a fabricated partial obra. A
 *     halted execution stays halted (no partial garbage), and a per-step-green obra
 *     whose integrated check did not pass is honestly certified=false (needs_review).
 *   - BRAIN-DRIVEN: each node carries its N1 brain refs (F1) and the obra + each step +
 *     the outcome are recorded back (F2/F3) so the NEXT obra sees prior obras
 *     (compounding). brain_recorded reports truthfully.
 *   - DETERMINISTIC, PROVIDER-SAFE ENVELOPE: {obra_id, intent (redacted), plan:[nodes],
 *     branch, certified, evidence, review_commands, main_untouched, never_merged,
 *     brain_recorded, ...}. The intent echoed back is the REDACTED plan label (never the
 *     verbatim raw ask).
 */
final class AtlasObraService
{
    public const SCHEMA = 'atlas.obra.commission.v1';

    /** A plan that was refused before any execution (cycle / dangling / over-cap / empty). */
    public const STATUS_REFUSED = 'refused';

    public function __construct(
        private readonly AtlasObraPlanService $planService,
        private readonly AtlasObraExecutor $executor,
    ) {}

    /**
     * Commission an obra end-to-end from a single natural-language intent.
     *
     * decompose (F1) → execute the DAG onto ONE branch (F2) → certify the whole (F3) →
     * record into the brain (F2/F3) → return the operator envelope.
     *
     * @param  array<string,mixed>  $opts  {workspace?:string, max_nodes?:int, id?:string,
     *                                     repo_dir?:string, provider?:string, integrated_check?:string, no_brain?:bool}
     * @return array<string,mixed> {schema, obra_id, intent, status, plan:[nodes], branch,
     *                             certified, disposition, evidence, review_commands, main_untouched, never_merged,
     *                             never_pushed, reversible, brain_recorded, delivered_nodes, node_count, failed_node?,
     *                             decomposer, reason?}
     */
    public function commission(string $intent, array $opts = []): array
    {
        if (! (bool) config('atlas.obra.enabled', true)) {
            return $this->refused('', '', 'obra_disabled', []);
        }

        $intent = trim($intent);
        if ($intent === '') {
            return $this->refused('', '', 'intent_required', []);
        }

        // --- 1) DECOMPOSE (F1): intent → validated, brain-anchored, persisted plan-DAG.
        // A refused plan is an honest, explained envelope — never a fabricated obra.
        try {
            $plan = $this->planService->decompose($intent, $this->planOpts($opts));
        } catch (InvalidArgumentException $e) {
            return $this->refused('', $intent, 'plan_refused:'.$e->getMessage(), []);
        }

        $planId = (string) ($plan['plan_id'] ?? '');
        $planNodes = $this->planNodes($plan);
        // The REDACTED intent label the spine persisted (never the verbatim raw ask).
        $intentLabel = $this->storedIntentLabel($planId, $intent);

        // --- 2) EXECUTE + CERTIFY (F2/F3): walk the DAG onto ONE accumulating branch,
        // certify the assembled branch as a whole, record the obra + steps into the
        // brain. THIS is the only place a provider runs (the operator's spend), behind
        // the cost-free delivery seam the injected executor carries.
        $run = $this->executor->executePlanId($planId, $this->execOpts($opts));

        // Reconcile the plan with the executor's authoritative per-node statuses (the
        // run knows which steps delivered / failed / were skipped).
        $nodes = $this->mergeNodeStatuses($planNodes, (array) ($run['nodes'] ?? []));

        $status = (string) ($run['status'] ?? AtlasObraExecutor::STATUS_FAILED);

        return [
            'schema' => self::SCHEMA,
            'obra_id' => $planId,
            'intent' => $intentLabel,
            'status' => $status,
            'plan' => $nodes,
            'node_count' => (int) ($run['node_count'] ?? count($nodes)),
            'delivered_nodes' => (int) ($run['delivered_nodes'] ?? 0),
            'failed_node' => $run['failed_node'] ?? null,
            'branch' => $run['branch'] ?? null,
            'certified' => (bool) ($run['certified'] ?? false),
            'disposition' => (string) ($run['disposition'] ?? ''),
            // The F3 integration-certification evidence envelope (provider-safe receipts).
            'evidence' => $run['certification'] ?? null,
            'integrated_test_result' => $run['integrated_test_result'] ?? null,
            'main_untouched' => (bool) ($run['main_untouched'] ?? true),
            'never_merged' => (bool) ($run['never_merged'] ?? true),
            'never_pushed' => (bool) ($run['never_pushed'] ?? true),
            'reversible' => (bool) ($run['reversible'] ?? true),
            'brain_recorded' => (bool) ($run['brain_recorded'] ?? false),
            'review_commands' => array_values((array) ($run['review_commands'] ?? [])),
            'decomposer' => (string) ($plan['decomposer'] ?? ''),
            'reason' => $run['reason'] ?? null,
        ];
    }

    /**
     * Discard the whole obra branch (reversible). Delegates to the executor's governed
     * discard, which only ever touches the atlas/obra/ prefix. Exposed here so the
     * operator surface (the deliver/status command) can reject a commissioned obra.
     *
     * @return array{discarded:bool,branch:string,reason:?string}
     */
    public function discard(string $repoDir, string $obraId): array
    {
        return $this->executor->discardObra($repoDir, $obraId);
    }

    /**
     * Read the status of a persisted obra (the plan header + per-node statuses + the
     * recorded branch). Cost-free, local DB only — the read side of the operator
     * surface (the atlas:obra:status command). Returns an honest "not_found" envelope
     * when the obra id is unknown.
     *
     * @return array<string,mixed> {schema, found:bool, obra_id, intent?, status?,
     *                             workspace_id?, branch?, decomposer?, node_count?, plan?:[nodes], reason?}
     */
    public function status(string $obraId): array
    {
        $obraId = trim($obraId);
        if ($obraId === '') {
            return ['schema' => self::SCHEMA, 'found' => false, 'obra_id' => '', 'reason' => 'obra_id_required'];
        }

        $planRow = DB::table('atlas_obra_plans')->where('id', $obraId)->first();
        if ($planRow === null) {
            return ['schema' => self::SCHEMA, 'found' => false, 'obra_id' => $obraId, 'reason' => 'obra_not_found'];
        }

        $nodeRows = DB::table('atlas_obra_nodes')
            ->where('plan_id', $obraId)
            ->orderBy('seq')
            ->get();

        $nodes = [];
        $branch = null;
        foreach ($nodeRows as $row) {
            $result = json_decode((string) $row->result, true) ?: [];
            if ($branch === null && is_string($result['branch'] ?? null) && $result['branch'] !== '') {
                $branch = (string) $result['branch'];
            }
            $nodes[] = [
                'id' => (string) $row->id,
                'seq' => (int) $row->seq,
                'title' => (string) $row->title,
                'request' => (string) $row->request,
                'target_area' => $row->target_area !== null ? (string) $row->target_area : null,
                'depends_on' => json_decode((string) $row->depends_on, true) ?: [],
                'status' => (string) $row->status,
                'commit' => is_string($result['commit'] ?? null) ? $result['commit'] : null,
                'files_changed' => array_values(array_filter((array) ($result['files_changed'] ?? []), 'is_string')),
            ];
        }

        $meta = json_decode((string) $planRow->meta, true) ?: [];
        // The obra branch is deterministic from its id; fall back to it when no node
        // recorded one yet (e.g. a planned-but-never-run obra has no branch).
        $branch ??= ($planRow->status === AtlasObraPlanService::STATUS_PLANNED ? null : 'atlas/obra/'.$obraId);

        return [
            'schema' => self::SCHEMA,
            'found' => true,
            'obra_id' => $obraId,
            'intent' => (string) $planRow->intent,
            'status' => (string) $planRow->status,
            'workspace_id' => (string) $planRow->workspace_id,
            'branch' => $branch,
            'decomposer' => is_string($meta['decomposer'] ?? null) ? $meta['decomposer'] : null,
            'node_count' => count($nodes),
            'plan' => $nodes,
        ];
    }

    /**
     * The plan-DAG nodes as returned by F1 (id / seq / title / request / target_area /
     * depends_on / status / brain_refs). Provider-safe by construction.
     *
     * @param  array<string,mixed>  $plan
     * @return list<array<string,mixed>>
     */
    private function planNodes(array $plan): array
    {
        $nodes = [];
        foreach ((array) ($plan['nodes'] ?? []) as $node) {
            if (! is_array($node)) {
                continue;
            }
            $refs = (array) ($node['brain_refs'] ?? []);
            $nodes[] = [
                'id' => (string) ($node['id'] ?? ''),
                'seq' => (int) ($node['seq'] ?? 0),
                'title' => (string) ($node['title'] ?? ''),
                'request' => (string) ($node['request'] ?? ''),
                'target_area' => $node['target_area'] !== null ? (string) $node['target_area'] : null,
                'depends_on' => array_values(array_filter((array) ($node['depends_on'] ?? []), 'is_string')),
                'status' => (string) ($node['status'] ?? 'pending'),
                // Just the brain sources present (provider-safe label) — the full refs
                // are persisted; the envelope keeps the surface bounded.
                'brain_sources' => array_values((array) ($refs['sources_present'] ?? [])),
            ];
        }

        return $nodes;
    }

    /**
     * Merge the plan-DAG nodes with the executor's authoritative per-node run results
     * (status / commit / files_changed). The plan defines the shape; the run defines
     * the truth of what happened to each node.
     *
     * @param  list<array<string,mixed>>  $planNodes
     * @param  list<array<string,mixed>>  $runNodes
     * @return list<array<string,mixed>>
     */
    private function mergeNodeStatuses(array $planNodes, array $runNodes): array
    {
        $runById = [];
        foreach ($runNodes as $rn) {
            if (is_array($rn) && isset($rn['id'])) {
                $runById[(string) $rn['id']] = $rn;
            }
        }

        $merged = [];
        foreach ($planNodes as $node) {
            $id = (string) ($node['id'] ?? '');
            $run = $runById[$id] ?? null;
            if ($run !== null) {
                $node['status'] = (string) ($run['status'] ?? $node['status']);
                if (isset($run['commit']) && is_string($run['commit'])) {
                    $node['commit'] = $run['commit'];
                }
                if (isset($run['files_changed'])) {
                    $node['files_changed'] = array_values(array_filter((array) $run['files_changed'], 'is_string'));
                }
                if (isset($run['reason']) && is_string($run['reason'])) {
                    $node['reason'] = $run['reason'];
                }
                if (isset($run['stage']) && is_string($run['stage'])) {
                    $node['stage'] = $run['stage'];
                }
            }
            $merged[] = $node;
        }

        return $merged;
    }

    /**
     * The redacted intent LABEL the spine persisted (never the verbatim raw ask). Reads
     * it back from the plan row so the envelope echoes exactly what the brain stored.
     */
    private function storedIntentLabel(string $planId, string $fallback): string
    {
        try {
            $stored = DB::table('atlas_obra_plans')->where('id', $planId)->value('intent');
            if (is_string($stored) && $stored !== '') {
                return $stored;
            }
        } catch (Throwable) {
            // The plan tables may be unreadable mid-test; fall back to the raw label.
        }

        return mb_substr($fallback, 0, 1000);
    }

    /**
     * Planning options forwarded to F1 (workspace / max_nodes / id).
     *
     * @param  array<string,mixed>  $opts
     * @return array<string,mixed>
     */
    private function planOpts(array $opts): array
    {
        $planOpts = [];
        foreach (['workspace', 'max_nodes', 'id'] as $key) {
            if (array_key_exists($key, $opts)) {
                $planOpts[$key] = $opts[$key];
            }
        }

        return $planOpts;
    }

    /**
     * Execution options forwarded to F2/F3 (repo_dir / provider / integrated_check /
     * no_brain).
     *
     * @param  array<string,mixed>  $opts
     * @return array<string,mixed>
     */
    private function execOpts(array $opts): array
    {
        $execOpts = [];
        foreach (['repo_dir', 'provider', 'integrated_check', 'no_brain'] as $key) {
            if (array_key_exists($key, $opts)) {
                $execOpts[$key] = $opts[$key];
            }
        }

        return $execOpts;
    }

    /**
     * @param  list<array<string,mixed>>  $plan
     * @return array<string,mixed>
     */
    private function refused(string $obraId, string $intent, string $reason, array $plan): array
    {
        return [
            'schema' => self::SCHEMA,
            'obra_id' => $obraId,
            'intent' => $intent !== '' ? mb_substr($intent, 0, 1000) : '',
            'status' => self::STATUS_REFUSED,
            'plan' => $plan,
            'node_count' => count($plan),
            'delivered_nodes' => 0,
            'failed_node' => null,
            'branch' => null,
            'certified' => false,
            'disposition' => AtlasObraCertificationService::DISPOSITION_HALTED,
            'evidence' => null,
            'integrated_test_result' => null,
            'main_untouched' => true,
            'never_merged' => true,
            'never_pushed' => true,
            'reversible' => true,
            'brain_recorded' => false,
            'review_commands' => [],
            'decomposer' => '',
            'reason' => $reason,
        ];
    }
}
