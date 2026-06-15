<?php

declare(strict_types=1);

namespace App\Services\Ai\Obra;

use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AOBG N3.F2 — THE OBRA EXECUTOR: run the plan-DAG into ONE accumulating branch.
 *
 * N3 is THE INVERSION. F1 built the DECOMPOSITION spine (intent → plan-DAG → steps,
 * persisted, brain-anchored, proven-acyclic). F2 is the missing FIO that EXECUTES
 * that spine: a topological walk of the plan's nodes, each node's certified change
 * applied onto the SAME accumulating obra branch (atlas/obra/<id>) in dependency
 * order — step N building on step N-1's state, all on ONE branch. This RAISES THE
 * UNIT OF WORK from edit → obra.
 *
 * It builds NO parallel engine — it CHAINS proven pieces:
 *   - per-node delivery is the proven mission STAGE-1 ("request → certified code"),
 *     behind the {@see ObraNodeDelivery} seam (provider in production, fake in tests —
 *     ZERO tokens in a test or an agent);
 *   - applying onto ONE branch is the {@see GovernedBranchMaterializationService}'s
 *     new OBRA-ACCUMULATE mode (openObra / applyStepToObra / closeObra), which
 *     preserves every sovereignty invariant (never main / push / merge; main proven
 *     untouched; reversible discardBranch for the WHOLE obra);
 *   - the obra + each step outcome are recorded back into the AURG brain via the
 *     proven {@see AtlasRealityGraphIngestionService::recordMissionOutcome()} so the
 *     NEXT obra sees prior obras (compounding).
 *
 * HARD CONTRACTS (non-negotiable):
 *   - COST: this service spends ONLY because the injected delivery's per-node step
 *     spends; in tests a fake delivery returns certified files per step (zero tokens).
 *     This class adds no provider calls of its own. Brain anchoring + outcome
 *     recording are local DB only.
 *   - NEVER-MERGE: the WHOLE obra is ONE branch; the loop NEVER merges, pushes, or
 *     touches main. Main HEAD + working tree are proven byte-identical at closeObra.
 *   - ONE BRANCH, MANY STEPS: every node's certified change is committed onto the
 *     SAME held worktree in topological order — NOT N branches.
 *   - GOVERNED, FAIL-CLOSED on certification: a node that does NOT certify (or whose
 *     apply / per-node gate fails) HALTS the obra — no further nodes run, the partial
 *     branch is KEPT for inspection but the obra is marked status=failed (never a
 *     silent partial pass).
 *   - FAIL-OPEN on brain: a brain-anchoring or outcome-recording outage degrades the
 *     run (no context / no recording) but NEVER breaks the obra.
 *   - ANTI-OVER-CLAIM: deterministic where possible; the result reports honestly
 *     (delivered / halted / which node failed / main_untouched / never_merged).
 */
final class AtlasObraExecutor
{
    public const SCHEMA = 'atlas.obra.executor.v1';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_HALTED = 'halted';

    // AOBG N3.F3 — steps all passed but the obra did NOT certify as a whole (the
    // integrated check failed / could not run / was absent). The branch is KEPT for
    // the operator to review; it is honestly NOT stamped green.
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const NODE_DONE = 'done';

    public const NODE_FAILED = 'failed';

    public const NODE_SKIPPED = 'skipped';

    public function __construct(
        private readonly ObraNodeDelivery $delivery,
        private readonly GovernedBranchMaterializationService $materializer,
        // FAIL-OPEN brain write-back (compounding). Nullable so a minimal executor
        // (and the cost-free tests) can run with no brain wiring — recording is skipped.
        private readonly ?AtlasRealityGraphIngestionService $brain = null,
        // GOVERNED per-node gate seam. Default null ⇒ the certification the delivery
        // already proved IS the gate (a certified step passes). A caller may inject a
        // stricter Forge gate; returning false HALTS the obra (fail-closed).
        private readonly ?ObraNodeGate $gate = null,
        // AOBG N3.F3 — the WHOLE-obra integration certification (pure assembly; no
        // IO). Default-constructed so the executor always certifies the assembled
        // branch as a unit, not just per-step.
        private readonly ?AtlasObraCertificationService $certification = null,
        // L4-10 — the EXECUTOR-SELF-STAMPED RUNTIME RECEIPT minter. Default-constructed
        // so every run emits a signed, executor-output receipt (provenance-hardened) the
        // L4-10 proof verifies — a hand-assembled file can no longer pass the strict proof.
        private readonly ?AtlasObraReceiptStamp $receiptStamp = null,
    ) {}

    /**
     * Execute a persisted plan by id: load its nodes, walk them onto ONE obra branch.
     * Thin convenience over {@see execute()} — reads the {@see AtlasObraPlanService}'s
     * tables (the F1 spine) so the operator's run command only needs the plan id.
     *
     * @param  array<string,mixed>  $opts  {repo_dir?, provider?, no_brain?:bool}
     * @return array<string,mixed> the {@see execute()} envelope
     */
    public function executePlanId(string $planId, array $opts = []): array
    {
        $planId = trim($planId);
        $planRow = DB::table('atlas_obra_plans')->where('id', $planId)->first();
        if ($planRow === null) {
            return $this->refused($planId, 'plan_not_found');
        }

        $nodeRows = DB::table('atlas_obra_nodes')
            ->where('plan_id', $planId)
            ->orderBy('seq')
            ->get();
        if ($nodeRows->isEmpty()) {
            return $this->refused($planId, 'plan_has_no_nodes');
        }

        $nodes = [];
        foreach ($nodeRows as $row) {
            $nodes[] = [
                'id' => (string) $row->id,
                'seq' => (int) $row->seq,
                'title' => (string) $row->title,
                'request' => (string) $row->request,
                'target_area' => $row->target_area !== null ? (string) $row->target_area : null,
                'depends_on' => json_decode((string) $row->depends_on, true) ?: [],
                'brain_refs' => json_decode((string) $row->brain_refs, true) ?: [],
            ];
        }

        return $this->execute([
            'plan_id' => $planId,
            'workspace_id' => (string) $planRow->workspace_id,
            'nodes' => $nodes,
        ], $opts);
    }

