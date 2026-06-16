<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopIntentSpecCompiler;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraDecompositionPlanner;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraPlanValidator;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopPlanReadinessGate;
use App\Services\Ai\Obra\AtlasObraExecutor;
use App\Services\Ai\Obra\ObraNodeDelivery;
use App\Services\Ai\Obra\ProviderObraNodeDelivery;
use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * #7 — the MISSING wire that makes the loop EXECUTE a coordinated multi-file refactor (not just park).
 *
 * It maps the multi-file synthesizer payload → an in-memory obra plan (one node per allowed file) →
 * invokes {@see AtlasObraExecutor} on an ISOLATED, discardable worktree (the executor opens
 * atlas/obra/<id> + a temp worktree from clean base_head; closeObra proves main untouched) → emits the
 * HMAC-signed L4-10 evidence from the executor receipt → validates it with
 * {@see AtlasLoopRefactorObraL410ProofService} → returns the evidence path for the grinder to route to
 * the obra bridge (PARK for operator review).
 *
 * FAIL-CLOSED + HONEST at every step:
 *  - self-target re-guard over EVERY allowed file before any execution (pétreo, defense-in-depth);
 *  - a non-done / non-certified / partial / main-touched obra → discardObra + ok=false (clean loop-back);
 *  - the L4-10 gate trusts ONLY the sealed provenance: a FIXTURE run (execution_mode='fixture_obra_run')
 *    is REJECTED — so the deterministic by-construction path proves the MACHINERY but can never park as
 *    real work. Only a real ProviderObraNodeDelivery run earns a parkable L4-10.
 *
 * The provider RELIABLY producing a correct multi-file change is EMPIRICAL (measured by parked-obra
 * acceptance over live runs); this adapter makes a provider failure a clean discard+loop-back no-op,
 * never a fabricated success. Default-OFF lane (the grinder co-gates it); auto-merge stays separate/OFF.
 *
 * ITEM8 NOTE: this class is NO LONGER `final` so a test double can override the two PROTECTED provider
 * seams (generateSpecViaProvider / generatePlanViaProvider) to exercise the full real planning machinery
 * (AtlasLoopIntentSpecCompiler -> AtlasLoopObraDecompositionPlanner -> AtlasLoopPlanReadinessGate) with a
 * fake-but-ready spec/DAG and ZERO provider spend. No behaviour changes for production callers.
 */
class AtlasLoopObraExecutionAdapter
{
    public function __construct(
        private readonly ?AtlasLoopRefactorObraL410ProofService $l410 = null,
        private readonly ?AtlasLoopHarnessGuard $guard = null,
        private readonly ?AtlasLoopSemanticImplementationCertifier $certifier = null,
        private readonly ?AtlasLoopIntentSpecCompiler $specCompiler = null,
        private readonly ?AtlasLoopObraDecompositionPlanner $planner = null,
    ) {}

