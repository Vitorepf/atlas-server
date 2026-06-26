<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopIntentSpecCompiler;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopNodeInterfaceContract;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopNodeInterfaceVerifier;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraDecompositionPlanner;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraPlanValidator;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopVaguenessPreScreener;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopPlanReadinessGate;
use App\Services\Ai\Obra\AtlasObraExecutor;
use App\Services\Ai\Obra\ObraNodeDelivery;
use App\Services\Ai\Obra\ProviderObraNodeDelivery;
use App\Services\Ai\AutonomousEvolution\Support\AtlasLoopObraGitWorktreeProbe;
use App\Services\Ai\RealExecution\AtlasLiveCodeDeliveryService;
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
        // ACDE Leap 1 — the provider manager the planner seam runs the spec/DAG calls through. Nullable +
        // last: Laravel does not autowire a nullable-default param, so the use-site falls back to app().
        private readonly ?AiProviderManager $providers = null,
        // ACDE Leap 5 — the decomposition outcome corpus recorder. Nullable + last (use-site falls back to a
        // fresh instance). No-op when atlas.loop.decomposition_corpus_enabled is OFF (byte-identical).
        private readonly ?AtlasLoopDecompositionOutcomeRecorder $outcomeRecorder = null,
        // §11.3 EARNED-RED — the producer that proves a behavior-changing obra's frozen acceptance is RED
        // against the pre-implementation tree. Nullable + last (the gate falls back to an inline `new`).
        private readonly ?\App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopAcceptanceRedProducer $acceptanceRedProducer = null,
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

        // §11.3 EARNED-RED GATE — for a behavior-CHANGING obra (feature_*/bug_fix), the obra's frozen
        // acceptance must be provably RED against the PRE-implementation tree, else there is no bar to
        // earn: a green-on-arrival command is a no-op the implementation satisfies by changing nothing
        // (the Goodhart hole the bug-fix lane closed). refactor_* obras are behavior-PRESERVING — their
        // acceptance is a frozen sibling test that must stay GREEN — so they are deliberately EXEMPT.
        // Default ON; only engages when the obra is behavior-changing AND names a runnable command.
        // §11.3 EARNED-RED GATE — for a behavior-CHANGING obra (feature_*/bug_fix), the obra's frozen
        // acceptance must be provably RED against the PRE-implementation tree, else there is no bar to
        // earn: a green-on-arrival command is a no-op the implementation satisfies by changing nothing
        // (the Goodhart hole the bug-fix lane closed). refactor_* obras are behavior-PRESERVING — their
        // acceptance is a frozen sibling test that must stay GREEN — so they are deliberately EXEMPT.
        // Default ON; only engages when the obra is behavior-changing AND names a runnable command.
        if ((bool) config('atlas.loop.obra_earned_red_enabled', true)) {
            $kind = trim((string) ($payload['objective_kind'] ?? ''));
            $behaviorChanging = str_starts_with($kind, 'feature_') || $kind === 'bug_fix';
            $acc = is_array($payload['acceptance'] ?? null) ? (array) $payload['acceptance'] : [];
            $cmds = array_values(array_filter((array) ($acc['commands'] ?? []), static fn ($c): bool => is_string($c) && trim($c) !== ''));
            if ($behaviorChanging && $cmds !== []) {
                $earned = ($this->acceptanceRedProducer ?? new \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopAcceptanceRedProducer)
                    ->toEarnedRedAcceptance(['objective' => (string) ($payload['objective'] ?? ''), 'acceptance_command' => $cmds[0]], $repoRoot);
                if ($earned === null || ($earned['earned_red'] ?? false) !== true) {
                    return $this->fail('acceptance_not_earned_red:'.$kind);
                }
            }
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
        // The goal is threaded so the readiness gate can consult the Leap 2 boundary-oracle for this
        // objective; with the oracle flag OFF / no fixture for the goal the extra arg is inert (byte-identical).
        $readiness = (new AtlasLoopPlanReadinessGate(new AtlasLoopObraPlanValidator($this->guard)))->assess($plan, $allowed, trim((string) ($payload['objective'] ?? '')));
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
                $this->recordShapeOutcome($plan, $payload, false, 'obra_not_certified:'.(string) ($envelope['status'] ?? 'unknown'));
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
                $this->recordShapeOutcome($plan, $payload, false, 'aggregate_complexity_not_reduced:'.(string) ($drop['reason'] ?? '?'));
                $executor->discardObra($repoRoot, $planId);

                return $this->fail('aggregate_complexity_not_reduced:'.(string) ($drop['reason'] ?? '?'), $envelope);
            }

            // ACDE Leap 3 — OBRA NET-DIFF FULL CERTIFICATION. The aggregate-drop above proved the refactor
            // reduced complexity; it did NOT run the single-target anti-gaming stack (behavioral-equivalence
            // floor, overfit probe, diff-earned, mutation, completeness, cross-node consumer contracts) on
            // the ASSEMBLED multi-file diff — the exact surface where large-obra gaming hides (a node games
            // one piece while another quietly breaks a sibling). When the flag is ON, route the obra net diff
            // through the FULL certify() against the obra's HUMAN-FROZEN acceptance; any refusal discards the
            // branch + loops back honestly (never merges). Flag OFF (default) => this block is skipped, so the
            // obra path is byte-identical to today. Fail-OPEN on its own infra (no acceptance / empty diff /
            // replay failure narrows coverage, never false-rejects a real obra — same honest limit as today).
            if ((bool) config('atlas.loop.obra_full_cert_enabled', false)) {
                $netCert = $this->certifyObraNetDiff($repoRoot, $envelope, $allowed, $acc, trim((string) ($payload['objective'] ?? '')));
                if (($netCert['certified'] ?? false) !== true) {
                    $this->recordShapeOutcome($plan, $payload, false, 'obra_net_diff_refused:'.(string) ($netCert['reason'] ?? '?'));
                    $executor->discardObra($repoRoot, $planId);

                    return $this->fail('obra_net_diff_refused:'.(string) ($netCert['reason'] ?? '?'), $envelope);
                }
            }

            // ACDE Leap 6 (design-judgement ceiling) — HUMAN-FROZEN NODE-INTERFACE CONTRACT. The boundary-
            // oracle proved the right FILES are separate nodes; this proves the right ABSTRACTION inside them.
            // When the flag is ON and a human froze an interface contract for this objective, replay the net
            // diff and verify each contracted file's REAL AST surface (decorrelated from the provider LLM)
            // honours it — required methods/implements/extends present, forbidden imports absent. A violation
            // discards + loops back. OFF / no contract for the goal => no check (byte-identical to today).
            if ((bool) config('atlas.loop.interface_contract_enabled', false)) {
                $iface = $this->certifyNodeInterfaces($repoRoot, $envelope, trim((string) ($payload['objective'] ?? '')));
                if (($iface['ok'] ?? false) !== true) {
                    $this->recordShapeOutcome($plan, $payload, false, 'interface_contract_violated:'.(string) ($iface['reason'] ?? '?'));
                    $executor->discardObra($repoRoot, $planId);

                    return $this->fail('interface_contract_violated:'.(string) ($iface['reason'] ?? '?'), $envelope);
                }
            }

            // ACDE Leap 7 (spec/completeness ceiling) — CHANGED-PUBLIC-SYMBOL COVERAGE CENSUS. A changed public
            // symbol with no covering test certifies fail-open today; with the flag ON, replay the obra net diff
            // and refuse it if any public method declared in the diff's added lines is NOT named by the frozen
            // acceptance's test corpus (refuse-until-named). OFF => byte-identical. Fail-OPEN on replay infra.
            if ((bool) config('atlas.loop.changed_symbol_census_enabled', false)) {
                $census = $this->censusObraSymbols($repoRoot, $envelope, $acc);
                if (($census['ok'] ?? false) !== true) {
                    $this->recordShapeOutcome($plan, $payload, false, 'changed_symbol_uncovered:'.(string) ($census['reason'] ?? '?'));
                    $executor->discardObra($repoRoot, $planId);

                    return $this->fail('changed_symbol_uncovered:'.(string) ($census['reason'] ?? '?'), $envelope);
                }
            }

            // L4-10 — emit the signed evidence from the executor receipt, then validate provenance.
            // A FIXTURE run is sealed fixture_obra_run → rejected here (it proved the machinery, not real work).
            $evidencePath = $this->writeEvidence($envelope);
            $l410 = ($this->l410 ?? new AtlasLoopRefactorObraL410ProofService)->report([
                'evidence_path' => $evidencePath,
                'allowed_files' => $allowed,
            ]);
            if (($l410['certified'] ?? false) !== true) {
                $this->recordShapeOutcome($plan, $payload, false, 'l4_10_not_real:'.(string) ($l410['status'] ?? '?'));
                @File::delete($evidencePath);
                $executor->discardObra($repoRoot, $planId);

                return $this->fail('l4_10_not_real:'.(string) ($l410['status'] ?? '?'), $envelope, $l410);
            }

            // CERTIFIED terminal — the assembled obra cleared the whole frozen-bar stack. Record the shape's
            // success so the corpus learns which decompositions land (Leap 5; no-op when the corpus is OFF).
            $this->recordShapeOutcome($plan, $payload, true, 'certified');

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
    protected function certifyAggregateDrop(string $repoRoot, array $envelope, array $allowed, array $newFiles = []): array
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
            $certifier = $this->certifier ?? app(AtlasLoopSemanticImplementationCertifier::class);
            // ACDE Leap 1 — STRUCTURAL lane (create-class / extract-class): when the obra introduced a
            // net-new file AND the per-method-identity gate is ON, route to the anti-relocation per-identity
            // census so a legitimate create-class+redirect obra is provable instead of refused by the
            // new-file-locked default lane. Flag OFF (default) OR no net-new file => the default
            // measureScopedComplexityDrop runs EXACTLY as before (byte-identical). The added-file set is the
            // ground truth from the net diff (--diff-filter=A), not the planner's declaration.
            $added = $this->gitLines($repoRoot, ['diff', '--name-only', '--diff-filter=A', $baseHead.'..'.$branch]);
            $useStructural = $added !== [] && (bool) config('atlas.loop.complexity_method_identity_gate', false);
            $drop = $useStructural
                ? $certifier->measureScopedStructuralDrop($ws, $changed, $allowed, $added)
                : $certifier->measureScopedComplexityDrop($ws, $changed, $allowed);

            $reduced = (bool) ($drop['reduced'] ?? false);
            $reason = $reduced
                ? null
                : (($drop['scope_violation'] ?? []) !== [] ? 'changed_files_outside_allowed' : 'not_reduced');

            return ['reduced' => $reduced, 'reason' => $reason, 'proof' => $drop['proof'] ?? null];
        } finally {
            $this->git($repoRoot, ['worktree', 'remove', '--force', $ws]);
        }
    }

    /**
     * ACDE Leap 3 — route the ASSEMBLED obra net diff through the FULL single-target anti-gaming stack.
     *
     * Self-contained replay (its OWN base_head worktree, so {@see certifyAggregateDrop} stays byte-identical):
     * materialize the net diff (base_head..branch) as an UNSTAGED dirty tree at base_head — the EXACT input
     * {@see AtlasLoopSemanticImplementationCertifier::certify} consumes — then run certify() there against the
     * obra's HUMAN-FROZEN acceptance (payload.acceptance, never the model spec). certify() re-runs the FULL
     * frozen command set (not just the executor's integrated commands[0]), so a node that silently breaks a
     * SIBLING's frozen contract turns the assembled net diff RED even when the integrated check stayed green —
     * the defining large-obra failure mode (independently-green steps that conflict once assembled) becomes
     * provable end-to-end. The behavioral-equivalence floor, overfit probe, diff-earned, mutation-kill-ratio,
     * completeness resolver and cross-file consumer gate all apply to the net diff exactly as on a single target.
     *
     * Fail-OPEN on its own infra (no acceptance / empty diff / replay failure narrows coverage, never
     * false-rejects); fail-CLOSED on the moat (any certify() reason refuses the obra). Anchored on the
     * human-frozen acceptance + machine-derived consumer contracts — zero model claim.
     *
     * @param  list<string>  $allowed
     * @param  array<string,mixed>  $obraAcceptance  the obra's HUMAN-FROZEN payload.acceptance
     * @return array{certified:bool, reason:?string, receipt:array<string,mixed>|null}
     */
    protected function certifyObraNetDiff(string $repoRoot, array $envelope, array $allowed, array $obraAcceptance, string $objective = ''): array
    {
        // No human-frozen acceptance => nothing to certify the assembled diff against. Fail-OPEN: narrow
        // coverage (the aggregate-drop + per-node gates already passed), never refuse a real obra.
        if ($obraAcceptance === []) {
            return ['certified' => true, 'reason' => 'no_obra_acceptance', 'receipt' => null];
        }

        $branch = (string) ($envelope['branch'] ?? '');
        $baseHead = (string) (($envelope['executor_receipt']['base_head'] ?? '') ?: '');
        if ($branch === '' || $baseHead === '') {
            return ['certified' => true, 'reason' => 'no_branch_or_base_head', 'receipt' => null];
        }

        $changed = $this->gitLines($repoRoot, ['diff', '--name-only', $baseHead.'..'.$branch]);
        $diff = $this->gitOutput($repoRoot, ['diff', $baseHead.'..'.$branch]);
        if ($diff === null || trim($diff) === '' || $changed === []) {
            return ['certified' => true, 'reason' => 'empty_obra_diff', 'receipt' => null];
        }

        $ws = sys_get_temp_dir().'/atlas-obra-netcert-'.bin2hex(random_bytes(5));
        if (! $this->git($repoRoot, ['worktree', 'add', '--detach', $ws, $baseHead])) {
            return ['certified' => true, 'reason' => 'replay_worktree_add_failed', 'receipt' => null];
        }
        try {
            $apply = new Process(['git', 'apply', '--whitespace=nowarn', '-'], $ws, null, null, 60.0);
            $apply->setInput($diff);
            $apply->run();
            if (! $apply->isSuccessful()) {
                return ['certified' => true, 'reason' => 'replay_apply_failed', 'receipt' => null];
            }

            // The obra's HUMAN-FROZEN acceptance. The cross-node gate decision (force completeness_gate +
            // resolve the Code-Intelligence workspace) is a pure function of the changed-file count + flags
            // (ACDE #7) — extracted so it is testable without git infra.
            $gate = $this->obraCrossNodeGate($changed, $envelope, $repoRoot);
            $acceptance = $obraAcceptance;
            if ($gate['completeness_gate']) {
                $acceptance['completeness_gate'] = true;
            }

            $certifier = $this->certifier ?? app(AtlasLoopSemanticImplementationCertifier::class);
            $verdict = $certifier->certify($ws, $acceptance, array_filter([
                'allowed_files' => $allowed,
                'objective' => $objective,
                // Cross-node consumer contracts ride the existing cross-file gate when a Code-Intelligence
                // workspace is resolvable; absent => no cross-node criteria (fail-open, never a false-reject).
                'code_graph_workspace' => $gate['code_graph_workspace'],
            ], static fn ($v): bool => $v !== null && $v !== '' && $v !== []));

            $certified = (bool) ($verdict['certified'] ?? false);
            $reason = $certified
                ? null
                : implode(',', array_slice(array_values(array_filter(
                    (array) ($verdict['reasons'] ?? ['refuted']),
                    static fn ($r): bool => is_string($r) && $r !== '' && $r !== 'certified',
                )) ?: ['refuted'], 0, 4));

            return ['certified' => $certified, 'reason' => $reason, 'receipt' => $verdict];
        } finally {
            $this->git($repoRoot, ['worktree', 'remove', '--force', $ws]);
        }
    }

    /**
     * ACDE #7 — pure decision for the obra net-diff cross-node gate. Cross-node consumer criteria add the most
     * value exactly where large-obra gaming hides: a node that silently breaks a SIBLING's contract. So for a
     * MULTI-FILE net diff (>=2 changed files) we (a) force the completeness gate so certify() derives one
     * criterion per cross-node consumer contract, and (b) resolve the Code-Intelligence workspace, falling back
     * to the obra repo when the envelope omits one so the contracts POPULATE instead of fail-open-empty.
     *
     * Two engage paths: the global obra_completeness_gate_enabled (unchanged), OR the multi-file default
     * (obra_cross_node_cert_default, default-OFF => byte-identical). Single-file diffs are NEVER affected by the
     * new default. Fail-OPEN preserved: an unresolvable contract narrows coverage, never false-rejects.
     *
     * @param  list<string>  $changed
     * @param  array<string,mixed>  $envelope
     * @return array{completeness_gate:bool, code_graph_workspace:?string}
     */
    private function obraCrossNodeGate(array $changed, array $envelope, string $repoRoot): array
    {
        $multiFile = count($changed) >= 2;
        $crossNodeDefault = $multiFile && (bool) config('atlas.loop.obra_cross_node_cert_default', false);
        $completeness = (bool) config('atlas.loop.obra_completeness_gate_enabled', false) || $crossNodeDefault;

        $codeGraphWs = $this->stringOrNull($envelope['code_graph_workspace'] ?? null);
        if ($codeGraphWs === null && $crossNodeDefault) {
            $codeGraphWs = $this->stringOrNull($repoRoot);
        }

        return ['completeness_gate' => $completeness, 'code_graph_workspace' => $codeGraphWs];
    }

    /**
     * ACDE Leap 6 — verify the assembled obra's REAL AST surface against the HUMAN-frozen node-interface
     * contract for the objective. Replays the net diff (base_head..branch) into a base_head worktree so the
     * ACTUAL delivered files exist, then runs the decorrelated AST verifier per contracted file. No contract
     * for the goal => ok (degrade, no false-reject). Fail-OPEN on its own replay infra; the ONLY refusal is a
     * real interface_contract_violation (the bar is human-frozen, the surface is an AST census — ungameable).
     *
     * @return array{ok:bool, reason:?string, violations:list<string>}
     */
    protected function certifyNodeInterfaces(string $repoRoot, array $envelope, string $goal): array
    {
        $contract = (new AtlasLoopNodeInterfaceContract)->load($goal);
        if ($contract === null) {
            return ['ok' => true, 'reason' => 'no_interface_contract', 'violations' => []];
        }

        $branch = (string) ($envelope['branch'] ?? '');
        $baseHead = (string) (($envelope['executor_receipt']['base_head'] ?? '') ?: '');
        if ($branch === '' || $baseHead === '') {
            return ['ok' => true, 'reason' => 'no_branch_or_base_head', 'violations' => []];
        }

        $diff = $this->gitOutput($repoRoot, ['diff', $baseHead.'..'.$branch]);
        if ($diff === null || trim($diff) === '') {
            return ['ok' => true, 'reason' => 'empty_obra_diff', 'violations' => []];
        }

        $ws = sys_get_temp_dir().'/atlas-obra-iface-'.bin2hex(random_bytes(5));
        if (! $this->git($repoRoot, ['worktree', 'add', '--detach', $ws, $baseHead])) {
            return ['ok' => true, 'reason' => 'replay_worktree_add_failed', 'violations' => []];
        }
        try {
            $apply = new Process(['git', 'apply', '--whitespace=nowarn', '-'], $ws, null, null, 60.0);
            $apply->setInput($diff);
            $apply->run();
            if (! $apply->isSuccessful()) {
                return ['ok' => true, 'reason' => 'replay_apply_failed', 'violations' => []];
            }

            $violations = (new AtlasLoopNodeInterfaceVerifier)->verify($contract, static function (string $rel) use ($ws): ?string {
                $path = $ws.'/'.ltrim($rel, '/');

                return is_file($path) ? (string) @file_get_contents($path) : null;
            });

            if ($violations !== []) {
                return ['ok' => false, 'reason' => implode(',', array_slice($violations, 0, 4)), 'violations' => $violations];
            }

            return ['ok' => true, 'reason' => null, 'violations' => []];
        } finally {
            $this->git($repoRoot, ['worktree', 'remove', '--force', $ws]);
        }
    }

    /**
     * ACDE Leap 7 — run the changed-public-symbol coverage census on the assembled obra net diff. Replays the
     * net diff into a base_head worktree (actual delivered files + the obra's test files), then refuses the
     * obra if any public method declared in the diff's added lines is NOT named by the frozen acceptance's
     * test corpus. No acceptance commands / empty diff => ok (degrade, no false-reject). Fail-OPEN on replay
     * infra; the only refusal is a real uncovered new public symbol (refuse-until-named).
     *
     * @param  array<string,mixed>  $acceptance
     * @return array{ok:bool, reason:?string, census:array<string,mixed>|null}
     */
    protected function censusObraSymbols(string $repoRoot, array $envelope, array $acceptance): array
    {
        $commands = array_values(array_filter(
            (array) ($acceptance['commands'] ?? []),
            static fn ($c): bool => is_string($c) && trim($c) !== '',
        ));
        if ($commands === []) {
            return ['ok' => true, 'reason' => 'no_acceptance_commands', 'census' => null];
        }

        $branch = (string) ($envelope['branch'] ?? '');
        $baseHead = (string) (($envelope['executor_receipt']['base_head'] ?? '') ?: '');
        if ($branch === '' || $baseHead === '') {
            return ['ok' => true, 'reason' => 'no_branch_or_base_head', 'census' => null];
        }

        $diff = $this->gitOutput($repoRoot, ['diff', $baseHead.'..'.$branch]);
        if ($diff === null || trim($diff) === '') {
            return ['ok' => true, 'reason' => 'empty_obra_diff', 'census' => null];
        }

        $ws = sys_get_temp_dir().'/atlas-obra-census-'.bin2hex(random_bytes(5));
        if (! $this->git($repoRoot, ['worktree', 'add', '--detach', $ws, $baseHead])) {
            return ['ok' => true, 'reason' => 'replay_worktree_add_failed', 'census' => null];
        }
        try {
            $apply = new Process(['git', 'apply', '--whitespace=nowarn', '-'], $ws, null, null, 60.0);
            $apply->setInput($diff);
            $apply->run();
            if (! $apply->isSuccessful()) {
                return ['ok' => true, 'reason' => 'replay_apply_failed', 'census' => null];
            }

            $census = (new AtlasLoopChangedSymbolCoverageCensus)->evaluate($ws, $commands);
            if (($census['passed'] ?? false) !== true) {
                return ['ok' => false, 'reason' => implode(',', array_slice((array) ($census['uncovered'] ?? []), 0, 4)), 'census' => $census];
            }

            return ['ok' => true, 'reason' => null, 'census' => $census];
        } finally {
            $this->git($repoRoot, ['worktree', 'remove', '--force', $ws]);
        }
    }

    /**
     * Cohesive stateless git probe — the only places the adapter shells out to `git -C <repo>` for the
     * replay-worktree lanes (aggregate-drop, net-diff full cert, node-interface contract, changed-symbol
     * census). Extracted to {@see AtlasLoopObraGitWorktreeProbe}; we keep these three private methods as
     * thin delegators so every existing call site (4 separate gates) stays byte-identical and the public
     * signature of the adapter does not move. Lazy-instantiated per call so the field stays nullable-default
     * (Laravel does not autowire a nullable-default param) and production callers pay no construction cost
     * beyond the first use.
     *
     * @param  list<string>  $argv
     * @return list<string>
     */
    private function gitProbe(): AtlasLoopObraGitWorktreeProbe
    {
        return new AtlasLoopObraGitWorktreeProbe;
    }

    /** @param  list<string>  $argv */
    private function git(string $repoRoot, array $argv): bool
    {
        return $this->gitProbe()->git($repoRoot, $argv);
    }

    /** @param  list<string>  $argv */
    private function gitOutput(string $repoRoot, array $argv): ?string
    {
        return $this->gitProbe()->gitOutput($repoRoot, $argv);
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private function gitLines(string $repoRoot, array $argv): array
    {
        return $this->gitProbe()->gitLines($repoRoot, $argv);
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

        // ACDE U6 — fold a CACHED operator clarification into the goal BEFORE screening/planning. If the
        // operator already answered this exact goal (U5 queue), the loop must never re-ask: the answer (which
        // carries the missing anchor) is appended so the goal now passes the vagueness screen and compiles a
        // richer spec. Default OFF => goal unchanged => byte-identical.
        $goal = $this->withCachedClarification($goal);

        // ACDE U4 — vagueness pre-screen: a goal with NO concrete anchor cannot be planned correctly by a weak
        // model; skip the expensive intent->spec->DAG planner (fall back to buildPlan) rather than grind a vague
        // directive, and surface the abstention. Default OFF => no screen => byte-identical.
        if ((bool) config('atlas.loop.vagueness_prescreen_enabled', false)
            && (new AtlasLoopVaguenessPreScreener)->screen($goal)['vague'] === true) {
            $this->emitPlanAbstention($payload, 'vague_goal_no_anchor');

            return null;
        }

        // ACDE DC4 — change-class landing prior: a change CLASS (objective_kind family) that empirically never
        // certifies on this engine should ASK the operator (escalate-not-burn), not grind yet another obra of a
        // hopeless class. Reads the existing decomposition-outcomes ledger by normalized change-class (no new
        // table). Default OFF / thin corpus => no abstention => byte-identical. Composes with U5: the abstention
        // enqueues a clarification.
        $ccPriorEnabled = (bool) config('atlas.loop.change_class_prior_enabled', false);
        $ccThinAskEnabled = (bool) config('atlas.loop.change_class_thin_prior_ask_enabled', false);
        if ($ccPriorEnabled || $ccThinAskEnabled) {
            try {
                $ccSvc = new \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopChangeClassPriorService;
                $ccPrior = $ccSvc->priorFor($kind);
                if ($ccPriorEnabled && ($ccPrior['hopeless'] ?? false) === true) {
                    $this->emitPlanAbstention($payload, 'change_class_historically_thrashes');

                    return null;
                }
                // ACDE DC6 — thin-prior MAX-UNCERTAINTY: ask the operator before burning more budget on a class
                // the loop cannot yet judge (pre-hopeless) but whose early evidence already leans below the
                // floor. Independently armed from DC4's hopeless gate. Default OFF => byte-identical. Composes
                // with U5 — the abstention enqueues a clarification.
                if ($ccThinAskEnabled && $ccSvc->thinPriorMaxUncertainty($ccPrior)) {
                    $this->emitPlanAbstention($payload, 'change_class_thin_prior_ask');

                    return null;
                }
            } catch (\Throwable) {
                // fail-open: a prior hiccup never blocks planning (proceed to spec/plan as before).
            }
        }

        // 1) INTENT -> SPEC (iterate-to-ready). generateSpec is hermes_cli spec-only in production;
        //    injected (via the protected provider seam) in tests. fn(string $goal, list<string> $priorGaps): array.
        $compiler = $this->specCompiler ?? new AtlasLoopIntentSpecCompiler;
        $spec = $compiler->compile($goal, $this->specGenerator($payload, $allowed), (int) config('atlas.loop.planning_spec_max_attempts', 3));
        if (($spec['ready'] ?? false) !== true || ! is_array($spec['spec'] ?? null)) {
            $this->emitPlanAbstention($payload, 'spec_not_ready'); // ACDE P4 — make the abstention VISIBLE
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

        // ACDE P2 — HINT-GROUNDING. A weak engine writes a confident-but-fictional decomposition_hint naming
        // files that do not exist; the planner then chases them into an ill-formed DAG. When armed, strip hint
        // file-references not in the allowed/new-file scope BEFORE the planner consumes the spec. Default OFF =>
        // spec unchanged => byte-identical.
        if ((bool) config('atlas.loop.hint_grounding_enabled', false)) {
            $spec['spec'] = $this->groundDecompositionHint($spec['spec'], $planAllowed);
        }

        $out = $planner->plan($goal, ['spec' => $spec['spec']], $planAllowed, $this->planGenerator($payload, $allowed, $newFiles, $spec['spec']), (int) config('atlas.loop.planning_plan_max_attempts', 3));
        if (($out['ready'] ?? false) !== true || ! is_array($out['plan'] ?? null)) {
            $this->emitPlanAbstention($payload, 'plan_not_ready'); // ACDE P4 — make the abstention VISIBLE
            return null; // planner refused -> buildPlan fallback (cheap; never spend on an ill-formed plan)
        }

        // The executor walks nodes by SEQ ascending (AtlasObraExecutor::execute), NOT by depends_on —
        // so the create-class node MUST carry seq=0 (the planGenerator assigns it).
        return ['plan' => $out['plan'], 'allowed' => $planAllowed, 'new_files' => $newFiles];
    }

    /**
     * ACDE P4 — the plan-abstention RECEIPT. Today when the planner exhausts its attempts maybePlan silently
     * returns null and the adapter falls back to the dumb one-shot buildPlan — the operator never sees that the
     * structured planner GAVE UP. This builds a provider-safe receipt (objective kind + family + reason, never
     * code) so the silent fallback becomes an observable signal. Pure.
     *
     * @param  array<string,mixed>  $payload
     * @return array{abstained:bool, objective_kind:string, family:string, reason:string}
     */
    public function planAbstentionReceipt(array $payload, string $reason): array
    {
        $kind = trim((string) ($payload['objective_kind'] ?? ''));
        $family = $kind === '' ? 'unknown' : (string) (explode('_', $kind, 2)[0] ?? $kind);

        return [
            'abstained' => true,
            'objective_kind' => $kind,
            'family' => $family !== '' ? $family : 'unknown',
            'reason' => trim($reason) !== '' ? trim($reason) : 'unspecified',
        ];
    }

    /**
     * ACDE P4 — emit the plan-abstention receipt to the log when armed, so a planner give-up is VISIBLE rather
     * than a silent dumb-buildPlan fallback. Default OFF => no log, no behavior change => byte-identical. The
     * fallback contract itself is unchanged (the operator-routing of an abstention is a separate governance call).
     *
     * @param  array<string,mixed>  $payload
     */
    protected function emitPlanAbstention(array $payload, string $reason): void
    {
        try {
            $receipt = $this->planAbstentionReceipt($payload, $reason);
            if ((bool) config('atlas.loop.plan_abstention_visible_enabled', false)) {
                \Illuminate\Support\Facades\Log::info('atlas.loop.plan_abstention', $receipt);
            }
            // ACDE U5 — persist the abstention as an operator CLARIFICATION REQUEST (the loop ASKS instead
            // of silently guessing). Independently gated from the log so the operator can have the queue
            // without the log noise. Default OFF => no row => byte-identical. The enqueue is fail-open: a DB
            // hiccup is swallowed below and never breaks the planning fallback.
            if ((bool) config('atlas.loop.clarification_queue_enabled', false)) {
                \App\Models\AtlasLoopClarificationRequest::enqueue($receipt, trim((string) ($payload['objective'] ?? '')));
            }
        } catch (\Throwable) {
            // visibility/queueing must never break the planning path — a logging/DB hiccup is swallowed.
        }
    }

    /**
     * ACDE U6 — fold a cached operator clarification into the goal. If the U5 queue holds an ANSWERED request
     * for this exact goal (fingerprint match), append the operator's answer so the loop plans WITH the
     * clarification instead of re-asking. Returns the goal unchanged when the flag is OFF, when nothing was
     * answered, or on any DB hiccup (fail-open). Default OFF => identity => byte-identical.
     */
    protected function withCachedClarification(string $goal): string
    {
        if (! (bool) config('atlas.loop.clarification_cache_enabled', false)) {
            return $goal;
        }
        try {
            $answer = \App\Models\AtlasLoopClarificationRequest::cachedAnswerFor($goal);
            if (is_string($answer) && trim($answer) !== '') {
                return trim($goal).' '.trim($answer);
            }
        } catch (\Throwable) {
            // fail-open: no cache fold, the goal proceeds (and may abstain) exactly as before.
        }

        return $goal;
    }

    /**
     * ACDE P2 — strip fictional file references from a spec's decomposition_hint. Every `*.php` token in the
     * hint that is NOT in the allowed/new-file scope (a file the weak engine invented) is removed, so the
     * planner cannot chase a non-existent file. Real references are preserved verbatim. Pure string transform.
     *
     * @param  array<string,mixed>  $spec
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    public function groundDecompositionHint(array $spec, array $allowedFiles): array
    {
        $hint = $spec['decomposition_hint'] ?? null;
        if (! is_string($hint) || trim($hint) === '') {
            return $spec;
        }
        $allow = [];
        foreach ($allowedFiles as $f) {
            $n = ltrim(mb_strtolower(trim((string) $f)), '/');
            if ($n !== '') {
                $allow[$n] = true;
            }
        }
        $stripped = false;
        $grounded = (string) preg_replace_callback('/[A-Za-z0-9_\/.\-]+\.php\b/', static function (array $m) use ($allow, &$stripped): string {
            $tok = ltrim(mb_strtolower($m[0]), '/');
            if (isset($allow[$tok])) {
                return $m[0];
            }
            $stripped = true;

            return ''; // a fictional file reference => removed
        }, $hint);
        $grounded = trim((string) preg_replace('/\s{2,}/', ' ', $grounded));

        $spec['decomposition_hint'] = $grounded;
        if ($stripped) {
            $spec['decomposition_hint_grounded'] = true;
        }

        return $spec;
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
     * ACDE Leap 1 — PRODUCTION provider seam, spec-only. Asks the configured provider (hermes_cli ->
     * MiniMax) for deterministic JSON and parses it into {summary, acceptance_criteria, suggested_files,
     * decomposition_hint}. The actual provider call lives in {@see obraPlanningProviderRaw} (overridable in
     * tests to feed a recorded transcript with NO live call). FAIL-OPEN: any provider error or parse
     * failure returns [] => the compiler refuses-with-gaps => maybePlan returns null => buildPlan fallback
     * => SAFE even with the flag ON. Only reached when atlas.loop.planning_enabled is ON.
     *
     * @param  list<string>  $priorGaps
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $allowed
     * @return array<string,mixed>
     */
    protected function generateSpecViaProvider(string $goal, array $priorGaps, array $payload, array $allowed): array
    {
        try {
            $json = $this->decodeJsonObject($this->obraPlanningProviderRaw('spec', $this->specPrompt($goal, $priorGaps)));
            if ($json === null) {
                return [];
            }
            $criteria = [];
            foreach ((array) ($json['acceptance_criteria'] ?? []) as $i => $c) {
                if (! is_array($c)) {
                    continue;
                }
                $criteria[] = [
                    'id' => trim((string) ($c['id'] ?? ('AC'.($i + 1)))),
                    'description' => trim((string) ($c['description'] ?? '')),
                    'required' => (bool) ($c['required'] ?? true),
                ];
            }

            return [
                'summary' => trim((string) ($json['summary'] ?? '')),
                'acceptance_criteria' => $criteria,
                'suggested_files' => array_values(array_filter(
                    (array) ($json['suggested_files'] ?? []),
                    static fn ($f): bool => is_string($f) && trim($f) !== '',
                )),
                'decomposition_hint' => trim((string) ($json['decomposition_hint'] ?? '')),
            ];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * ACDE Leap 1 — PRODUCTION provider seam, DAG-only. Asks the provider for a node list and assembles
     * it into the shape {@see AtlasLoopObraDecompositionPlanner}->plan() consumes. CRITICAL: the executor
     * walks nodes by "seq" ASCENDING (NOT depends_on), so Atlas emits a create-class node for each net-new
     * file at the LOWEST seq (its request literally references the new path so the readiness gate's
     * request-references-target check passes), then the provider's edit/redirect nodes at higher seq with
     * depends_on the create ids. FAIL-OPEN: any error/parse failure returns [] => buildPlan fallback. Only
     * reached when atlas.loop.planning_enabled is ON.
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
        try {
            $dagContext = [
                'decomposition_hint' => trim((string) ($spec['decomposition_hint'] ?? '')),
                'suggested_files' => $newFiles,
            ];
            $json = $this->decodeJsonObject($this->obraPlanningProviderRaw('dag', $this->dagPrompt($goal, $dagContext, $priorGaps)));
            if ($json === null) {
                return [];
            }
            $newFileSet = array_flip(array_map(static fn (string $f): string => ltrim($f, '/'), $newFiles));

            $nodes = [];
            $seq = 0;
            $createIds = [];
            // CREATE-CLASS nodes FIRST (lowest seq) — one per net-new file, so the executor's seq walk
            // materializes the new class before any node that edits/redirects onto it.
            foreach ($newFiles as $file) {
                $norm = ltrim((string) $file, '/');
                if ($norm === '') {
                    continue;
                }
                $id = 'create-'.substr(hash('sha256', $norm), 0, 12);
                $createIds[] = $id;
                $nodes[] = [
                    'id' => $id,
                    'seq' => $seq++,
                    'title' => 'create '.basename($norm),
                    'request' => 'Create the new file '.$norm.' with the extracted, simpler helper methods for: '.$goal
                        .' PRESERVE behaviour exactly; each new method must be strictly simpler than the original worst method.',
                    'target_area' => $norm,
                    'depends_on' => [],
                    'complexity_proof' => true,
                ];
            }
            // EDIT/REDIRECT nodes at higher seq — each provider-named node that touches an EXISTING file,
            // depending on every create node so the new class exists first.
            foreach ((array) ($json['nodes'] ?? []) as $i => $n) {
                if (! is_array($n)) {
                    continue;
                }
                $target = ltrim(trim((string) ($n['target_area'] ?? ($n['file'] ?? ''))), '/');
                if ($target === '' || isset($newFileSet[$target])) {
                    continue; // skip empty + already-emitted create-class targets
                }
                $request = trim((string) ($n['request'] ?? ''));
                if ($request === '') {
                    $request = 'Edit '.$target.' to redirect onto the extracted class and simplify its worst method in place for: '.$goal;
                } elseif (! str_contains($request, basename($target)) && ! str_contains($request, $target)) {
                    $request .= ' (edit '.$target.')';
                }
                $nodes[] = [
                    'id' => trim((string) ($n['id'] ?? ('edit-'.substr(hash('sha256', $target.'|'.$i), 0, 12)))),
                    'seq' => $seq++,
                    'title' => 'refactor '.basename($target),
                    'request' => $request,
                    'target_area' => $target,
                    'depends_on' => $createIds,
                    'complexity_proof' => true,
                ];
            }

            return [
                'plan_id' => trim((string) ($json['plan_id'] ?? ('obra-plan-'.substr(hash('sha256', $goal), 0, 16)))),
                'nodes' => $nodes,
            ];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * ACDE Leap 1 — the ACTUAL provider invocation (the ONLY place a provider runs in the planner path).
     * Mirrors {@see AtlasLiveCodeDeliveryService}: resolve the configured
     * provider via {@see AiProviderManager}->get(), run an EPHEMERAL read-only job, return the raw output
     * text (the generate*ViaProvider methods parse it). Overridable in a test double to return a RECORDED
     * JSON transcript so the parse path runs for real with NO live call / NO spend. Read-only: the job
     * never edits anything; it only returns planning TEXT.
     */
    protected function obraPlanningProviderRaw(string $mode, string $prompt): string
    {
        $manager = $this->providers ?? app(AiProviderManager::class);
        $providerKey = $this->planningProviderKey();
        $provider = $manager->get($providerKey);
        if (! $provider instanceof AiProvider) {
            return '';
        }

        $job = new AiJob;
        $job->kind = 'obra_planning';
        $job->provider = $providerKey;
        $job->prompt = $prompt;
        $job->input_text = $prompt;
        $job->metadata = ['permission_mode' => 'read', 'obra_planning_mode' => $mode];
        $job->timeout_seconds = max(60, min(3600, (int) config('atlas.ai.timeout_seconds', 600)));

        $result = $provider->run($job, $prompt);
        if (! (bool) ($result->ok ?? false)) {
            return '';
        }

        return (string) ($result->output ?? '');
    }

    /** The provider the planner runs through — the loop default (hermes_cli -> MiniMax). */
    private function planningProviderKey(): string
    {
        $configured = config('atlas.ai.default_provider', 'hermes_cli');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : 'hermes_cli';
    }

    /** @param  list<string>  $priorGaps */
    private function specPrompt(string $goal, array $priorGaps): string
    {
        $fix = $priorGaps === [] ? '' : "\n\nThe previous spec had these gaps; FIX them: ".implode(', ', array_slice($priorGaps, 0, 8));

        return 'Produce ONLY a single deterministic JSON object (no prose, no markdown fence) for this engineering goal.'
            ."\nGoal: ".$goal
            ."\nShape: {\"summary\": string, \"acceptance_criteria\": [{\"id\": string, \"description\": string (>=15 chars), \"required\": bool}], "
            .'"suggested_files": [string], "decomposition_hint": string}'
            ."\nAt least one acceptance criterion must be required. suggested_files lists any NEW files the change introduces."
            .$fix;
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  list<string>  $priorGaps
     */
    private function dagPrompt(string $goal, array $context, array $priorGaps): string
    {
        $hint = trim((string) ($context['decomposition_hint'] ?? ''));
        $newFiles = implode(', ', array_filter((array) ($context['suggested_files'] ?? []), 'is_string'));
        $fix = $priorGaps === [] ? '' : "\n\nThe previous DAG had these gaps; FIX them: ".implode(', ', array_slice($priorGaps, 0, 8));

        return 'Produce ONLY a single deterministic JSON object (no prose, no markdown fence) decomposing this goal into a node DAG.'
            ."\nGoal: ".$goal
            .($hint !== '' ? "\nDecomposition hint: ".$hint : '')
            .($newFiles !== '' ? "\nNew files to create: ".$newFiles : '')
            ."\nShape: {\"plan_id\": string, \"nodes\": [{\"id\": string, \"target_area\": string (a file path), \"request\": string (concrete, references its file)}]}"
            ."\nDo NOT include create-class nodes for the new files — Atlas emits those. List only the EXISTING files to edit/redirect."
            .$fix;
    }

    /**
     * Decode a provider response into a JSON object. Tolerant of a leading/trailing prose wrap or a single
     * ```json fence (extract the outermost {...}); returns null on anything non-object.
     *
     * @return array<string,mixed>|null
     */
    private function decodeJsonObject(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $start = strpos($raw, '{');
            $end = strrpos($raw, '}');
            if ($start === false || $end === false || $end <= $start) {
                return null;
            }
            $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        }

        return is_array($decoded) ? $decoded : null;
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

    /**
     * ACDE Leap 5 — append the EXECUTED obra's terminal outcome to the decomposition corpus. No-op when
     * atlas.loop.decomposition_corpus_enabled is OFF (byte-identical) and best-effort otherwise (never
     * breaks the obra path). Called ONLY after the plan was readiness-gated AND the executor ran — a
     * pre-execution REPLAN (plan_not_ready) is NOT an executed shape and is deliberately not recorded.
     *
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $payload
     */
    private function recordShapeOutcome(array $plan, array $payload, bool $certified, string $terminalReason): void
    {
        ($this->outcomeRecorder ?? new AtlasLoopDecompositionOutcomeRecorder)->record(
            $plan,
            trim((string) ($payload['objective_kind'] ?? '')),
            $certified,
            $terminalReason,
            count(is_array($plan['nodes'] ?? null) ? (array) $plan['nodes'] : []),
        );
    }

    private function stringOrNull(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }
}