    /**
     * Execute a plan (in-memory or just-loaded). Topological walk — for each node:
     * deliver (per-node brain context from its brain_refs) → apply certified files
     * onto the obra branch (accumulate) → per-node gate → record the node into the
     * brain. A failed/ungated node HALTS the obra.
     *
     * @param  array{plan_id:string,workspace_id?:string,nodes:list<array<string,mixed>>}  $plan
     * @param  array<string,mixed>  $opts  {repo_dir?, provider?, no_brain?:bool}
     * @return array<string,mixed> {schema, plan_id, status, branch, node_count,
     *                             delivered_nodes, failed_node?, nodes:list<...>,
     *                             main_untouched, never_merged, reversible, review_commands?,
     *                             brain_recorded, reason?}
     */
    public function execute(array $plan, array $opts = []): array
    {
        if (! (bool) config('atlas.obra.enabled', true)) {
            return $this->refused((string) ($plan['plan_id'] ?? ''), 'obra_disabled');
        }

        $planId = trim((string) ($plan['plan_id'] ?? ''));
        if ($planId === '') {
            return $this->refused('', 'plan_id_required');
        }
        $nodes = array_values((array) ($plan['nodes'] ?? []));
        if ($nodes === []) {
            return $this->refused($planId, 'plan_has_no_nodes');
        }

        $repoDir = (string) ($opts['repo_dir'] ?? base_path());
        $noBrain = (bool) ($opts['no_brain'] ?? false);

        // Walk in the persisted topological order (seq ascending — the F1 spine already
        // proved it acyclic + dependency-respecting; re-sort defensively in case the
        // caller handed an unordered list).
        usort($nodes, static fn (array $a, array $b): int => ((int) ($a['seq'] ?? 0)) <=> ((int) ($b['seq'] ?? 0)));

        // --- OPEN or RESUME the obra: ONE branch + a persistent worktree from the clean base. ---
        $open = $this->openOrResumeObra($planId, $repoDir);
        if (! (bool) ($open['opened'] ?? false)) {
            $this->setPlanStatus($planId, self::STATUS_FAILED);

            return $this->refused($planId, 'open_obra_failed:'.((string) ($open['reason'] ?? 'unknown')), [
                'branch' => $open['branch'] ?? null,
            ]);
        }

        $branch = (string) $open['branch'];
        $worktree = (string) $open['worktree'];
        $resumed = (bool) ($open['resumed'] ?? false);
        $resumeCount = (int) ($open['resume_count'] ?? 0);
        $this->setPlanStatus($planId, 'running');
        $this->setPlanRuntime($planId, [
            'branch' => $branch,
            'worktree' => $worktree,
            'base_head' => (string) $open['base_head'],
            'repo_dir' => $repoDir,
            'resume_supported' => true,
            'resume_count' => $resumeCount,
            'resumed_current_run' => $resumed,
        ]);

        $nodeResults = [];
        $deliveredCount = 0;
        $failedNode = null;
        $halted = false;
        // REPAIR (default-OFF): a single per-OBRA delivery-attempt counter bounds total provider
        // spend across ALL nodes even if no single node hits its per-node cap.
        $totalAttempts = 0;

        foreach ($nodes as $node) {
            $nodeId = (string) ($node['id'] ?? '');
            $request = trim((string) ($node['request'] ?? ''));
            $seq = (int) ($node['seq'] ?? 0);

            if ($resumed && $this->nodeStatus($nodeId) === self::NODE_DONE) {
                $previous = $this->nodeResult($nodeId);
                $nodeResults[] = [
                    'id' => $nodeId,
                    'seq' => $seq,
                    'status' => self::NODE_DONE,
                    'commit' => $previous['commit'] ?? null,
                    'files_changed' => array_values((array) ($previous['files_changed'] ?? [])),
                    'provider' => $previous['provider'] ?? null,
                    'model' => is_string($previous['model'] ?? null) ? $previous['model'] : null,
                    'delivery' => $previous['delivery'] ?? 'resumed_prior_delivery',
                    'resumed' => true,
                ];
                $deliveredCount++;

                continue;
            }

            // Once halted, the remaining nodes do NOT run — they are recorded skipped.
            if ($halted) {
                $nodeResults[] = ['id' => $nodeId, 'seq' => $seq, 'status' => self::NODE_SKIPPED, 'reason' => 'obra_halted'];
                $this->setNodeStatus($nodeId, self::NODE_SKIPPED, ['reason' => 'obra_halted']);

                continue;
            }

            $this->setNodeStatus($nodeId, 'running');

            // --- 1) DELIVER the node's step → certified files (the SPEND path). ---
            $context = [
                'node_id' => $nodeId,
                'plan_id' => $planId,
                'target_area' => $node['target_area'] ?? null,
                'brain_refs' => (array) ($node['brain_refs'] ?? []),
                'repo_dir' => $repoDir,
                // The HELD obra worktree carrying steps 1..N-1's committed state. A
                // delivery MAY read it to build on the accumulated state (the production
                // provider delivery does not need it — it works against the named files —
                // but exposing it makes the accumulation a first-class, inspectable seam).
                'obra_worktree' => $worktree,
                'obra_branch' => $branch,
            ];
            if (! $noBrain) {
                // BRAIN-DRIVEN: format the node's persisted brain_refs into a small,
                // provider-safe context string for the delivery (fail-open — empty when absent).
                $context['brain_context'] = $this->brainContextFor((array) ($node['brain_refs'] ?? []));
            }
            if (isset($opts['provider']) && is_string($opts['provider']) && $opts['provider'] !== '') {
                $context['provider'] = (string) $opts['provider'];
            }

            // REPAIR LOOP (default-OFF): on a DELIVERY failure (certified=false — BEFORE any apply or
            // commit, so there is no gate-stage launder), retry the delivery with LABEL-ONLY failure
            // feedback, bounded per-node AND per-obra, stopping early on no-progress (a byte-identical
            // re-edit). Repair changes only the COUNT of delivery attempts, never the bar: a retry's
            // result still faces the same certified/apply/gate stack below. With repair.enabled=false
            // the delivery runs EXACTLY ONCE (byte-identical to today).
            $repairEnabled = (bool) ($opts['repair']['enabled'] ?? false);
            $maxPerNode = max(1, (int) ($opts['repair']['maxPerNode'] ?? 1));
            $maxPerObra = max(1, (int) ($opts['repair']['maxPerObra'] ?? PHP_INT_MAX));
            $nodeAttempt = 0;
            $repairStop = null;
            $lastArtifactFp = null;
            $deliveryRequest = $request;
            while (true) {
                $nodeAttempt++;
                $totalAttempts++;
                try {
                    $delivered = $this->delivery->deliver($deliveryRequest, $context);
                } catch (Throwable $e) {
                    $delivered = ['certified' => false, 'files' => [], 'reason' => 'delivery_exception:'.substr($e->getMessage(), 0, 120)];
                }
                if ((bool) ($delivered['certified'] ?? false) || ! $repairEnabled) {
                    break; // delivered (-> apply+gate below) OR repair OFF (single pass -> halt below)
                }
                // NO-PROGRESS: only when the failed delivery actually produced files (a re-edit that
                // keeps failing identically). An empty-files delivery failure has no artifact to
                // compare — the per-node/per-obra caps bound it instead.
                $fp = $this->deliveredArtifactFingerprint($delivered);
                if ($fp !== null && $fp === $lastArtifactFp) {
                    $repairStop = 'no_progress';
                    break;
                }
                $lastArtifactFp = $fp;
                if ($nodeAttempt >= $maxPerNode) {
                    $repairStop = 'per_node';
                    break;
                }
                if ($totalAttempts >= $maxPerObra) {
                    $repairStop = 'per_obra';
                    break;
                }
                // LABEL-ONLY feedback (the critical anti-leak: NEVER the raw output_excerpt / source).
                $deliveryRequest = $request."\n\n[repair attempt ".($nodeAttempt + 1)."] the prior attempt failed — "
                    .$this->repairFeedback($delivered)." Fix it and PRESERVE behaviour so the frozen sibling tests stay green.";
            }

            // FAIL-CLOSED: a non-certified delivery HALTS the obra (no garbage applied).
            if (! (bool) ($delivered['certified'] ?? false)) {
                $reason = (string) ($delivered['reason'] ?? 'not_certified');
                if ($repairEnabled && $repairStop !== null) {
                    $reason = 'repair_exhausted:'.$repairStop.':'.$reason;
                }
                $failure = array_merge([
                    'id' => $nodeId,
                    'seq' => $seq,
                    'status' => self::NODE_FAILED,
                    'stage' => 'delivery',
                    'reason' => $reason,
                ], $this->deliveryFailureAutopsy($delivered));
                $nodeResults[] = $failure;
                $this->setNodeStatus($nodeId, self::NODE_FAILED, $this->nodeResultPayload($failure));
                $failedNode = $nodeId;
                $halted = true;

                continue;
            }

            $files = array_values((array) ($delivered['files'] ?? []));
            $gateReceipt = (string) ($delivered['gate_receipt'] ?? '');

            // --- 2) APPLY the certified files onto the SAME accumulating branch. ---
            $apply = $this->materializer->applyStepToObra([
                'worktree' => $worktree,
                'base_head' => (string) $open['base_head'],
                'step_id' => $nodeId,
                'files' => $files,
                'certified' => true,
                'gate_receipt' => $gateReceipt,
            ]);
            if (! (bool) ($apply['applied'] ?? false)) {
                $reason = (string) ($apply['reason'] ?? 'apply_failed');
                $nodeResults[] = ['id' => $nodeId, 'seq' => $seq, 'status' => self::NODE_FAILED, 'stage' => 'apply', 'reason' => $reason];
                $this->setNodeStatus($nodeId, self::NODE_FAILED, ['stage' => 'apply', 'reason' => $reason]);
                $failedNode = $nodeId;
                $halted = true;

                continue;
            }

            // --- 3) GOVERNED per-node gate (fail-closed). The certification IS the
            //         default gate; an injected stricter Forge gate may still veto. ---
            $gateResult = $this->runGate($node, $delivered, $apply, $worktree);
            if (! (bool) ($gateResult['passed'] ?? false)) {
                $reason = (string) ($gateResult['reason'] ?? 'gate_failed');
                $nodeResults[] = ['id' => $nodeId, 'seq' => $seq, 'status' => self::NODE_FAILED, 'stage' => 'gate', 'reason' => $reason];
                $this->setNodeStatus($nodeId, self::NODE_FAILED, ['stage' => 'gate', 'reason' => $reason]);
                $failedNode = $nodeId;
                $halted = true;

                continue;
            }

            // --- 4) Node DONE: record outcome + result. ---
            $filesChanged = array_values((array) ($apply['files_changed'] ?? []));
            $nodeOutcome = [
                'id' => $nodeId,
                'seq' => $seq,
                'status' => self::NODE_DONE,
                'commit' => $apply['commit'] ?? null,
                'files_changed' => $filesChanged,
                'provider' => $delivered['provider'] ?? null,
                // L4-10 — carry the engine model LABEL the delivery reported so the
                // self-stamped receipt can record provider/model from observed facts.
                'model' => is_string($delivered['model'] ?? null) ? $delivered['model'] : null,
                'delivery' => $this->delivery->label(),
            ];
            $nodeResults[] = $nodeOutcome;
            $this->setNodeStatus($nodeId, self::NODE_DONE, [
                'commit' => $apply['commit'] ?? null,
                'files_changed' => $filesChanged,
                'branch' => $branch,
                'gate_receipt' => $gateReceipt,
                'provider' => $delivered['provider'] ?? null,
                'model' => is_string($delivered['model'] ?? null) ? $delivered['model'] : null,
                'delivery' => $this->delivery->label(),
            ]);

            // BRAIN write-back (compounding) — provider-safe, fail-open.
            $this->recordNodeIntoBrain($nodeId, $request, $branch, $filesChanged, $delivered);
            $deliveredCount++;
        }

        // --- AOBG N3.F3 — INTEGRATED CERTIFICATION of the WHOLE assembled branch. ---
        // A per-step pass does NOT imply the obra integrates (step 3 may break step 1).
        // When (and only when) every step succeeded, run the integrated measure ON THE
        // HELD WORKTREE (the assembled state) BEFORE close drops it. A failed/unrunnable
        // integrated check → certified=false / needs_review (fail-closed; whole > parts).
        $integratedCheck = $this->integratedCheckFor($opts);
        $integratedResult = null;
        if (! $halted && $integratedCheck !== null) {
            $measureInput = ['worktree' => $worktree, 'measure_cmd' => $integratedCheck];
            // The integrated whole-obra test is the slowest step; honour the caller's declared budget
            // when supplied (else measureObra keeps its default git timeout — byte-identical).
            $integratedTimeout = $this->integratedCheckTimeoutFor($opts);
            if ($integratedTimeout !== null) {
                $measureInput['measure_timeout_seconds'] = $integratedTimeout;
            }
            $integratedResult = $this->materializer->measureObra($measureInput);
        }

        // --- CLOSE the obra: drop the worktree, keep the branch, prove main untouched. ---
        $close = $this->materializer->closeObra([
            'repo' => (string) ($open['repo'] ?? $repoDir),
            'worktree' => $worktree,
            'branch' => $branch,
            'base_head' => (string) $open['base_head'],
            'status_before' => (string) ($open['status_before'] ?? ''),
        ]);

        // --- Assemble the integration evidence envelope (pure; cost-free). ---
        $envelope = $this->certifier()->certify([
            'obra_id' => $planId,
            'branch' => $branch,
            'halted' => $halted,
            'failed_node' => $failedNode,
            'nodes' => $nodeResults,
            'integrated' => $integratedResult,
            'integrated_supplied' => $integratedCheck !== null,
        ]);

        $certified = (bool) ($envelope['certified'] ?? false);
        // status: done ONLY when the WHOLE obra certified; otherwise failed (halt) or
        // the honest needs_review (steps passed but integration failed / unrunnable / absent).
        if ($halted) {
            $status = self::STATUS_FAILED;
        } elseif ($certified) {
            $status = self::STATUS_DONE;
        } else {
            $status = self::STATUS_NEEDS_REVIEW;
        }
        $this->setPlanStatus($planId, $status);

        // BRAIN write-back for the OBRA itself (so the next obra sees this one) —
        // truthfully: certified flag + integrated status + generated edges to each step.
        $stepIds = array_values(array_map(
            static fn (array $n): string => (string) ($n['id'] ?? ''),
            array_filter($nodeResults, static fn (array $n): bool => ($n['status'] ?? '') === self::NODE_DONE),
        ));
        $obraRecorded = $this->recordObraIntoBrain($planId, $branch, $envelope, $deliveredCount, count($nodes), $stepIds);

        $reason = $envelope['reason'] ?? ($halted ? 'halted_on_node:'.((string) $failedNode) : null);

        $mainUntouched = (bool) ($close['main_untouched'] ?? true);

        // --- L4-10 — EMIT the self-stamped, signed executor RECEIPT from observed facts. ---
        // This is the provenance inversion: the L4-10 proof reads THIS executor OUTPUT
        // (HMAC-sealed over the load-bearing facts), not a hand-assembled file. A
        // hand-edit of any sealed fact (certified / provider / node_count / a step commit
        // / main_untouched) invalidates the signature and the strict proof rejects it.
        $executorReceipt = $this->stampReceipt([
            'obra_id' => $planId,
            'branch' => $branch,
            'base_head' => (string) ($open['base_head'] ?? ''),
            'status' => $status,
            'certified' => $certified,
            'node_count' => count($nodes),
            'delivered_nodes' => $deliveredCount,
            'resumed' => $resumed,
            'resume_count' => $resumeCount,
            'main_untouched' => $mainUntouched,
            'never_merged' => true,
            'receipt_hash' => is_string($envelope['receipt_hash'] ?? null) ? $envelope['receipt_hash'] : null,
            'nodes' => $nodeResults,
            'integrated' => $envelope['integrated_test_result'] ?? null,
        ]);

        return [
            'schema' => self::SCHEMA,
            'plan_id' => $planId,
            'status' => $status,
            'branch' => $branch,
            'node_count' => count($nodes),
            'delivered_nodes' => $deliveredCount,
            'failed_node' => $failedNode,
            // The WHOLE-obra verdict (F3): a per-step-green obra whose integrated check
            // failed/could-not-run/was-absent is certified=false (needs_review).
            'certified' => $certified,
            'disposition' => (string) ($envelope['disposition'] ?? ''),
            'certification' => $envelope,
            'integrated_test_result' => $envelope['integrated_test_result'] ?? null,
            'files_total' => (int) ($envelope['files_total'] ?? 0),
            'nodes' => $nodeResults,
            'main_untouched' => $mainUntouched,
            'never_merged' => true,
            'never_pushed' => true,
            'reversible' => true,
            'resumed' => $resumed,
            'resume_count' => $resumeCount,
            'brain_recorded' => $obraRecorded,
            // L4-10 — the self-stamped, HMAC-signed runtime receipt (executor OUTPUT). The
            // L4-10 proof verifies its provenance signature; a hand-edit is rejected.
            'executor_receipt' => $executorReceipt,
            'review_commands' => $close['review_commands'] ?? [],
            'reason' => $reason,
        ];
    }

