<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopParkEscalation;
use App\Services\Ai\AutonomousEvolution\Framework\AtlasLoopFrameworkMaterializer;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopRunPersister;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopComprehensionGroundingGate;
use App\Services\Ai\Cognitive\PredictiveFailure\AtlasLoopPredictiveOutcomeBridge;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\Support\AiStringListNormalizer;
use RuntimeException;
use Throwable;

/**
 * The shared grind core — the single path from a CLAIMED durable task to a persisted,
 * propose-only result. Both the serial supervisor (in-process) and the parallel
 * worker subcommand (one process per task) call this, so there is exactly ONE grind
 * path, never two divergent ones.
 *
 * For one claimed task it: admits against the disk gate (backpressure, never crash),
 * materializes the durable snapshot into a fresh per-worker-namespaced workspace, runs
 * the PROVEN {@see AtlasEvolutionLoopRunner} (deep search + frozen judge + propose-only),
 * and streams the result to the durable ledger via {@see AtlasLoopRunPersister}. The
 * task MUST already be claimed by $workerId (the caller owns the lease).
 */
final class AtlasLoopTaskGrinder
{
    public function __construct(
        private readonly AtlasLoopWorkspaceMaterializer $materializer,
        private readonly AtlasLoopFrameworkMaterializer $frameworkMaterializer,
        private readonly AtlasEvolutionLoopRunner $runner,
        private readonly AtlasLoopRunPersister $persister,
        private readonly AtlasLoopStore $store,
        private readonly AtlasLoopResourceGate $gate,
        private readonly AtlasLoopSemanticImplementationCertifier $semanticCertifier,
        private readonly AtlasLoopIntentVerifierFactory $intentVerifierFactory,
        private readonly AtlasLoopExplorerStrategyBanditService $strategyBandit,
        private readonly AtlasLoopPredictiveOutcomeBridge $predictiveBridge,
        // ACDE Tier-1 #5: the autonomous escalation conductor. Optional + nullable + LAST so the
        // container autowires it to null (Laravel does not inject `?Type = null`) and the
        // newInstanceWithoutConstructor test build is unaffected; grind() falls back to a fresh
        // instance. Touched ONLY when atlas.loop.conductor_escalation_enabled is ON (default OFF).
        private readonly ?AtlasLoopAutonomousConductor $conductor = null,
        // ACDE Leap 2: the obra execution adapter the conductor's `decompose` tier invokes so escalation
        // changes STRUCTURE (a real maybePlan -> AtlasObraExecutor), not just scenario width. Nullable +
        // LAST (Laravel does not inject `?Type = null`); escalateViaConductor falls back to app(). Touched
        // ONLY inside the decompose tier AND only when atlas.loop.planning_enabled is ON (default OFF) —
        // OFF => the decompose tier degrades to its prior scenario-width closure (byte-identical).
        private readonly ?AtlasLoopObraExecutionAdapter $obraAdapter = null,
        // COMPREHENSION GROUNDING GATE (stage-1 UNDERSTAND): a keep-conjunct on the implementation cert.
        // A certified proposal whose allowed/affected files cite a symbol that resolves NOWHERE in the repo
        // is a tell that the stated objective is hallucinated — drop it. FAIL-OPEN (empty citations or an
        // unreadable root => grounded=true), so it can only refute a positively-fabricated citation, never
        // block a real one. Nullable + LAST (Laravel does not inject `?Type = null`); the use-site falls
        // back to a fresh instance. Touched ONLY when comprehension_grounding_gate_enabled is ON.
        private readonly ?AtlasLoopComprehensionGroundingGate $comprehensionGroundingGate = null,
        // PARK-LEDGER ESCALATION: when a target is parked as hopeless after >=1 REAL attempt, escalate its
        // priority and re-enqueue an escalated shadow follow-up so a high-EV item cannot be starved forever.
        // Nullable + LAST (Laravel does not inject `?Type = null`); the park seam falls back to a fresh
        // instance. Touched ONLY when park_escalation_enabled is ON.
        private readonly ?AtlasLoopParkEscalation $parkEscalation = null,
    ) {}