    /**
     * @param  array<string,mixed>  $payload  the AtlasLoopMultiFileRefactorSynthesizer task payload
     * @param  ObraNodeDelivery|null  $delivery  live: ProviderObraNodeDelivery (default); tests inject a fixture
     * @return array{ok:bool, reason:?string, envelope:?array<string,mixed>, l4_10:?array<string,mixed>, l4_10_evidence_path:?string, branch:?string}
     */
    public function executeAndProve(array $payload, ?ObraNodeDelivery $delivery = null): array
    {
        $allowed = $this->normAllowed($payload['allowed_files'] ?? []);
        if (count($allowed) < 2) {
            return $this->fail('not_multi_file');
        }
        $guard = $this->guard ?? new AtlasLoopHarnessGuard;
        foreach ($allowed as $f) {
            if ($guard->isForbiddenSelfTarget($f)) {
                return $this->fail('forbidden_self_target:'.$f);
            }
        }

        $repoRoot = rtrim((string) ($payload['target_repo_path'] ?? base_path()), '/');
        if (! is_dir($repoRoot.'/.git')) {
            return $this->fail('repo_root_not_a_git_tree');
        }

        $delivery ??= app(ProviderObraNodeDelivery::class);

        // ITEM8 — PLANNING PHASE (default-OFF). For a qualifying task, compile the intent into a
        // falsifiable spec then decompose into a readiness-gated DAG with a create-class node at
        // seq 0 for the NEW file. Any new file is folded into $allowed so the validator scope, the
        // L4-10 delivered_files census, and the aggregate-drop all admit it. OFF/non-qualifying =>
        // buildPlan() exactly as today (byte-identical).
        $planned = $this->maybePlan($payload, $allowed);
        if ($planned !== null) {
            $plan = $planned['plan'];
            $allowed = $planned['allowed']; // includes any planner-introduced new file(s)
            $newFiles = $planned['new_files']; // for the structural aggregate-drop lane
        } else {
            $plan = $this->buildPlan($payload, $allowed);
            $newFiles = [];
        }
        $planId = (string) $plan['plan_id'];

        // PLAN-READINESS GATE — "plan impeccably, THEN implement". Do not spend the EXPENSIVE
        // implementation budget on a plan that is not structurally sound + fully specified +
        // pre-verified (every node names its target, states a concrete change, and carries an
        // acceptance defined UP FRONT). A weak plan REPLANS (cheap), never builds-and-discards
        // (expensive). This is the structure that makes the loop almost never waste tokens.
        $readiness = (new AtlasLoopPlanReadinessGate(new AtlasLoopObraPlanValidator($this->guard)))->assess($plan, $allowed);
        if (($readiness['ready'] ?? false) !== true) {
            return $this->fail('plan_not_ready_'.((string) ($readiness['decision'] ?? 'replan')).':'.implode(',', array_slice((array) ($readiness['gaps'] ?? []), 0, 4)));
        }

        $executor = new AtlasObraExecutor($delivery, new GovernedBranchMaterializationService);

        $evidencePath = '';
        try {
            $opts = array_filter([
                'repo_dir' => $repoRoot,
                'provider' => 'hermes_cli',
                'integrated_check' => $this->integratedCheck($payload),
                'no_brain' => true,
            ], static fn ($v): bool => $v !== null && $v !== '');
            // REPAIR (eixo-3, default-OFF): bounded per-node retry-with-feedback inside execute().
            $opts['repair'] = [
                'enabled' => (bool) config('atlas.loop.obra_repair_enabled', false),
                'maxPerNode' => (int) config('atlas.loop.obra_repair_max_attempts_per_node', 3),
                'maxPerObra' => (int) config('atlas.loop.obra_repair_max_attempts_per_obra', 8),
            ];
            // The integrated whole-obra test must honour the obra's DECLARED budget (the multi-file
            // refactor synthesizer sets acceptance.timeout_seconds, default 600), not the materializer's
            // 120s git constant — else a legitimately-large assembled suite times out and the good
            // branch is discarded as "integration unrunnable". (measureObra hard-caps at 3600s.)
            $acc = is_array($payload['acceptance'] ?? null) ? (array) $payload['acceptance'] : [];
            $opts['integrated_check_timeout'] = max(60, (int) ($acc['timeout_seconds'] ?? config('atlas.loop.multi_file_refactor_timeout_seconds', 600)));
            $envelope = $executor->execute($plan, $opts);

            // FAIL-CLOSED: the whole obra must be genuinely done, certified, every node delivered, and
            // main byte-identical — anything else discards the branch and loops back honestly.
            $done = ($envelope['status'] ?? '') === AtlasObraExecutor::STATUS_DONE
                && ($envelope['certified'] ?? false) === true
                && (int) ($envelope['delivered_nodes'] ?? 0) === (int) ($envelope['node_count'] ?? -1)
                && ($envelope['main_untouched'] ?? false) === true;
            if (! $done) {
                $executor->discardObra($repoRoot, $planId);

                return $this->fail('obra_not_certified:'.(string) ($envelope['status'] ?? 'unknown'), $envelope);
            }

            // AGGREGATE-DROP CERTIFY (the refactor's POINT): the executor's integrated check proved
            // BEHAVIOUR preserved, NOT that complexity dropped. Replay the obra net diff into a fresh
            // worktree cut at BASE_HEAD (never live HEAD — the soak's single-file auto-merger may have
            // advanced main between openObra and now) and re-measure the SCOPED aggregate AST drop.
            // A refactor that ran green but did not reduce complexity is REFUSED (discard + loop-back).
            $drop = $this->certifyAggregateDrop($repoRoot, $envelope, $allowed, $newFiles);
            if (($drop['reduced'] ?? false) !== true) {
                $executor->discardObra($repoRoot, $planId);

                return $this->fail('aggregate_complexity_not_reduced:'.(string) ($drop['reason'] ?? '?'), $envelope);
            }

            // L4-10 — emit the signed evidence from the executor receipt, then validate provenance.
            // A FIXTURE run is sealed fixture_obra_run → rejected here (it proved the machinery, not real work).
            $evidencePath = $this->writeEvidence($envelope);
            $l410 = ($this->l410 ?? new AtlasLoopRefactorObraL410ProofService)->report([
                'evidence_path' => $evidencePath,
                'allowed_files' => $allowed,
            ]);
            if (($l410['certified'] ?? false) !== true) {
                @File::delete($evidencePath);
                $executor->discardObra($repoRoot, $planId);

                return $this->fail('l4_10_not_real:'.(string) ($l410['status'] ?? '?'), $envelope, $l410);
            }

            return [
                'ok' => true,
                'reason' => null,
                'envelope' => $envelope,
                'l4_10' => $l410,
                'l4_10_evidence_path' => $evidencePath,
                'branch' => $this->stringOrNull($envelope['branch'] ?? null),
            ];
        } catch (Throwable $e) {
            if ($evidencePath !== '') {
                @File::delete($evidencePath);
            }
            try {
                $executor->discardObra($repoRoot, $planId);
            } catch (Throwable) {
                // best-effort cleanup; the branch is discardable, never merged
            }

            return $this->fail('execution_error:'.mb_substr($e->getMessage(), 0, 120));
        }
    }