    /**
     * L4-10 — mint the self-stamped, signed executor receipt from the run's OBSERVED
     * facts (provider/model aggregated from the delivered nodes, the per-step commits,
     * resumed/resume_count, main_untouched, the F3 receipt_hash). Default-constructed
     * stamp when none injected — every run emits one. Provider-safe: ids / commits /
     * labels / booleans only.
     *
     * @param  array<string,mixed>  $facts  {obra_id, branch, base_head, status, certified,
     *                             node_count, delivered_nodes, resumed, resume_count,
     *                             main_untouched, never_merged, receipt_hash, nodes, integrated}
     * @return array<string,mixed> the signed receipt
     */
    private function stampReceipt(array $facts): array
    {
        $nodes = array_values((array) ($facts['nodes'] ?? []));
        $done = array_values(array_filter(
            $nodes,
            static fn (array $n): bool => ($n['status'] ?? '') === self::NODE_DONE,
        ));

        // Aggregate the provider/model LABELS from the delivered steps (the executor
        // genuinely observed these). A homogeneous run reports the single engine; a
        // mixed run reports the FIRST non-empty (the receipt carries a label, not a claim).
        $provider = $this->firstNonEmptyNodeField($done, 'provider');
        $model = $this->firstNonEmptyNodeField($done, 'model');

        $steps = array_map(static fn (array $n): array => [
            'id' => (string) ($n['id'] ?? ''),
            'status' => (string) ($n['status'] ?? ''),
            'commit' => is_string($n['commit'] ?? null) ? (string) $n['commit'] : '',
        ], $nodes);

        $deliveredFiles = [];
        foreach ($done as $n) {
            foreach ((array) ($n['files_changed'] ?? []) as $f) {
                if (is_string($f) && $f !== '') {
                    $deliveredFiles[$f] = true;
                }
            }
        }

        return $this->receiptStamper()->stamp([
            'obra_id' => (string) ($facts['obra_id'] ?? ''),
            'branch' => $facts['branch'] ?? null,
            'base_head' => $facts['base_head'] ?? null,
            'status' => (string) ($facts['status'] ?? ''),
            'certified' => (bool) ($facts['certified'] ?? false),
            'node_count' => (int) ($facts['node_count'] ?? count($nodes)),
            'delivered_nodes' => (int) ($facts['delivered_nodes'] ?? count($done)),
            'provider' => $provider,
            'model' => $model,
            // RUN PROVENANCE (sealed in the HMAC): only the executor knows its delivery binding.
            // A real provider run (ProviderObraNodeDelivery, the single spend path) is the ONLY
            // thing that earns 'real_provider_obra_run'; ANY other binding (a deterministic test
            // fixture) is honestly stamped 'fixture_obra_run' and a downstream L4-10 proof rejects
            // it — a fixture can never be laundered into a real receipt.
            'execution_mode' => $this->delivery instanceof ProviderObraNodeDelivery ? 'real_provider_obra_run' : 'fixture_obra_run',
            'delivery_label' => $this->delivery->label(),
            'resumed' => (bool) ($facts['resumed'] ?? false),
            'resume_count' => (int) ($facts['resume_count'] ?? 0),
            'main_untouched' => (bool) ($facts['main_untouched'] ?? false),
            'never_merged' => (bool) ($facts['never_merged'] ?? true),
            'receipt_hash' => is_string($facts['receipt_hash'] ?? null) ? $facts['receipt_hash'] : null,
            'delivered_item_id' => null,
            'delivered_files' => array_keys($deliveredFiles),
            'steps' => $steps,
            'integrated' => $facts['integrated'] ?? null,
        ]);
    }