    /**
     * @return array{status:string, has_winner:bool, proposals:int, scenarios_explored:int, elapsed_seconds:int, cost_estimate_usd?:?float, cost_cents?:int, tokens_used?:?int, reason?:string}
     */
    public function grind(AtlasLoopTask $task, string $workerId, ?int $scenarios = null, string $workspaceRoot = '', ?int $timeBudgetSeconds = null, ?callable $onProgress = null): array
    {
        $started = microtime(true);
        $this->store->markRunning($task->id, $workerId);
        $this->emitProgress($onProgress, 'grind_running', $task);

        $tmpRoot = $workspaceRoot !== '' ? $workspaceRoot : sys_get_temp_dir();
        $admit = $this->gate->admitScenario(
            $tmpRoot,
            (int) config('atlas.loop.campaign.min_free_mb', 512),
            (int) config('atlas.loop.campaign.max_live_workspaces', 0),
        );
        if (! $admit['admit']) {
            // Backpressure: hand the claim back so the supervisor retries once disk frees.
            $this->store->releaseClaim($task->id, $workerId);

            return ['status' => 'backpressure', 'reason' => (string) $admit['reason'], 'has_winner' => false, 'proposals' => 0, 'scenarios_explored' => 0, 'elapsed_seconds' => 0];
        }

        $cleanup = static function (): void {};
        try {
            $payload = $this->taskPayload($task);

            // LEVER 5 — provider routing (flag-gated, default OFF => byte-identical). Send cheap
            // task-classes (characterization tests, edge-fixes) to the cheap tier (MiniMax) and keep
            // load-bearing classes (refactors/features) on the strong default (codex/gpt-5.5). Only
            // overrides when the route picks the cheap tier AND the payload has no explicit pin; the
            // driver auto-resolves the routed provider's model. Fail-safe: an unconfigured cheap
            // provider falls back to the default (the router never returns an unconfigured one).
            if (empty($payload['provider'])) {
                $routed = (new AtlasLoopProviderRouter)->route(
                    (string) ($payload['objective_kind'] ?? ''),
                    (string) config('atlas.loop.default_provider', (string) config('atlas.ai.default_provider', '')),
                    null,
                    (array) config('atlas.loop.provider_routing', []),
                    fn (string $p): bool => app(AtlasForgeProviderInvocationDriverRouter::class)->isConfigured($p),
                );
                if ($routed['tier'] === 'cheap') {
                    $payload['provider'] = $routed['provider'];
                }
            }

            // PHASE-2 multi-file refactor routing (flag-gated, default OFF). A `refactor_*`
            // objective touching >=2 files is HARD-ROUTED to the governed Obra bridge
            // (operator-reviewed, never-merge) INSTEAD of the single-file explorer/materializer.
            // It NEVER single-file-materializes a multi-file diff. With the flag OFF or a single
            // file, the routing is inert and the grind falls through to the normal path below.
            // Resolved LAZILY via app() so the grinder constructor signature stays unchanged.
            $obraRoute = $this->maybeRouteMultiFileRefactorToObra($task, $payload, $workerId, $started);
            if ($obraRoute !== null) {
                $cleanup();

                return $obraRoute;
            }

            // §5.6 ORPHAN-WIRING execution route (flag-gated, default OFF => inert/byte-identical). A
            // source='orphan_wiring' directive (the brain's tested-orphan supply) is routed to the engine-
            // authored executor inside a CONCURRENCY-SAFE standalone workspace; a certified wiring PARKS its net
            // diff (propose-only, never merge). Any other task => null (fall-through to the normal path).
            $orphanWiringRoute = $this->maybeRouteOrphanWiringToExecutor($task, $payload, $workerId, $started);
            if ($orphanWiringRoute !== null) {
                $cleanup();

                return $orphanWiringRoute;
            }

            $strategyBanditDecision = $this->strategyBanditDecision($task, $payload, $scenarios);
            // ACDE lever #7 — if THIS exact target has gone N real attempts with ZERO certs it is hopeless
            // (already-clean / unfixable-as-framed); skip BEFORE best-of-N burns the budget. A skip is an
            // honest TERMINAL refusal (a DQS defect) via completeTask(success=false) — NOT releaseClaim, which
            // would re-claim -> re-grind -> re-fail forever (the zombie-stall). Runs before materialization so
            // there is no workspace to clean. Flag OFF => verdict is always 'open' => byte-identical.
            if ((string) data_get($strategyBanditDecision, 'target_verdict.verdict') === 'hopeless') {
                $tv = (array) ($strategyBanditDecision['target_verdict'] ?? []);
                // PARK-LEDGER ESCALATION — a hopeless target that has burned >=1 REAL attempt is parked, not
                // forgotten: escalate its priority and re-enqueue an escalated SHADOW follow-up so a high-EV
                // item cannot be starved forever behind a wall of cheaper, freshly-discovered work. The
                // priority column is an INTEGER (default ~100), so normalize /1000 into [0,1], let the pure
                // escalator add 0.05/reattempt (clamped [0,1]), then *1000 back. The follow-up's objective
                // carries a ' [park-escalated]' suffix so its dedupe_key is DISTINCT (it actually lands), and
                // it records park_escalated provenance. Only enqueue when the escalated INTEGER strictly
                // exceeds the base (so a saturated priority is a no-op). Flag OFF => skipped => byte-identical.
                if ((bool) config('atlas.loop.park_escalation_enabled', true)
                    && (int) ($tv['real_attempts'] ?? 0) >= 1) {
                    try {
                        $escalator = $this->parkEscalation ?? new AtlasLoopParkEscalation;
                        $baseInt = max(0, (int) $task->priority);
                        $escalatedUnit = $escalator->escalatePriority(
                            $baseInt / 1000.0,
                            (int) ($tv['real_attempts'] ?? 0),
                        );
                        $escalatedInt = (int) round($escalatedUnit * 1000.0);
                        if ($escalatedInt > $baseInt) {
                            $escalatedPayload = $payload;
                            $escalatedPayload['park_escalated'] = true;
                            $escalatedPayload['park_escalated_from_priority'] = $baseInt;
                            $this->store->enqueueTask(
                                (string) $task->campaign_id,
                                (string) $task->objective.' [park-escalated]',
                                $escalatedPayload,
                                'park_escalation',
                                $task->target_path !== null ? (string) $task->target_path : null,
                                $escalatedInt,
                                (bool) $task->self_contained,
                                $task->acceptance_hash !== null ? (string) $task->acceptance_hash : null,
                            );
                        }
                    } catch (Throwable) {
                        // Escalation is a best-effort re-prioritization — never crash the honest skip.
                    }
                }
                $this->store->completeTask($task->id, $workerId, [
                    'status' => 'skipped_hopeless_target',
                    'reason' => 'per_target_skip:'.(int) ($tv['real_attempts'] ?? 0).'_real_attempts_0_certified',
                    'target_verdict' => $tv,
                ], false);

                return ['status' => 'skipped', 'reason' => 'hopeless_target', 'target_verdict' => $tv];
            }
            if ((bool) ($strategyBanditDecision['applied'] ?? false)) {
                $payload['scenario_strategies'] = $strategyBanditDecision['selected_strategy_texts'] ?? [];
                $payload['scenario_strategy_keys'] = $strategyBanditDecision['selected_strategy_keys'] ?? [];
            }
            // ACDE direction-(a): DEEPER best-of-N on the single weak engine. Flag OFF (default) => payload
            // untouched => byte-identical. ON => the explorer's default decorrelation pool widens from 5 to
            // 9 structurally-distinct mandates, so the extra scenarios (up to max_scenarios_per_task=12) buy
            // genuinely different candidates instead of re-rolling the same 5 prompts. Moot when the bandit
            // pinned explicit strategies above (those take precedence in the explorer).
            if ((bool) config('atlas.loop.deep_strategy_portfolio', false)) {
                $payload['deep_strategy_portfolio'] = true;
            }
            // ACDE lever #1: when the cross-provider best-of-N portfolio is armed, tell the materializer to
            // derive the FrozenJudge scope globs from allowed_files (the materializer is config-free for the
            // pure-unit suite, so the flag is injected here where the container is always available). Flag OFF
            // => payload untouched => byte-identical.
            if ((bool) config('atlas.loop.cross_provider_best_of_n', false)) {
                $payload['cross_provider_best_of_n'] = true;
            }
            $frameworkTask = $this->usesFrameworkMaterializer($payload);
            $intentVerifierPacket = null;
            if ($frameworkTask && $this->shouldCompileIntentVerifier($payload)) {
                $intentVerifierPacket = $this->intentVerifierFactory->compileFrameworkPacket(base_path(), (string) $task->objective, $payload);
                $payload = $this->intentVerifierFactory->taskPayloadOrFail($intentVerifierPacket);
            }
            [$explorerTask, $cleanup] = $frameworkTask
                ? $this->frameworkMaterializer->materializeBase(base_path(), (string) $task->objective, $payload)
                : $this->materializer->materialize((string) $task->objective, $payload);
            $this->emitProgress($onProgress, 'grind_materialized', $task);
            if ($workspaceRoot !== '') {
                $explorerTask['workspace_root'] = $workspaceRoot; // namespace + reapable scenario copies
            }
            if ($timeBudgetSeconds !== null && $timeBudgetSeconds > 0) {
                $explorerTask['search_time_budget_seconds'] = $timeBudgetSeconds; // never overrun the campaign deadline
            }

            $options = [];
            if ($scenarios !== null && $scenarios > 0) {
                $options['scenarios_per_task'] = $scenarios;
            }
            if ($onProgress !== null) {
                $options['progress_callback'] = $onProgress;
            }

            $result = $this->runner->run([$explorerTask], $options);
            $this->emitProgress($onProgress, 'grind_runner_returned', $task);
            if (is_array($strategyBanditDecision)) {
                $result['explorer_strategy_bandit'] = $this->summariseStrategyBanditDecision($strategyBanditDecision);
            }
            if ($intentVerifierPacket !== null) {
                $result['intent_verifier_factory'] = $this->summariseIntentVerifierPacket($intentVerifierPacket);
            }
            // O-2 slice (d): certificação adversarial em TODOS os caminhos. Antes só o
            // caminho framework passava pelo gate (semantic certifier + painel adversarial
            // + refuters); o caminho default de descoberta — o que alimenta a campanha 24h
            // e o que o merge-livre vai consumir — só tinha o frozen judge auto-escrito
            // (Goodhart aberto). Ligar a certificação universal MUDA a severidade do juiz
            // do loop vivo: é decisão deliberada (flag, default OFF — destravada em O-3
            // junto da política de merge-livre), não um flip silencioso. Quando ligada, o
            // caminho de descoberta passa pelo MESMO gate; falha de certificação de uma
            // proposta a derruba (fail-closed), nunca derruba a task inteira.
            $universal = (bool) config('atlas.loop.universal_certification', false);
            // CHARACTERIZATION-TEST lane: a provider-written test certifies via the mutant-killed
            // verifier (not the refactor cert). Inert for every existing task — fires ONLY for the
            // dedicated objective_kind (only the coverage-gap feeder produces it) AND behind a config
            // flag, so the running soak's normal grind/cert path is byte-unchanged.
            if ($this->isCharacterizationTestTask($payload) && $this->canGateProposals($explorerTask)) {
                $result = $this->gateCharacterizationTestProposals($result, $explorerTask, $payload);
            } elseif (($frameworkTask || $universal) && $this->canGateProposals($explorerTask)) {
                $result = $this->gateImplementationProposals($result, $explorerTask, $payload, $frameworkTask);
            }
            // ACDE Tier-1 #5: a no-winner best-of-N round, instead of dead-ending, escalates STRUCTURALLY
            // (repair_from_refutation -> decompose -> escalate_provider) via the autonomous conductor —
            // the flow-level substitute for model intelligence ("change strategy when stuck"). Fail-open:
            // any fault leaves $result untouched. Default OFF => skipped entirely (runner ran exactly once).
            if ((bool) config('atlas.loop.conductor_escalation_enabled', false)
                && $this->canGateProposals($explorerTask)
                && ! $this->resultHasCertifiedWinner($result)) {
                $result = $this->escalateViaConductor($result, $explorerTask, $payload, $frameworkTask, $universal, $options);
            }
            $summary = $this->persister->persist($task, $workerId, $result);
            $this->emitProgress($onProgress, 'grind_persisted', $task);
            $cleanup();

            $grindResult = [
                'status' => $summary['has_winner'] ? 'winner' : 'no_winner',
                'has_winner' => $summary['has_winner'],
                'proposals' => $summary['proposals'],
                'scenarios_explored' => $summary['scenarios_explored'],
                'elapsed_seconds' => (int) ceil(microtime(true) - $started),
                'cost_estimate_usd' => $summary['cost_estimate_usd'] ?? null,
                'cost_cents' => (int) ($summary['cost_cents'] ?? 0),
                'tokens_used' => $summary['tokens_used'] ?? null,
            ];

            // L6-11: record a real prediction + observed outcome for this grind so the
            // predictive calibration surface computes brier on live loop data. Fail-open
            // + flag-gated default-OFF inside the bridge — never crashes a grind.
            $this->recordPredictiveOutcome($task, $grindResult);
            // ACDE R2: append the IN-LANE extract-sequence outcome to the SAME decomposition corpus the obra
            // path feeds, so the prior-read (R2-read) can ground the next sequence's ordering. Fail-open +
            // flag-gated (the recorder no-ops when decomposition_corpus_enabled is OFF) => byte-identical.
            $this->recordExtractSequenceOutcome($payload, $grindResult);

            return $grindResult;
        } catch (Throwable $e) {
            $cleanup();
            // Infra failure (not a no-winner) — mark failed, lease-checked; counters untouched.
            $this->store->completeTask($task->id, $workerId, ['error' => mb_substr($e->getMessage(), 0, 400)], false);

            $grindResult = ['status' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 200), 'has_winner' => false, 'proposals' => 0, 'scenarios_explored' => 0, 'elapsed_seconds' => (int) ceil(microtime(true) - $started)];
            $this->recordPredictiveOutcome($task, $grindResult);

            return $grindResult;
        }
    }

    private function emitProgress(?callable $onProgress, string $stage, AtlasLoopTask $task): void
    {
        if ($onProgress === null) {
            return;
        }

        try {
            $onProgress([
                'stage' => $stage,
                'task_id' => (string) $task->id,
                'target_path' => $task->target_path !== null ? (string) $task->target_path : null,
            ]);
        } catch (Throwable) {
            // Lease/heartbeat progress is advisory and must never change grind correctness.
        }
    }

    /**
     * PHASE-2: hard-route a multi-file `refactor_*` objective to the governed Obra bridge
     * (operator-reviewed, never-merge) instead of the single-file explorer/materializer.
     *
     * Returns a terminal grind result when the route fires, or null when it does NOT (flag OFF,
     * non-refactor objective, or fewer than 2 allowed files) so the caller falls through to the
     * normal single-file path. The bridge dispatches NO provider and never merges; a blocked
     * bridge (no certified L4-10 real evidence) drops to no_winner — it NEVER single-file
     * materializes a multi-file diff.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    /**
     * §5.6 ORPHAN-WIRING execution route — the thin grinder wrapper around {@see AtlasLoopOrphanWiringRouteHandler}.
     * Routes a source='orphan_wiring' directive to the engine-authored executor: resolve the §9 authoring engine
     * ({@see AtlasLoopOrphanWiringAuthoringEngine}, app()-bound so a fixture can supply a double), run the handler
     * in a concurrency-safe standalone workspace, and PARK the certified net diff (propose-only, never merge).
     * Returns a terminal grind result when it fires, or null (flag OFF / non-orphan-wiring / no base) to fall
     * through. The authoring engine returning null (live provider-authoring not yet built) => honest no_winner,
     * NEVER a fabricated cert. Flag OFF => this method is inert and the grind is byte-identical to today.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    private function maybeRouteOrphanWiringToExecutor(AtlasLoopTask $task, array $payload, string $workerId, float $started): ?array
    {
        if (! (bool) config('atlas.loop.orphan_wiring_execution_enabled', false)) {
            return null;
        }
        if (trim((string) ($payload['objective_kind'] ?? '')) !== 'orphan_wiring') {
            return null;
        }
        $orphanRel = trim((string) ($payload['orphan_path'] ?? ''));
        if ($orphanRel === '') {
            return null;
        }
        $campaign = \App\Models\AtlasLoopCampaign::query()->find($task->campaign_id);
        $baseWorkspace = trim((string) ($campaign->base_workspace ?? ''));
        if (! $campaign instanceof \App\Models\AtlasLoopCampaign || $baseWorkspace === '' || ! is_dir($baseWorkspace)) {
            $this->store->completeTask($task->id, $workerId, [
                'status' => 'no_winner',
                'reason' => 'orphan_wiring_base_workspace_unavailable',
            ], false);

            return $this->orphanWiringTerminal($started, 'no_winner', 'orphan_wiring_base_workspace_unavailable', 0);
        }

        // The §9 authoring seam: a real provider call in prod, a fixture double in tests. null => honest no_winner.
        $authoring = app(AtlasLoopOrphanWiringAuthoringEngine::class)->author($payload, $baseWorkspace);
        if (! is_array($authoring) || ! is_callable($authoring['author_test'] ?? null) || ! is_callable($authoring['author_wiring'] ?? null)) {
            $this->store->releaseClaim($task->id, $workerId);

            return $this->orphanWiringTerminal($started, 'no_winner', 'orphan_wiring_authoring_unavailable', 0);
        }

        $outcome = (new AtlasLoopOrphanWiringRouteHandler)->handle($orphanRel, $baseWorkspace, $authoring['author_test'], $authoring['author_wiring']);
        if (($outcome['certified'] ?? false) !== true) {
            $this->store->releaseClaim($task->id, $workerId);

            return $this->orphanWiringTerminal($started, 'no_winner', 'orphan_wiring_not_certified:'.(string) ($outcome['reason'] ?? '?'), 0);
        }

        // PARK (propose-only): terminal-DONE, never a winner, never merged — the operator reviews the net diff.
        $this->store->completeTask($task->id, $workerId, [
            'status' => 'proposal_created_orphan_wiring',
            'orphan_path' => $orphanRel,
            'proposal_diff' => (string) ($outcome['proposal_diff'] ?? ''),
        ], true);

        return $this->orphanWiringTerminal($started, 'proposal_created_orphan_wiring', null, 1);
    }

    /**
     * @return array<string,mixed>
     */
    private function orphanWiringTerminal(float $started, string $status, ?string $reason, int $proposals): array
    {
        $out = [
            'status' => $status,
            'has_winner' => false,
            'proposals' => $proposals,
            'scenarios_explored' => 0,
            'elapsed_seconds' => (int) ceil(microtime(true) - $started),
        ];
        if ($reason !== null) {
            $out['reason'] = $reason;
        }

        return $out;
    }

    private function maybeRouteMultiFileRefactorToObra(AtlasLoopTask $task, array $payload, string $workerId, float $started): ?array
    {
        // PATH B (default lane for big refactors): when multi_file_refactor_via_normal_lane is ON, a
        // multi-file refactor is NOT diverted to the heavy Obra bridge — it falls through to the
        // PROVEN grind (the materializer already reads allowed_files as a list; the frozen judge +
        // certifier honour the structural_proof cross-file census + anti-relocation verdict, and the
        // acceptance's allowed_globs bound the diff to exactly the declared files). This precedes the
        // via_obra check so the normal lane wins when both are set.
        if ((bool) config('atlas.loop.multi_file_refactor_via_normal_lane', false)) {
            return null;
        }
        if (! (bool) config('atlas.loop.refactor_multi_file_via_obra', false)) {
            return null;
        }

        $objectiveKind = trim((string) ($payload['objective_kind'] ?? ''));
        if (! str_starts_with($objectiveKind, 'refactor_')) {
            return null;
        }

        $allowedFiles = AiStringListNormalizer::trimmedStrings($payload['allowed_files'] ?? []);
        if (count($allowedFiles) < 2) {
            return null;
        }

        // #7 EXECUTION LANE (default-OFF, co-gated): when no operator L4-10 is supplied AND
        // multi_file_execution_enabled is ON, the loop EXECUTES the obra itself — the adapter runs
        // the real provider on an ISOLATED worktree, certifies, and PRODUCES the real L4-10 — then
        // threads that evidence into the bridge below (PARK). A provider failure / non-real /
        // non-certified result loops back HONESTLY (never parks a non-real obra). With the flag OFF
        // the lane only accepts an operator-supplied L4-10 (byte-identical to today).
        if (trim((string) ($payload['l4_10_evidence_path'] ?? '')) === ''
            && (bool) config('atlas.loop.multi_file_execution_enabled', false)) {
            $exec = app(AtlasLoopObraExecutionAdapter::class)->executeAndProve($payload);
            if (($exec['ok'] ?? false) === true && is_string($exec['l4_10_evidence_path'] ?? null)) {
                $payload['l4_10_evidence_path'] = (string) $exec['l4_10_evidence_path'];
            } else {
                $this->store->releaseClaim($task->id, $workerId);

                return [
                    'status' => 'no_winner',
                    'has_winner' => false,
                    'proposals' => 0,
                    'scenarios_explored' => 0,
                    'elapsed_seconds' => (int) ceil(microtime(true) - $started),
                    'reason' => 'obra_execution_not_certified:'.(string) ($exec['reason'] ?? '?'),
                ];
            }
        }

        $bridgeOptions = [
            'intent' => (string) $task->objective,
            'files' => $allowedFiles,
        ];
        $evidencePath = trim((string) ($payload['l4_10_evidence_path'] ?? ''));
        if ($evidencePath !== '') {
            $bridgeOptions['l4_10_evidence_path'] = $evidencePath;
        }
        $deliveryReceiptPath = trim((string) ($payload['delivery_receipt_path'] ?? ''));
        if ($deliveryReceiptPath !== '') {
            $bridgeOptions['delivery_receipt_path'] = $deliveryReceiptPath;
        }

        $bridge = app(AtlasLoopObraBridgeService::class)->bridge($bridgeOptions);
        $status = (string) ($bridge['status'] ?? '');
        $elapsed = (int) ceil(microtime(true) - $started);
        $obraBridge = [
            'status' => $status,
            'operator_approval_required' => (bool) data_get($bridge, 'operator_approval.required', true),
            'provider_dispatches_now' => (bool) data_get($bridge, 'claim_policy.provider_dispatches_now', false),
        ];

        if (in_array($status, ['ready_for_operator_review', 'delivered'], true)) {
            // Graduated to the governed obra handoff: terminal-DONE, never a winner, zero
            // proposals (the bridge never merges; operator review is required before any merge).
            $this->store->completeTask($task->id, $workerId, [
                'status' => 'proposal_created_obra_multi_file_refactor',
                'obra_bridge' => $obraBridge,
            ], true);

            return [
                'status' => 'proposal_created_obra_multi_file_refactor',
                'has_winner' => false,
                'proposals' => 0,
                'scenarios_explored' => 0,
                'elapsed_seconds' => $elapsed,
                'obra_bridge' => $obraBridge,
            ];
        }

        // Blocked bridge (no certified L4-10 real evidence). TERMINAL no_winner — the block is
        // STRUCTURAL (operator_approval_required / multi_file_execution OFF), so every re-grind is
        // byte-identical and re-blocks. releaseClaim() here would spin claim->release->re-claim,
        // burning one attempt per claim until the task zombies (pending @ max_attempts, claimable=0)
        // and starves the queue (observed live: an extract_class_proof seed exhausted 5/5 in <4min
        // and sat as a dead pending row, blocking the clean queue_starved stop). Finalize so the
        // refiller can move on. Single-file fall-through is still never taken — this returns first.
        $this->store->completeTask($task->id, $workerId, [
            'status' => 'no_winner',
            'reason' => 'obra_bridge_blocked_by_l4_10:'.$status,
            'obra_bridge' => $obraBridge,
        ], false);

        return [
            'status' => 'no_winner',
            'has_winner' => false,
            'proposals' => 0,
            'scenarios_explored' => 0,
            'elapsed_seconds' => $elapsed,
            'reason' => 'obra_bridge_blocked_by_l4_10:'.$status,
            'obra_bridge' => $obraBridge,
        ];
    }

    /**
     * L6-11 seam: hand the terminal grind outcome to the predictive bridge. The bridge
     * is flag-gated (default OFF), fail-open, and writes telemetry only. This wrapper
     * adds a final fail-open shell so even an unexpected bridge construction issue can
     * never escape the grind.
     *
     * @param  array<string,mixed>  $grindResult
     */
    private function recordPredictiveOutcome(AtlasLoopTask $task, array $grindResult): void
    {
        try {
            $this->predictiveBridge->recordGrind($grindResult, [
                'objective' => (string) $task->objective,
                'target_path' => (string) $task->target_path,
            ]);
        } catch (Throwable) {
            // Telemetry must never crash a grind.
        }
    }

    /**
     * ACDE R2 — append the IN-LANE extract-sequence outcome to the SAME decomposition corpus the obra path
     * feeds, so the prior-read (R2-read) can ground the next sequence's worst-first ordering + tractable
     * threshold for a recurring shape. Fires only for an extract-sequence task (payload carries
     * extract_sequence_plan, set by the framework-refactor synthesizer when extract_sequence_enabled is ON);
     * fail-open + flag-gated (the recorder is a no-op when decomposition_corpus_enabled is OFF) =>
     * byte-identical until armed. The plan's steps become the fingerprinted {nodes}; certified = the grind
     * produced a certified winner.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $grindResult
     */
    private function recordExtractSequenceOutcome(array $payload, array $grindResult): void
    {
        $plan = $payload['extract_sequence_plan'] ?? null;
        if (! is_array($plan) || $plan === []) {
            return; // not an in-lane extract-sequence task
        }
        try {
            (new AtlasLoopDecompositionOutcomeRecorder)->record(
                ['nodes' => array_values($plan)],
                'refactor_extract_sequence',
                (bool) ($grindResult['has_winner'] ?? false),
                (string) ($grindResult['status'] ?? ''),
                1,
            );
        } catch (Throwable) {
            // Telemetry must never crash a grind.
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function strategyBanditDecision(AtlasLoopTask $task, array $payload, ?int $scenarios): array
    {
        if (is_array($payload['scenario_strategies'] ?? null) && $payload['scenario_strategies'] !== []) {
            return [
                'schema_version' => AtlasLoopExplorerStrategyBanditService::SCHEMA_VERSION.'.decision.v1',
                'status' => 'operator_override',
                'applied' => false,
                'reason' => 'task_payload_already_declares_scenario_strategies',
            ];
        }

        return $this->strategyBandit->decideForTask((string) $task->target_path, [
            'scenario_count' => $scenarios,
        ]);
    }

    /**
     * @param  array<string,mixed>  $decision
     * @return array<string,mixed>
     */
    private function summariseStrategyBanditDecision(array $decision): array
    {
        return [
            'schema_version' => (string) ($decision['schema_version'] ?? AtlasLoopExplorerStrategyBanditService::SCHEMA_VERSION.'.decision.v1'),
            'status' => (string) ($decision['status'] ?? 'unknown'),
            'applied' => (bool) ($decision['applied'] ?? false),
            'target_type' => (string) ($decision['target_type'] ?? ''),
            'scenario_count' => isset($decision['scenario_count']) ? (int) $decision['scenario_count'] : null,
            'applied_scenario_count' => isset($decision['applied_scenario_count']) ? (int) $decision['applied_scenario_count'] : null,
            'proven_order' => array_values((array) ($decision['proven_order'] ?? [])),
            'selected_strategy_keys' => array_values((array) ($decision['selected_strategy_keys'] ?? [])),
            'baseline_strategy_keys' => array_values((array) ($decision['baseline_strategy_keys'] ?? [])),
            'distribution_changed' => (bool) ($decision['distribution_changed'] ?? false),
            'token_efficiency_delta_per_1k' => $decision['token_efficiency_delta_per_1k'] ?? null,
            'blockers' => array_values((array) ($decision['blockers'] ?? [])),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function usesFrameworkMaterializer(array $payload): bool
    {
        return trim((string) ($payload['materializer'] ?? '')) === 'framework';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function shouldCompileIntentVerifier(array $payload): bool
    {
        if ((bool) ($payload['intent_verifier_factory'] ?? false)) {
            return true;
        }

        return trim((string) ($payload['materializer'] ?? '')) === 'framework'
            && AiStringListNormalizer::trimmedStrings(data_get($payload, 'acceptance.commands', [])) === [];
    }

    /**
     * @return array<string,mixed>
     */
    private function taskPayload(AtlasLoopTask $task): array
    {
        $raw = $task->payload;
        if (! is_array($raw)) {
            return [];
        }

        $payload = [];
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function summariseIntentVerifierPacket(array $packet): array
    {
        return [
            'schema_version' => (string) ($packet['schema_version'] ?? AtlasLoopIntentVerifierFactory::SCHEMA),
            'status' => (string) ($packet['status'] ?? 'unknown'),
            'ready' => (bool) ($packet['ready'] ?? false),
            'verifier_hash' => (string) ($packet['verifier_hash'] ?? ''),
            'target_relative_path' => (string) ($packet['target_relative_path'] ?? ''),
            'target_class' => (string) ($packet['target_class'] ?? ''),
            'verification_atom_count' => count((array) ($packet['verification_atoms'] ?? [])),
            'verification_atom_types' => AiStringListNormalizer::uniqueMappedStrings(
                (array) ($packet['verification_atoms'] ?? []),
                static fn (mixed $atom): string => is_array($atom) ? (string) ($atom['type'] ?? '') : '',
            ),
            'blockers' => array_values((array) ($packet['blockers'] ?? [])),
            'red_preflight' => is_array($packet['red_preflight'] ?? null) ? $packet['red_preflight'] : null,
            'verifier_refuters' => is_array($packet['verifier_refuters'] ?? null) ? $packet['verifier_refuters'] : null,
            'acceptance' => is_array($packet['acceptance'] ?? null)
                ? array_filter([
                    'commands' => AiStringListNormalizer::trimmedStrings(data_get($packet, 'acceptance.commands', [])),
                    'allowed_globs' => AiStringListNormalizer::trimmedStrings(data_get($packet, 'acceptance.allowed_globs', [])),
                    'frozen_globs' => AiStringListNormalizer::trimmedStrings(data_get($packet, 'acceptance.frozen_globs', [])),
                    'metric_kind' => (string) data_get($packet, 'acceptance.metric_kind', ''),
                    'revert_recheck' => (bool) data_get($packet, 'acceptance.revert_recheck', false),
                    // Lever pass-through: preserve the COMPLETENESS checklist + per-task gate keys so those
                    // dimensions actually reach the certifier (otherwise the whitelist silently strips them
                    // and the gate can never fire). New keys only => byte-identical when absent (null => dropped).
                    'completeness_criteria' => is_array(data_get($packet, 'acceptance.completeness_criteria'))
                        ? array_values(data_get($packet, 'acceptance.completeness_criteria'))
                        : null,
                    'completeness_min_coverage' => data_get($packet, 'acceptance.completeness_min_coverage'),
                    'completeness_gate' => data_get($packet, 'acceptance.completeness_gate'),
                ], static fn (mixed $v): bool => $v !== null)
                : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $explorerTask
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    /**
     * Can the adversarial gate run for this task? It needs a real base workspace (to
     * re-materialize each proposal's diff) and a frozen acceptance with commands. Both
     * the framework and the discovery materializers provide these; if either is missing
     * (malformed task) we do not fabricate a certification.
     *
     * @param  array<string,mixed>  $explorerTask
     */
    private function canGateProposals(array $explorerTask): bool
    {
        $base = (string) ($explorerTask['base_workspace'] ?? '');
        $commands = AiStringListNormalizer::trimmedStrings(data_get($explorerTask, 'acceptance.commands', []));

        return $base !== '' && is_dir($base) && $commands !== [];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function isCharacterizationTestTask(array $payload): bool
    {
        return trim((string) ($payload['objective_kind'] ?? '')) === 'characterization_test'
            && (bool) config('atlas.loop.characterization_test_lane_enabled', false)
            && trim((string) ($payload['characterization_target'] ?? '')) !== ''
            && trim((string) ($payload['characterization_operator'] ?? '')) !== '';
    }

    /**
     * Certify provider-written characterization tests via the mutant-killed verifier instead of the
     * refactor cert: a kept proposal is one whose new test PASSES on the correct target and FAILS once
     * the gate's surviving operator is re-applied. Mirrors {@see gateImplementationProposals}'s
     * per-proposal materialize/clean structure; fail-closed (a verifier error drops just that proposal).
     *
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $explorerTask
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function gateCharacterizationTestProposals(array $result, array $explorerTask, array $payload): array
    {
        $proposals = is_array($result['proposals'] ?? null) ? $result['proposals'] : [];
        $baseWorkspace = (string) ($explorerTask['base_workspace'] ?? '');
        $targetFile = trim((string) ($payload['characterization_target'] ?? ''));
        $siblingTest = trim((string) ($payload['characterization_sibling_test'] ?? ''));
        $operator = trim((string) ($payload['characterization_operator'] ?? ''));
        $timeout = max(1, (int) ($payload['characterization_timeout_seconds'] ?? 120));
        $verifier = app(AtlasLoopCharacterizationTestVerifier::class);

        $kept = [];
        $reports = [];
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            $diff = (string) ($proposal['diff_text'] ?? '');
            try {
                $gateWorkspace = $this->materializeGateWorkspace($baseWorkspace, $diff);
                try {
                    $verdict = $verifier->verify($gateWorkspace, $targetFile, $siblingTest, $operator, $timeout);
                } finally {
                    $this->removeGateWorkspace($baseWorkspace, $gateWorkspace);
                }
            } catch (Throwable $e) {
                $reports[] = [
                    'proposal_hash' => (string) ($proposal['proposal_hash'] ?? ''),
                    'certified' => false,
                    'reasons' => ['verifier_error:'.mb_substr($e->getMessage(), 0, 120)],
                ];

                continue; // fail-closed: an uncertifiable test proposal is dropped, never the whole task
            }

            $reports[] = [
                'proposal_hash' => (string) ($proposal['proposal_hash'] ?? ''),
                'certified' => (bool) ($verdict['certified'] ?? false),
                'reasons' => [(string) ($verdict['reason'] ?? '')],
                'characterization' => $verdict,
            ];
            if ((bool) ($verdict['certified'] ?? false)) {
                $proposal['characterization_certification'] = $verdict;
                // Also ride the verdict inside the proposal's QUALITY json so it survives persistence
                // (certifyProposal only persists proposal['quality']) — the merge boundary can then see
                // that this proposal's anti-fake proof is mutant-kill, not just an acceptance pass.
                $quality = is_array($proposal['quality'] ?? null) ? $proposal['quality'] : [];
                $quality['characterization_certification'] = $verdict;
                $proposal['quality'] = $quality;
                $kept[] = $proposal;
            }
        }

        $result['proposals'] = $kept;
        $result['proposals_certified_for_review'] = count($kept);
        $result['characterization_test_certification'] = [
            'schema_version' => 'atlas.loop.characterization_test_certification.v1.summary',
            'proposals_in' => count($proposals),
            'proposals_certified' => count($kept),
            'target' => $targetFile,
            'sibling_test' => $siblingTest,
            'operator' => $operator,
            'reports' => $reports,
        ];

        return $result;
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $explorerTask
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function gateImplementationProposals(array $result, array $explorerTask, array $payload, bool $frameworkTask = true): array
    {
        $proposals = is_array($result['proposals'] ?? null) ? $result['proposals'] : [];
        $acceptance = is_array($explorerTask['acceptance'] ?? null) ? $explorerTask['acceptance'] : [];
        $baseWorkspace = (string) ($explorerTask['base_workspace'] ?? '');
        $sealedHoldouts = $this->sealedHoldoutCommands($payload);
        $refuterCommands = self::withIndependentJudge(
            $this->semanticRefuterCommands($payload),
            (string) config('atlas.loop.independent_judge_cmd', ''),
        );
        $consumerCommands = $this->crossFileConsumerCommands($payload);
        $kept = [];
        $gateReports = [];
        $certificationReports = [];

        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            $diff = (string) ($proposal['diff_text'] ?? '');
            // On the discovery (non-framework) path the certifier can fail for reasons
            // specific to a self-contained workspace; fail-CLOSED per-proposal (drop it)
            // instead of failing the whole task, so the hole is closed without crashing
            // the 24h loop. The framework path keeps its original strict behavior.
            try {
                $gateWorkspace = $this->materializeGateWorkspace($baseWorkspace, $diff);
                try {
                    $verdict = $this->semanticCertifier->certify($gateWorkspace, $acceptance, [
                        'objective' => (string) ($proposal['objective'] ?? $explorerTask['objective'] ?? ''),
                        'allowed_files' => $this->semanticAllowedFiles($payload, $explorerTask),
                        'sealed_holdout_commands' => $sealedHoldouts,
                        'mutation_property_commands' => $this->mutationPropertyCommands($payload),
                        'cross_file_consumer_commands' => $consumerCommands,
                        'consumer_contracts' => $this->crossFileConsumerContracts($payload),
                        'code_graph_workspace' => $payload['code_graph_workspace'] ?? base_path(),
                        'semantic_refuter_commands' => $refuterCommands,
                        'provider_refuters_required' => $this->semanticRefutersRequired($payload, count($refuterCommands)),
                        'refuter_provider' => $payload['refuter_provider'] ?? $payload['provider'] ?? null,
                        'refuter_timeout_seconds' => $payload['refuter_timeout_seconds'] ?? null,
                    ]);
                } finally {
                    $this->removeGateWorkspace($baseWorkspace, $gateWorkspace);
                }
            } catch (Throwable $e) {
                if ($frameworkTask) {
                    throw $e; // framework path unchanged: a gate error is fatal
                }
                $certificationReports[] = [
                    'proposal_hash' => (string) ($proposal['proposal_hash'] ?? ''),
                    'certified' => false,
                    'level' => 'gate_error',
                    'reasons' => ['gate_error:'.mb_substr($e->getMessage(), 0, 120)],
                ];

                continue; // fail-closed: uncertifiable proposal is dropped
            }

            $deterministicGate = is_array($verdict['deterministic_gate'] ?? null) ? $verdict['deterministic_gate'] : [];
            $gateReports[] = [
                'proposal_hash' => (string) ($proposal['proposal_hash'] ?? ''),
                'certified' => (bool) ($deterministicGate['certified'] ?? false),
                'reasons' => $deterministicGate['reasons'] ?? [],
                'report' => $deterministicGate['report'] ?? [],
            ];
            $certificationReports[] = [
                'proposal_hash' => (string) ($proposal['proposal_hash'] ?? ''),
                'certified' => (bool) ($verdict['certified'] ?? false),
                'level' => (string) ($verdict['level'] ?? ''),
                'reasons' => $verdict['reasons'] ?? [],
                'provider_refuters' => $verdict['provider_refuters'] ?? [],
                'adversarial_panel' => $verdict['adversarial_panel'] ?? [],
                'mutation_adequacy_gate' => $verdict['mutation_adequacy_gate'] ?? [],
                'cross_file_consumer_gate' => $verdict['cross_file_consumer_gate'] ?? [],
                'receipt' => $verdict,
            ];
            // COMPREHENSION GROUNDING GATE — keep-conjunct on the cert. A certified proposal whose declared
            // files cite a symbol resolving NOWHERE in the repo is treated as hallucinated comprehension and
            // dropped. FAIL-OPEN: no citations or an unreadable root => grounded=true (the gate can only
            // refute a positively-fabricated citation, never block a real one). Flag OFF => grounded forced
            // true => byte-identical to the pre-wire keep decision. The receipt rides onto THIS proposal's
            // certification report (the last one appended above) for provenance.
            $grounded = true;
            if ((bool) config('atlas.loop.comprehension_grounding_gate_enabled', true)) {
                $gate = $this->comprehensionGroundingGate ?? new AtlasLoopComprehensionGroundingGate;
                $citations = $this->comprehensionCitations($payload, $explorerTask);
                $groundingReceipt = $gate->ground(
                    (string) ($proposal['objective'] ?? $explorerTask['objective'] ?? ''),
                    $citations,
                    (string) ($payload['code_graph_workspace'] ?? base_path()),
                );
                $grounded = (bool) ($groundingReceipt['grounded'] ?? true);
                $lastReportKey = array_key_last($certificationReports);
                if ($lastReportKey !== null) {
                    $certificationReports[$lastReportKey]['comprehension_grounding'] = $groundingReceipt;
                }
            }
            if ((bool) ($verdict['certified'] ?? false) && $grounded) {
                $proposal['implementation_gate'] = $deterministicGate['report'] ?? [];
                $proposal['semantic_implementation_certification'] = $verdict;
                // Ride the cert's delivery_confidence + quality_grade inside the proposal's QUALITY json so
                // they survive persistence (AtlasLoopStore::certifyProposal only persists proposal['quality']).
                // The post-merge confidence-calibration feeder reads delivery_confidence.confidence as the
                // predicted outcome; the receipt's grade becomes durable. Purely additive (mirrors the
                // characterization lane); absent keys store null and the feeder no-ops.
                $quality = is_array($proposal['quality'] ?? null) ? $proposal['quality'] : [];
                $quality['delivery_confidence'] = is_array($verdict['delivery_confidence'] ?? null) ? $verdict['delivery_confidence'] : null;
                $quality['quality_grade'] = is_array($verdict['quality_grade'] ?? null) ? $verdict['quality_grade'] : null;
                $proposal['quality'] = $quality;
                $kept[] = $proposal;
            }
        }

        $result['proposals'] = $kept;
        $result['proposals_certified_for_review'] = count($kept);
        $result['implementation_gate'] = [
            'schema_version' => 'atlas.loop.framework_implementation_gate.v1',
            'proposals_in' => count($proposals),
            'proposals_certified' => count($kept),
            'sealed_holdout_count' => count($sealedHoldouts),
            'reports' => $gateReports,
        ];
        $result['semantic_implementation_certification'] = [
            'schema_version' => AtlasLoopSemanticImplementationCertifier::SCHEMA.'.summary',
            'proposals_in' => count($proposals),
            'proposals_certified' => count($kept),
            'provider_refuter_command_count' => count($refuterCommands),
            'provider_refuters_required' => $this->semanticRefutersRequired($payload, count($refuterCommands)),
            'cross_file_consumer_command_count' => count($consumerCommands),
            'reports' => $certificationReports,
        ];

        return $result;
    }

    /**
         * @param  array<string,mixed>  $result
         */
        private function resultHasCertifiedWinner(array $result): bool
        {
            return Grinder\AtlasLoopGrinderTierReasonResolver::resultHasCertifiedWinner($result);
        }

        /**
         * @param  array<string,mixed>  $result
         */
        private function tierReason(array $result): string
        {
            return Grinder\AtlasLoopGrinderTierReasonResolver::tierReason($result);
        }

        /**
         * @param  array<string,mixed>  $result
         * @return list<string>
         */
        private function certificationRejectionReasons(array $result): array
        {
            return Grinder\AtlasLoopGrinderTierReasonResolver::certificationRejectionReasons($result);
        }

        /**
         * @param  array<string,mixed>  $result
     * substitute for model intelligence. The conductor walks the escalation ladder
     * (best_of_n -> repair_from_refutation -> decompose -> escalate_provider), feeding its attempt-ledger
     * guidance forward into each re-run's objective and thrash-jumping on recurring failure, stopping on
     * certification or the round budget. Each tier RE-RUNS the same explorer+gate path with progressively
     * deeper search (provider rotation is moot on the single live engine — depth is the lever). The full
     * winning runner-result (proposals/diffs/explorations) is captured by-ref — NOT conduct()'s lite
     * last_outcome, which carries no proposals. Fully fail-open: any fault returns the ORIGINAL $result.
     *
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $explorerTask
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function escalateViaConductor(array $result, array $explorerTask, array $payload, bool $frameworkTask, bool $universal, array $options): array
    {
        try {
            $conductor = $this->conductor ?? new AtlasLoopAutonomousConductor;
            $winning = $result;
            $taskBudgetSeconds = is_numeric($explorerTask['search_time_budget_seconds'] ?? null)
                ? max(0, (int) $explorerTask['search_time_budget_seconds'])
                : 0;
            $elapsedBeforeEscalation = is_numeric($result['elapsed_seconds'] ?? null)
                ? max(0.0, (float) $result['elapsed_seconds'])
                : 0.0;
            $escalationStartedAt = microtime(true);
            $remainingTaskBudget = static function () use ($taskBudgetSeconds, $elapsedBeforeEscalation, $escalationStartedAt): ?int {
                if ($taskBudgetSeconds <= 0) {
                    return null;
                }

                $elapsed = $elapsedBeforeEscalation + (microtime(true) - $escalationStartedAt);

                return max(0, $taskBudgetSeconds - (int) ceil($elapsed));
            };

            $runTier = function (array $tierOptions, string $guidance, string $providerOverride = '') use (&$winning, $explorerTask, $payload, $frameworkTask, $universal, $remainingTaskBudget): array {
                $task = $explorerTask;
                $remaining = $remainingTaskBudget();
                if ($remaining !== null) {
                    if ($remaining <= 0) {
                        return ['certified' => false, 'reason' => 'task_time_budget_exhausted'];
                    }
                    $task['search_time_budget_seconds'] = $remaining;
                }
                if (trim($guidance) !== '') {
                    $task['objective'] = (string) ($task['objective'] ?? '')."\n\n".$guidance;
                }
                // ACDE lever #5 — the strong-engine rung pins the task to a genuinely stronger provider for
                // EVERY scenario of this tier (provider + single-entry portfolio). '' => no override =>
                // byte-identical to the same-engine tier.
                if ($providerOverride !== '') {
                    $task['provider'] = $providerOverride;
                    $task['scenario_providers'] = [$providerOverride];
                }
                $tierResult = $this->runner->run([$task], $tierOptions);
                if ($this->isCharacterizationTestTask($payload) && $this->canGateProposals($explorerTask)) {
                    $tierResult = $this->gateCharacterizationTestProposals($tierResult, $explorerTask, $payload);
                } elseif (($frameworkTask || $universal) && $this->canGateProposals($explorerTask)) {
                    $tierResult = $this->gateImplementationProposals($tierResult, $explorerTask, $payload, $frameworkTask);
                }
                if ($this->resultHasCertifiedWinner($tierResult)) {
                    $winning = $tierResult;

                    return ['certified' => true, 'reason' => 'certified'];
                }

                return ['certified' => false, 'reason' => $this->tierReason($tierResult)];
            };

            $deep = array_merge($options, ['scenarios_per_task' => max(1, (int) config('atlas.loop.max_scenarios_per_task', 12))]);

            // ACDE Leap 2 — the `decompose` tier actually DECOMPOSES. Until now every tier mapped to the
            // same scenario-width closure, so `decompose` only widened the search — it never changed the
            // STRUCTURE of the attempt. Now, when planning is ON, the decompose tier runs the obra planning
            // path (maybePlan -> AtlasObraExecutor, via the execution adapter): the goal is split into a
            // readiness-gated DAG (oracle-gated when a fixture exists) and executed node-by-node in an
            // isolated worktree. Fail-OPEN: a throw is caught by the conductor as a failed round (never a
            // fabricated success); with planning OFF this degrades to the prior scenario-width closure.
            $runDecomposeTier = function (string $guidance) use ($explorerTask, $payload, $deep, $runTier): array {
                if (! (bool) config('atlas.loop.planning_enabled', false)) {
                    return $runTier($deep, $guidance); // byte-identical to the pre-Leap-2 decompose tier
                }

                $adapter = $this->obraAdapter ?? app(AtlasLoopObraExecutionAdapter::class);
                $obraPayload = $payload;
                // The decompose tier targets the obra planning path; carry the explorer objective + any
                // accumulated escalation guidance so the planner sees the same intent the runner saw.
                $objective = trim((string) ($explorerTask['objective'] ?? ($payload['objective'] ?? '')));
                if (trim($guidance) !== '') {
                    $objective = $objective."\n\n".$guidance;
                }
                $obraPayload['objective'] = $objective;

                $exec = $adapter->executeAndProve($obraPayload);
                if (($exec['ok'] ?? false) === true) {
                    return ['certified' => true, 'reason' => 'decomposed_and_certified', 'strategy' => 'decompose'];
                }

                return ['certified' => false, 'reason' => 'decompose:'.(string) ($exec['reason'] ?? 'not_certified'), 'strategy' => 'decompose'];
            };

            $byTier = [
                'best_of_n' => $options,
                'repair_from_refutation' => $deep,
                'escalate_provider' => $deep,
            ];
            $tiers = [];
            foreach ($byTier as $name => $tierOptions) {
                $tiers[$name] = fn (string $goal, string $guidance, ?array $spec, int $round): array => $runTier($tierOptions, $guidance);
            }
            // ACDE lever #5 — the MISSING N×M rung. The ladder's strongest tier used to re-run the SAME weak
            // engine wider ('escalate_provider' => $deep), so a no-winner just dead-ended. When a genuinely
            // stronger engine is configured, hand the FINAL rung that engine on the now-refuted/ledger-enriched
            // task, under the SAME pétreo frozen judge — converting a refusal (a DQS defect) into a certified
            // delivery on the same run. Default '' (or Claude/same-as-weak) => byte-identical (re-runs $deep).
            $strongProvider = $this->escalationStrongProvider($payload);
            if ($strongProvider !== '') {
                $tiers['escalate_provider'] = fn (string $goal, string $guidance, ?array $spec, int $round): array => $runTier($deep, $guidance, $strongProvider);
            }
            $tiers['decompose'] = fn (string $goal, string $guidance, ?array $spec, int $round): array => $runDecomposeTier($guidance);

            $conduct = $conductor->conduct((string) ($explorerTask['objective'] ?? ''), ['tier_executors' => $tiers]);

            $result = $winning;
            $result['conductor_escalation'] = [
                'ran' => true,
                'certified' => (bool) ($conduct['certified'] ?? false),
                'rounds' => (int) ($conduct['rounds'] ?? 0),
                'final_reason' => (string) ($conduct['final_reason'] ?? ''),
            ];

            return $result;
        } catch (Throwable $e) {
            $result['conductor_escalation'] = ['ran' => true, 'error' => mb_substr($e->getMessage(), 0, 200)];

            return $result;
        }
    }

    /**
     * ACDE lever #5 — resolve the strong escalation engine for THIS task, reading config (container-safe
     * here in the grinder) and delegating the policy to the pure {@see resolveStrongProvider}.
     *
     * @param  array<string,mixed>  $payload
     */
    private function escalationStrongProvider(array $payload): string
    {
        $weakDefault = trim((string) ($payload['provider'] ?? '')) ?: trim((string) config('atlas.loop.default_provider', ''));

        return self::resolveStrongProvider(
            (string) config('atlas.loop.escalation_strong_provider', ''),
            $weakDefault,
        );
    }

    /**
     * Pure policy for the strong-engine rung. Returns the strong provider key, or '' to fall back to the
     * same-engine $deep tier (byte-identical). Refuses '' / Claude-Anthropic (3rd-party block + operator
     * no-burn rule) / a provider equal to the weak engine the prior rounds already used (anti-theatre — an
     * "escalation" to the same engine is not an escalation).
     */
    /**
     * S216 — append the operator-configured INDEPENDENT-engine judge command to the semantic refuter set,
     * so its verdict enters judge_verdicts stamped source_class='external' (certifier) and can satisfy the
     * S214 source-class independence floor. Pure + public-static for direct testing. Empty cmd or an exact
     * duplicate => the list is returned unchanged (byte-identical: no extra judge spawned).
     *
     * @param  list<string>  $refuterCommands
     * @return list<string>
     */
    public static function withIndependentJudge(array $refuterCommands, string $independentJudgeCmd): array
    {
        $cmd = trim($independentJudgeCmd);
        if ($cmd === '' || in_array($cmd, $refuterCommands, true)) {
            return $refuterCommands;
        }
        $refuterCommands[] = $cmd;

        return $refuterCommands;
    }

    public static function resolveStrongProvider(string $strong, string $weakDefault): string
    {
        $strong = trim($strong);
        if ($strong === '') {
            return '';
        }
        $lower = mb_strtolower($strong);
        if (str_contains($lower, 'claude') || str_contains($lower, 'anthropic')) {
            return '';
        }
        if ($strong === trim($weakDefault)) {
            return '';
        }

        return $strong;
    }

    private function materializeGateWorkspace(string $baseWorkspace, string $diff): string
    {
        return (new AtlasLoopGateWorkspaceProvisioner())->materialize($baseWorkspace, $diff);
    }

    private function removeGateWorkspace(string $baseWorkspace, string $workspace): void
    {
        (new AtlasLoopGateWorkspaceProvisioner())->remove($baseWorkspace, $workspace);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function sealedHoldoutCommands(array $payload): array
    {
        return Grinder\AtlasLoopGrinderCertificationCommandExtractor::sealedHoldoutCommands($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function semanticRefuterCommands(array $payload): array
    {
        return Grinder\AtlasLoopGrinderCertificationCommandExtractor::semanticRefuterCommands($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function mutationPropertyCommands(array $payload): array
    {
        return Grinder\AtlasLoopGrinderCertificationCommandExtractor::mutationPropertyCommands($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function crossFileConsumerCommands(array $payload): array
    {
        return Grinder\AtlasLoopGrinderCertificationCommandExtractor::crossFileConsumerCommands($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<array<string,mixed>>
     */
    private function crossFileConsumerContracts(array $payload): array
    {
        return Grinder\AtlasLoopGrinderCertificationCommandExtractor::crossFileConsumerContracts($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function semanticRefutersRequired(array $payload, int $configured): int
    {
        return Grinder\AtlasLoopGrinderCertificationCommandExtractor::semanticRefutersRequired($payload, $configured);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $explorerTask
     * @return list<string>
     */
    private function semanticAllowedFiles(array $payload, array $explorerTask): array
    {
        return Grinder\AtlasLoopGrinderComprehensionCitationDeriver::semanticAllowedFiles($payload, $explorerTask);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $explorerTask
     * @return list<string>
     */
    private function comprehensionCitations(array $payload, array $explorerTask): array
    {
        return Grinder\AtlasLoopGrinderComprehensionCitationDeriver::comprehensionCitations($payload, $explorerTask);
    }
}