    /**
     * Replay the obra net diff (base_head..branch) into a fresh worktree cut at BASE_HEAD and
     * re-measure the SCOPED aggregate AST drop via the certifier. The obra branch is committed, so
     * the certifier (which measures an UNSTAGED dirty tree) can't read it directly — the replay
     * materializes the net diff as an unstaged change it CAN measure. Cuts at base_head, not live
     * HEAD, so a concurrent single-file auto-merge to main never drifts the baseline.
     *
     * ITEM8: $newFiles carries any planner-introduced NEW file (no committed baseline). $allowed is
     * ALREADY extended with them by maybePlan, so the diff-name-only census + scope check admit them.
     * DEVIATION (depends_on): measureScopedComplexityDrop hardcodes the NON-structural complexityReduced
     * lane, which has a new-file-lock that refuses files with no committed baseline. There is no public
     * structural variant on the certifier today, so a PURE create-class+redirect obra is still refused
     * here ('aggregate_complexity_not_reduced'). Until a public structural aggregate-drop ships, item8 is
     * sound for refactor_ clusters that reuse existing allowed files (no new file); the create-class
     * capability lands once the structural lane is public. We thread $newFiles to make that contract
     * explicit and to keep the signature ready for the structural route.
     *
     * @param  list<string>  $allowed
     * @param  list<string>  $newFiles  planner-introduced new files (no committed baseline)
     * @return array{reduced:bool, reason:?string, proof:array<string,mixed>|null}
     */
    private function certifyAggregateDrop(string $repoRoot, array $envelope, array $allowed, array $newFiles = []): array
    {
        $branch = (string) ($envelope['branch'] ?? '');
        $baseHead = (string) (($envelope['executor_receipt']['base_head'] ?? '') ?: '');
        if ($branch === '' || $baseHead === '') {
            return ['reduced' => false, 'reason' => 'no_branch_or_base_head', 'proof' => null];
        }

        $changed = $this->gitLines($repoRoot, ['diff', '--name-only', $baseHead.'..'.$branch]);
        $diff = $this->gitOutput($repoRoot, ['diff', $baseHead.'..'.$branch]);
        if ($diff === null || trim($diff) === '' || $changed === []) {
            return ['reduced' => false, 'reason' => 'empty_obra_diff', 'proof' => null];
        }

        $ws = sys_get_temp_dir().'/atlas-obra-replay-'.bin2hex(random_bytes(5));
        if (! $this->git($repoRoot, ['worktree', 'add', '--detach', $ws, $baseHead])) {
            return ['reduced' => false, 'reason' => 'replay_worktree_add_failed', 'proof' => null];
        }
        try {
            $apply = new Process(['git', 'apply', '--whitespace=nowarn', '-'], $ws, null, null, 60.0);
            $apply->setInput($diff);
            $apply->run();
            if (! $apply->isSuccessful()) {
                return ['reduced' => false, 'reason' => 'replay_apply_failed', 'proof' => null];
            }
            $drop = ($this->certifier ?? app(AtlasLoopSemanticImplementationCertifier::class))
                ->measureScopedComplexityDrop($ws, $changed, $allowed);

            $reduced = (bool) ($drop['reduced'] ?? false);
            $reason = $reduced
                ? null
                : (($drop['scope_violation'] ?? []) !== [] ? 'changed_files_outside_allowed' : 'not_reduced');

            return ['reduced' => $reduced, 'reason' => $reason, 'proof' => $drop['proof'] ?? null];
        } finally {
            $this->git($repoRoot, ['worktree', 'remove', '--force', $ws]);
        }
    }