    private function receiptStamper(): AtlasObraReceiptStamp
    {
        return $this->receiptStamp ?? new AtlasObraReceiptStamp;
    }

    /**
     * First non-empty string value of $field across the given nodes (provider/model).
     *
     * @param  list<array<string,mixed>>  $nodes
     */
    private function firstNonEmptyNodeField(array $nodes, string $field): ?string
    {
        foreach ($nodes as $n) {
            $v = $n[$field] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }

    /**
     * Resume only when a prior live run persisted the exact branch/worktree/base
     * runtime and that worktree still exists. This makes kill/restart fail-closed:
     * branch-only leftovers are not guessed back into a green run.
     *
     * @return array<string,mixed>
     */
    private function openOrResumeObra(string $planId, string $repoDir): array
    {
        $stored = $this->storedPlanRuntime($planId);
        if (($stored['status'] ?? null) === 'running') {
            $runtime = (array) ($stored['runtime'] ?? []);
            $worktree = rtrim((string) ($runtime['worktree'] ?? ''), '/');
            $branch = (string) ($runtime['branch'] ?? '');
            $baseHead = (string) ($runtime['base_head'] ?? '');
            if (
                $branch !== ''
                && $baseHead !== ''
                && $worktree !== ''
                && is_dir($worktree)
                && (is_dir($worktree.'/.git') || is_file($worktree.'/.git'))
            ) {
                $resumeCount = ((int) ($runtime['resume_count'] ?? 0)) + 1;

                return [
                    'opened' => true,
                    'resumed' => true,
                    'resume_count' => $resumeCount,
                    'repo' => $repoDir,
                    'branch' => $branch,
                    'worktree' => $worktree,
                    'base_head' => $baseHead,
                    'status_before' => (string) ($stored['status'] ?? ''),
                ];
            }
        }

        $open = $this->materializer->openObra(['id' => $planId, 'repo_dir' => $repoDir]);
        $open['resumed'] = false;
        $open['resume_count'] = (int) data_get($stored, 'runtime.resume_count', 0);

        return $open;
    }

    /**
     * The integrated check command for THIS run, or null when none is configured.
     * Precedence: an explicit per-run opt > the plan-level configured default. The
     * integrated check is the whole-branch test/measure (e.g. the focused
     * `php artisan test ...` for the obra's area) — it runs ON THE ASSEMBLED branch,
     * not per step. null ⇒ no whole-branch proof ⇒ the obra is delivered needs_review
     * (NEVER silently certified on per-step passes alone).
     *
     * @param  array<string,mixed>  $opts
     */
    /**
     * Optional budget (seconds) for the integrated whole-obra check. Null (the default) leaves the
     * materializer's own git timeout in place — so the default executor path is byte-identical.
     *
     * @param  array<string,mixed>  $opts
     */
    private function integratedCheckTimeoutFor(array $opts): ?int
    {
        $v = $opts['integrated_check_timeout'] ?? null;

        return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
    }

    private function integratedCheckFor(array $opts): ?string
    {
        if (array_key_exists('integrated_check', $opts) && is_string($opts['integrated_check'])) {
            $cmd = trim($opts['integrated_check']);

            return $cmd !== '' ? $cmd : null;
        }
        $configured = config('atlas.obra.integrated_check');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return null;
    }

    private function certifier(): AtlasObraCertificationService
    {
        return $this->certification ?? new AtlasObraCertificationService;
    }

    /**
     * Discard the WHOLE obra branch (the reversible invariant, made callable for the
     * operator / a rejecting caller). Delegates to the materializer's governed
     * discard, which only ever touches the atlas/obra/ (or atlas/materialize/) prefix.
     *
     * @return array{discarded:bool,branch:string,reason:?string}
     */
    public function discardObra(string $repoDir, string $planId): array
    {
        return $this->materializer->discardBranch($repoDir, 'atlas/obra/'.$this->slug($planId));
    }

    /**
     * GOVERNED per-node gate. Default: the delivery's certification IS the credential —
     * a certified, applied step passes. An injected {@see ObraNodeGate} runs ON TOP
     * (a stricter Forge certification) and may VETO; its veto is fail-closed (HALTS
     * the obra). A thrown gate is treated as a FAILURE (never silently passed).
     *
     * @param  array<string,mixed>  $node
     * @param  array<string,mixed>  $delivered
     * @param  array<string,mixed>  $apply
     * @return array{passed:bool,reason?:string}
     */
    private function runGate(array $node, array $delivered, array $apply, string $worktree): array
    {
        if (! $this->gate instanceof ObraNodeGate) {
            return ['passed' => true];
        }
        try {
            $r = $this->gate->certify($node, [
                'delivered' => $delivered,
                'apply' => $apply,
                'worktree' => $worktree,
            ]);

            return ['passed' => (bool) ($r['passed'] ?? false), 'reason' => (string) ($r['reason'] ?? 'gate_vetoed')];
        } catch (Throwable $e) {
            // Fail-CLOSED: a gate that throws does NOT let the step pass.
            return ['passed' => false, 'reason' => 'gate_exception:'.substr($e->getMessage(), 0, 120)];
        }
    }

    /**
     * Format a node's persisted, provider-safe brain_refs into a small context string
     * for the delivery prompt. Deterministic, bounded; empty when there is no anchor
     * (fail-open). Reads only ids / labels / paths — never source.
     *
     * @param  array<string,mixed>  $refs
     */
    private function brainContextFor(array $refs): string
    {
        $lines = [];
        foreach (array_slice((array) ($refs['code'] ?? []), 0, 6) as $it) {
            $id = (string) ($it['id'] ?? '');
            $file = (string) ($it['file'] ?? '');
            if ($id !== '' || $file !== '') {
                $lines[] = '- code: '.trim($id.($file !== '' ? ' ('.$file.')' : ''));
            }
        }
        foreach (array_slice((array) ($refs['memory'] ?? []), 0, 6) as $m) {
            $title = (string) ($m['title'] ?? '');
            if ($title !== '') {
                $lines[] = '- memory: '.$title;
            }
        }

        return $lines === [] ? '' : implode("\n", $lines);
    }

    /**
     * Record ONE delivered node into the AURG brain (compounding). Reuses the proven
     * {@see AtlasRealityGraphIngestionService::recordMissionOutcome()} — branch ref +
     * ids / hashes / labels / paths only, never source. Fail-open.
     *
     * @param  list<string>  $filesChanged
     * @param  array<string,mixed>  $delivered
     */
    private function recordNodeIntoBrain(string $nodeId, string $request, string $branch, array $filesChanged, array $delivered): void
    {
        if (! $this->brain instanceof AtlasRealityGraphIngestionService) {
            return;
        }
        try {
            $this->brain->recordMissionOutcome([
                'id' => $nodeId,
                'request' => $request,
                'branch' => $branch,
                'delivered' => true,
                'provider' => is_string($delivered['provider'] ?? null) ? $delivered['provider'] : null,
                'receipt' => is_string($delivered['gate_receipt'] ?? null) ? $delivered['gate_receipt'] : null,
                'files' => $filesChanged,
                'measure' => ['ok' => true],
            ]);
        } catch (Throwable) {
            // Honest degrade — a brain outage never breaks the obra.
        }
    }

    /**
     * AOBG N3.F3 — record the OBRA itself into the brain as a FIRST-CLASS unit (an
     * 'obra' node + 'generated' edges to each step's mission node + an 'evidence' node
     * for the integrated certification) so the next obra's brain query sees this one
     * (compounding). Records the integration verdict TRUTHFULLY — a needs_review obra
     * is recorded certified=false with status 'needs_review' (never a green claim).
     * Fail-open: a brain outage never breaks the obra.
     *
     * @param  array<string,mixed>  $envelope  the F3 certification envelope
     * @param  list<string>  $stepIds  the done step node ids (for obra→step generated edges)
     */
    private function recordObraIntoBrain(string $planId, string $branch, array $envelope, int $delivered, int $total, array $stepIds): bool
    {
        if (! $this->brain instanceof AtlasRealityGraphIngestionService) {
            return false;
        }
        try {
            $certified = (bool) ($envelope['certified'] ?? false);
            $integrated = (array) ($envelope['integrated_test_result'] ?? []);
            $integratedStatus = (bool) ($integrated['supplied'] ?? false)
                ? ((bool) ($integrated['ran'] ?? false)
                    ? ((bool) ($integrated['passed'] ?? false) ? 'passed' : 'failed')
                    : 'unrunnable')
                : 'absent';

            $r = $this->brain->recordObraOutcome([
                'id' => $planId,
                'intent' => 'obra '.$planId.' ('.$delivered.'/'.$total.' steps)',
                'branch' => $branch,
                'certified' => $certified,
                'status' => $certified ? 'certified' : 'needs_review',
                'integrated_status' => $integratedStatus,
                'delivered_steps' => $delivered,
                'total_steps' => $total,
                'receipt_hash' => is_string($envelope['receipt_hash'] ?? null) ? $envelope['receipt_hash'] : null,
                'step_ids' => $stepIds,
            ]);

            return (bool) ($r['recorded'] ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{status?:string,runtime?:array<string,mixed>}
     */
    private function storedPlanRuntime(string $planId): array
    {
        try {
            $row = DB::table('atlas_obra_plans')->where('id', $planId)->first(['status', 'meta']);
            if ($row === null) {
                return [];
            }
            $meta = json_decode((string) ($row->meta ?? '{}'), true);

            return [
                'status' => (string) ($row->status ?? ''),
                'runtime' => is_array($meta) ? (array) ($meta['obra_runtime'] ?? []) : [],
            ];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string,mixed>  $runtime
     */
    private function setPlanRuntime(string $planId, array $runtime): void
    {
        try {
            $raw = DB::table('atlas_obra_plans')->where('id', $planId)->value('meta');
            $meta = json_decode((string) ($raw ?: '{}'), true);
            if (! is_array($meta)) {
                $meta = [];
            }
            $previous = (array) ($meta['obra_runtime'] ?? []);
            $meta['obra_runtime'] = array_merge($previous, $runtime, [
                'updated_at' => now()->toISOString(),
            ]);
            DB::table('atlas_obra_plans')->where('id', $planId)->update([
                'meta' => json_encode($meta, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // The plan tables may be absent in an in-memory-only execute(); ignore.
        }
    }

    private function nodeStatus(string $nodeId): ?string
    {
        try {
            $status = DB::table('atlas_obra_nodes')->where('id', $nodeId)->value('status');

            return is_string($status) ? $status : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function nodeResult(string $nodeId): array
    {
        try {
            $raw = DB::table('atlas_obra_nodes')->where('id', $nodeId)->value('result');
            $decoded = json_decode((string) ($raw ?: '{}'), true);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function setPlanStatus(string $planId, string $status): void
    {
        try {
            DB::table('atlas_obra_plans')->where('id', $planId)->update(['status' => $status, 'updated_at' => now()]);
        } catch (Throwable) {
            // The plan tables may be absent in an in-memory-only execute(); ignore.
        }
    }

    /**
     * @param  array<string,mixed>  $resultMerge
     */
    private function setNodeStatus(string $nodeId, string $status, array $resultMerge = []): void
    {
        try {
            $update = ['status' => $status, 'updated_at' => now()];
            if ($resultMerge !== []) {
                $update['result'] = json_encode($resultMerge, JSON_UNESCAPED_SLASHES);
            }
            DB::table('atlas_obra_nodes')->where('id', $nodeId)->update($update);
        } catch (Throwable) {
            // In-memory-only execute(): the node row may not exist; ignore.
        }
    }

    /**
     * LABEL-ONLY repair feedback for the next delivery attempt — provider-safe BY CONSTRUCTION: it
     * projects ONLY the failure reason and the gate-check tool/reason/exit_code SCALARS, and
     * DELIBERATELY DROPS output_excerpt / output (the raw command stdout/stderr that could echo
     * source). No file bodies, no provider output, no prompt — a single short clause the provider can
     * act on. (The full {@see deliveryFailureAutopsy} carries bounded excerpts for the AUDIT receipt;
     * this projection is what crosses back INTO a provider request, so it is strictly narrower.)
     *
     * @param  array<string,mixed>  $delivered
     */
    private function repairFeedback(array $delivered): string
    {
        $parts = [];
        $reason = trim((string) ($delivered['reason'] ?? 'not_certified'));
        if ($reason !== '') {
            $parts[] = 'reason='.substr($reason, 0, 120);
        }
        foreach (['syntax_check' => 'syntax', 'run_check' => 'tests'] as $key => $label) {
            $check = (array) ($delivered[$key] ?? []);
            $bits = [];
            foreach (['tool', 'reason', 'exit_code'] as $f) {
                // EXPLICIT scalar whitelist — output_excerpt/output are NEVER included.
                if (isset($check[$f]) && (is_string($check[$f]) || is_int($check[$f]))) {
                    $bits[] = $f.'='.substr((string) $check[$f], 0, 80);
                }
            }
            if ($bits !== []) {
                $parts[] = $label.'('.implode(',', $bits).')';
            }
        }

        return $parts === [] ? 'the gate did not pass.' : implode('; ', $parts).'.';
    }

    /**
     * Content fingerprint of the delivered files (sorted path => sha256(content)); NULL when the
     * failed delivery produced no files (nothing to compare — the caps bound it instead). The repair
     * no-progress guard stops when a retry reproduces an identical fingerprint (the same wrong edit).
     *
     * @param  array<string,mixed>  $delivered
     */
    private function deliveredArtifactFingerprint(array $delivered): ?string
    {
        $map = [];
        foreach (array_values((array) ($delivered['files'] ?? [])) as $f) {
            if (! is_array($f)) {
                continue;
            }
            $path = trim((string) ($f['path'] ?? ''));
            if ($path !== '') {
                $map[$path] = hash('sha256', (string) ($f['content'] ?? ''));
            }
        }
        if ($map === []) {
            return null;
        }
        ksort($map);

        return hash('sha256', (string) json_encode($map));
    }

    /**
     * Bounded delivery autopsy for a halted node. This intentionally carries only
     * metadata/gate summaries: no prompt, provider output, code preview or file bodies.
     *
     * @param  array<string,mixed>  $delivered
     * @return array<string,mixed>
     */
    private function deliveryFailureAutopsy(array $delivered): array
    {
        $autopsy = [];
        foreach (['provider', 'model', 'delivery_status', 'target_file'] as $key) {
            if (isset($delivered[$key]) && is_string($delivered[$key]) && $delivered[$key] !== '') {
                $autopsy[$key] = substr($delivered[$key], 0, 180);
            }
        }
        foreach (['file_count', 'latency_ms'] as $key) {
            if (isset($delivered[$key]) && is_numeric($delivered[$key])) {
                $autopsy[$key] = (int) $delivered[$key];
            }
        }

        $syntax = $this->boundedGateCheck((array) ($delivered['syntax_check'] ?? []));
        if ($syntax !== []) {
            $autopsy['syntax_check'] = $syntax;
        }
        $run = $this->boundedGateCheck((array) ($delivered['run_check'] ?? []));
        if ($run !== []) {
            $autopsy['run_check'] = $run;
        }
        $diagnostic = $this->boundedDeliveryDiagnostic((array) ($delivered['delivery_diagnostic'] ?? []));
        if ($diagnostic !== []) {
            $autopsy['delivery_diagnostic'] = $diagnostic;
        }

        return $autopsy;
    }

    /**
     * Drop presentation-only fields before persisting the node result.
     *
     * @param  array<string,mixed>  $failure
     * @return array<string,mixed>
     */
    private function nodeResultPayload(array $failure): array
    {
        unset($failure['id'], $failure['seq'], $failure['status']);

        return $failure;
    }

    /**
     * @param  array<string,mixed>  $diagnostic
     * @return array<string,mixed>
     */
    private function boundedDeliveryDiagnostic(array $diagnostic): array
    {
        if ($diagnostic === []) {
            return [];
        }

        $bounded = [];
        foreach (['schema_version', 'reason', 'delivery_status', 'blocked_reason', 'provider', 'model', 'target_file'] as $key) {
            if (isset($diagnostic[$key]) && is_string($diagnostic[$key]) && $diagnostic[$key] !== '') {
                $bounded[$key] = substr($diagnostic[$key], 0, 180);
            }
        }
        foreach (['file_count', 'latency_ms'] as $key) {
            if (isset($diagnostic[$key]) && is_numeric($diagnostic[$key])) {
                $bounded[$key] = (int) $diagnostic[$key];
            }
        }
        foreach (['syntax_check', 'run_check'] as $key) {
            $check = $this->boundedGateCheck((array) ($diagnostic[$key] ?? []));
            if ($check !== []) {
                $bounded[$key] = $check;
            }
        }

        return $bounded;
    }

    /**
     * @param  array<string,mixed>  $check
     * @return array<string,mixed>
     */
    private function boundedGateCheck(array $check): array
    {
        if ($check === []) {
            return [];
        }

        $bounded = [];
        foreach (['ok', 'tool', 'reason', 'exit_code'] as $key) {
            if (array_key_exists($key, $check)) {
                $bounded[$key] = is_string($check[$key]) ? substr(trim($check[$key]), 0, 180) : $check[$key];
            }
        }
        if (isset($check['output_excerpt']) && is_string($check['output_excerpt'])) {
            $bounded['output_excerpt'] = substr(trim($check['output_excerpt']), 0, 500);
        } elseif (isset($check['output']) && is_string($check['output'])) {
            $bounded['output_excerpt'] = substr(trim($check['output']), 0, 500);
        }

        return $bounded;
    }

    private function slug(string $id): string
    {
        return ltrim(strtolower((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $id)), '-.');
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function refused(string $planId, string $reason, array $extra = []): array
    {
        return array_merge([
            'schema' => self::SCHEMA,
            'plan_id' => $planId,
            'status' => self::STATUS_FAILED,
            'branch' => null,
            'node_count' => 0,
            'delivered_nodes' => 0,
            'failed_node' => null,
            'certified' => false,
            'disposition' => AtlasObraCertificationService::DISPOSITION_HALTED,
            'certification' => null,
            'integrated_test_result' => null,
            'files_total' => 0,
            'nodes' => [],
            'main_untouched' => true,
            'never_merged' => true,
            'never_pushed' => true,
            'reversible' => true,
            'brain_recorded' => false,
            'reason' => $reason,
        ], $extra);
    }
}