    /** @param  list<string>  $argv */
    private function git(string $repoRoot, array $argv): bool
    {
        $p = new Process(array_merge(['git', '-C', $repoRoot], $argv), null, null, null, 60.0);
        $p->run();

        return $p->isSuccessful();
    }

    /** @param  list<string>  $argv */
    private function gitOutput(string $repoRoot, array $argv): ?string
    {
        $p = new Process(array_merge(['git', '-C', $repoRoot], $argv), null, null, null, 60.0);
        $p->run();

        return $p->isSuccessful() ? $p->getOutput() : null;
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private function gitLines(string $repoRoot, array $argv): array
    {
        $out = $this->gitOutput($repoRoot, $argv);
        if ($out === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\R/', $out) ?: []), static fn (string $l): bool => $l !== ''));
    }

    /**
     * One node per allowed file (node_count>=2 — required by the L4-10 proof + the auto-merge verifier).
     * The hub (target_repo_path's own file, if present in allowed) leads (seq 0).
     *
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $allowed
     * @return array<string,mixed>
     */
    private function buildPlan(array $payload, array $allowed): array
    {
        $hub = ltrim((string) ($payload['target_relative_path'] ?? $payload['hub'] ?? ''), '/');
        usort($allowed, static fn (string $a, string $b): int => ($a === $hub ? 0 : 1) <=> ($b === $hub ? 0 : 1));

        $objective = trim((string) ($payload['objective'] ?? 'Reduce the cyclomatic complexity of the cluster, preserving behaviour.'));
        $clusterKey = (string) ($payload['cluster_hash'] ?? hash('sha256', implode('|', $allowed)));
        $nodes = [];
        foreach ($allowed as $i => $file) {
            $nodes[] = [
                'id' => 'node-'.substr(hash('sha256', $clusterKey.'|'.$file), 0, 16),
                'seq' => $i,
                'title' => 'refactor '.basename($file),
                'request' => $objective."\n\nEdit ONLY ".$file.' as part of cluster '.$hub
                    .'; reduce its worst-method cyclomatic complexity; PRESERVE behaviour exactly (the frozen sibling tests must stay green).',
                'target_area' => $file,
                'depends_on' => [],
                'brain_refs' => [],
                // Each refactor node is verified by the obra-level complexity-proof + integrated check.
                'complexity_proof' => true,
            ];
        }

        return [
            'plan_id' => 'obra-loop-'.substr(hash('sha256', $clusterKey), 0, 16),
            'workspace_id' => $clusterKey,
            'nodes' => $nodes,
        ];
    }

    /**
     * ITEM8 — when planning is armed AND the task qualifies, compile the intent into a falsifiable
     * spec then decompose into a readiness-gated DAG. Returns null (=> fall back to buildPlan) when
     * the flag is OFF, the task does not qualify, the goal is empty, the spec is not ready, or the
     * planner could not produce a READY plan. Never throws — a refusal is a cheap buildPlan fallback,
     * never a parked vague obra. With the flag OFF this short-circuits BEFORE any new code runs, so the
     * adapter path is byte-identical to today.
     *
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $allowed
     * @return array{plan:array<string,mixed>, allowed:list<string>, new_files:list<string>}|null
     */
    protected function maybePlan(array $payload, array $allowed): ?array
    {
        if (! (bool) config('atlas.loop.planning_enabled', false)) {
            return null;
        }
        $kind = trim((string) ($payload['objective_kind'] ?? ''));
        $qualifies = count($allowed) >= 2 || str_starts_with($kind, 'refactor_') || str_starts_with($kind, 'feature_');
        if (! $qualifies) {
            return null;
        }
        $goal = trim((string) ($payload['objective'] ?? ''));
        if ($goal === '') {
            return null;
        }

        // 1) INTENT -> SPEC (iterate-to-ready). generateSpec is hermes_cli spec-only in production;
        //    injected (via the protected provider seam) in tests. fn(string $goal, list<string> $priorGaps): array.
        $compiler = $this->specCompiler ?? new AtlasLoopIntentSpecCompiler;
        $spec = $compiler->compile($goal, $this->specGenerator($payload, $allowed), (int) config('atlas.loop.planning_spec_max_attempts', 3));
        if (($spec['ready'] ?? false) !== true || ! is_array($spec['spec'] ?? null)) {
            return null; // refuse-with-gaps -> fall back to buildPlan (never park a vague obra)
        }

        // 2) DECOMPOSE -> DAG (iterate-to-ready against the REAL readiness gate). generatePlan is
        //    fn(string $goal, array $context, list<string> $priorGaps): array. The generator emits a
        //    create-class node at seq 0 for any NEW file + redirect-caller nodes at higher seq.
        $guard = $this->guard ?? new AtlasLoopHarnessGuard;
        $planner = $this->planner ?? new AtlasLoopObraDecompositionPlanner(
            new AtlasLoopPlanReadinessGate(new AtlasLoopObraPlanValidator($guard))
        );
        $newFiles = $this->plannedNewFiles($spec['spec'], $allowed);
        $planAllowed = array_values(array_unique(array_merge($allowed, $newFiles)));
        $out = $planner->plan($goal, ['spec' => $spec['spec']], $planAllowed, $this->planGenerator($payload, $allowed, $newFiles, $spec['spec']), (int) config('atlas.loop.planning_plan_max_attempts', 3));
        if (($out['ready'] ?? false) !== true || ! is_array($out['plan'] ?? null)) {
            return null; // planner refused -> buildPlan fallback (cheap; never spend on an ill-formed plan)
        }

        // The executor walks nodes by SEQ ascending (AtlasObraExecutor::execute), NOT by depends_on —
        // so the create-class node MUST carry seq=0 (the planGenerator assigns it).
        return ['plan' => $out['plan'], 'allowed' => $planAllowed, 'new_files' => $newFiles];
    }

    /**
     * NEW files the spec wants created (suggested_files) that are not yet in $allowed. Only .php paths.
     *
     * @param  array<string,mixed>  $spec
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function plannedNewFiles(array $spec, array $allowed): array
    {
        $allow = array_flip(array_map(static fn (string $f): string => ltrim($f, '/'), $allowed));
        $out = [];
        foreach ((array) ($spec['suggested_files'] ?? []) as $f) {
            $n = ltrim(trim((string) $f), '/');
            if ($n !== '' && str_ends_with($n, '.php') && ! isset($allow[$n])) {
                $out[$n] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Spec generator. PRODUCTION: hermes_cli spec-only (deterministic JSON). The provider seam is the
     * protected generateSpecViaProvider() so a test can override it with a fake-but-ready spec.
     *
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $allowed
     * @return callable(string, list<string>): array<string,mixed>
     */
    private function specGenerator(array $payload, array $allowed): callable
    {
        return function (string $goal, array $priorGaps) use ($payload, $allowed): array {
            return $this->generateSpecViaProvider($goal, $priorGaps, $payload, $allowed);
        };
    }

    /**
     * Plan generator. PRODUCTION: hermes_cli DAG-only. Emits create-class @ seq 0 + redirect @ higher
     * seq. The provider seam is the protected generatePlanViaProvider() so a test can override it.
     *
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $allowed
     * @param  list<string>  $newFiles
     * @param  array<string,mixed>  $spec
     * @return callable(string, array<string,mixed>, list<string>): array<string,mixed>
     */
    private function planGenerator(array $payload, array $allowed, array $newFiles, array $spec): callable
    {
        return function (string $goal, array $context, array $priorGaps) use ($payload, $allowed, $newFiles, $spec): array {
            return $this->generatePlanViaProvider($goal, $context, $priorGaps, $payload, $allowed, $newFiles, $spec);
        };
    }

    /**
     * PRODUCTION provider seam — spec-only hermes_cli call. FAIL-OPEN: there is no obvious clean
     * spec-only seam on this adapter today (ProviderObraNodeDelivery is node-delivery, not spec
     * synthesis), and wiring a bespoke hermes_cli spec call is a larger, riskier piece of work. So
     * this returns [] => the compiler refuses-with-gaps => maybePlan returns null => buildPlan
     * fallback => SAFE even with the flag ON (never breaks). Tests override this to inject a ready
     * spec and exercise the full planning machinery with no provider.
     *
     * TODO(item8 follow-up): wire a real hermes_cli spec-only invocation through the AiProviderManager
     * seam, parsing deterministic JSON into {summary, acceptance_criteria, suggested_files, decomposition_hint}.
     *
     * @param  list<string>  $priorGaps
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $allowed
     * @return array<string,mixed>
     */
    protected function generateSpecViaProvider(string $goal, array $priorGaps, array $payload, array $allowed): array
    {
        return [];
    }

    /**
     * PRODUCTION provider seam — DAG-only hermes_cli call. FAIL-OPEN (same rationale as the spec seam):
     * returns [] => the planner normalises to an empty-node plan the readiness gate rejects => maybePlan
     * returns null => buildPlan fallback => SAFE even with the flag ON. Tests override this to inject a
     * valid create-class-at-seq-0 DAG and exercise the planner + readiness gate with no provider.
     *
     * TODO(item8 follow-up): wire a real hermes_cli DAG-only invocation; emit the create-class node at
     * seq 0 (its request MUST literally reference the new file path/basename or the readiness gate flags
     * 'request_does_not_reference_its_target') + redirect-caller nodes at higher seq with depends_on.
     *
     * @param  array<string,mixed>  $context
     * @param  list<string>  $priorGaps
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $allowed
     * @param  list<string>  $newFiles
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    protected function generatePlanViaProvider(string $goal, array $context, array $priorGaps, array $payload, array $allowed, array $newFiles, array $spec): array
    {
        return [];
    }

    /** The whole-obra integrated check = the synthesizer's frozen sibling-test command (behaviour gate). */
    private function integratedCheck(array $payload): ?string
    {
        $acc = is_array($payload['acceptance'] ?? null) ? (array) $payload['acceptance'] : [];
        $cmds = is_array($acc['commands'] ?? null) ? array_values((array) $acc['commands']) : [];
        $first = isset($cmds[0]) ? trim((string) $cmds[0]) : '';

        return $first !== '' ? $first : null;
    }

    /** Write the HMAC-signed L4-10 evidence envelope (the executor receipt is the load-bearing part). */
    private function writeEvidence(array $envelope): string
    {
        $receipt = is_array($envelope['executor_receipt'] ?? null) ? (array) $envelope['executor_receipt'] : [];
        $path = storage_path('framework/obra-l410/'.Str::uuid().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'schema_version' => AtlasLoopRefactorObraL410ProofService::SCHEMA_VERSION,
            'obra_id' => $envelope['plan_id'] ?? null,
            'status' => $envelope['status'] ?? null,
            'certified' => $envelope['certified'] ?? false,
            'executor_receipt' => $receipt,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /**
     * @param  mixed  $files
     * @return list<string>
     */
    private function normAllowed($files): array
    {
        $out = [];
        foreach ((array) $files as $f) {
            if (is_string($f) && trim($f) !== '') {
                $out[ltrim(trim($f), '/')] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * @return array{ok:bool, reason:string, envelope:?array<string,mixed>, l4_10:?array<string,mixed>, l4_10_evidence_path:?string, branch:?string}
     */
    private function fail(string $reason, ?array $envelope = null, ?array $l410 = null): array
    {
        return ['ok' => false, 'reason' => $reason, 'envelope' => $envelope, 'l4_10' => $l410, 'l4_10_evidence_path' => null, 'branch' => null];
    }

    private function stringOrNull(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }
}
