<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\Framework\AtlasLoopFrameworkMaterializer;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopRunPersister;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use App\Services\Ai\Cognitive\PredictiveFailure\AtlasLoopPredictiveOutcomeBridge;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\Support\AiStringListNormalizer;
use RuntimeException;
use Symfony\Component\Process\Process;
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
    ) {}

    /**
     * @return array{status:string, has_winner:bool, proposals:int, scenarios_explored:int, elapsed_seconds:int, cost_estimate_usd?:?float, cost_cents?:int, tokens_used?:?int, reason?:string}
     */
    public function grind(AtlasLoopTask $task, string $workerId, ?int $scenarios = null, string $workspaceRoot = '', ?int $timeBudgetSeconds = null): array
    {
        $started = microtime(true);
        $this->store->markRunning($task->id, $workerId);

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

            $strategyBanditDecision = $this->strategyBanditDecision($task, $payload, $scenarios);
            // ACDE lever #7 — if THIS exact target has gone N real attempts with ZERO certs it is hopeless
            // (already-clean / unfixable-as-framed); skip BEFORE best-of-N burns the budget. A skip is an
            // honest TERMINAL refusal (a DQS defect) via completeTask(success=false) — NOT releaseClaim, which
            // would re-claim -> re-grind -> re-fail forever (the zombie-stall). Runs before materialization so
            // there is no workspace to clean. Flag OFF => verdict is always 'open' => byte-identical.
            if ((string) data_get($strategyBanditDecision, 'target_verdict.verdict') === 'hopeless') {
                $tv = (array) ($strategyBanditDecision['target_verdict'] ?? []);
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

            $result = $this->runner->run([$explorerTask], $options);
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
        $refuterCommands = $this->semanticRefuterCommands($payload);
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
            if ((bool) ($verdict['certified'] ?? false)) {
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
     * ACDE Tier-1 #5: does this (post-gate) runner result carry a certified winner? Mirrors the
     * persister's has_winner rule (proposals !== []), so the conductor escalates iff persist would
     * otherwise record no winner.
     *
     * @param  array<string,mixed>  $result
     */
    private function resultHasCertifiedWinner(array $result): bool
    {
        return is_array($result['proposals'] ?? null) && $result['proposals'] !== [];
    }

    /**
     * ACDE Tier-1 #5: a STABLE reason string for the conductor's attempt ledger — 'certified' on a
     * winner, else the first non-empty rejection across explorations (kept stable so a recurring
     * identical failure trips the ledger's thrashing jump), else 'no_winner'.
     *
     * @param  array<string,mixed>  $result
     */
    private function tierReason(array $result): string
    {
        if ($this->resultHasCertifiedWinner($result)) {
            return 'certified';
        }
        foreach ((array) ($result['explorations'] ?? []) as $exploration) {
            foreach ((array) (is_array($exploration) ? ($exploration['rejected_reasons'] ?? []) : []) as $reason) {
                $reason = trim((string) $reason);
                if ($reason !== '') {
                    return $reason;
                }
            }
        }

        return 'no_winner';
    }

    /**
     * ACDE Tier-1 #5: escalate a no-winner round through the autonomous conductor — the flow-level
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

            $runTier = function (array $tierOptions, string $guidance, string $providerOverride = '') use (&$winning, $explorerTask, $payload, $frameworkTask, $universal): array {
                $task = $explorerTask;
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
        if ($baseWorkspace === '' || ! is_dir($baseWorkspace)) {
            throw new RuntimeException('framework gate: base workspace missing');
        }
        if ($diff === '' || str_ends_with($diff, '…')) {
            throw new RuntimeException('framework gate: proposal diff missing or truncated');
        }

        $workspace = sys_get_temp_dir().'/atlas-loop-fw-gate-'.bin2hex(random_bytes(5));
        if (is_dir($baseWorkspace.'/.git')) {
            $this->mustRun(['git', '-C', $baseWorkspace, 'worktree', 'add', '--detach', $workspace, 'HEAD'], 'framework_gate_worktree_add_failed', 120.0);
        } else {
            // AUTÓPSIA 12/06 (a causa-raiz do "0 propostas"): no caminho DISCOVERY o
            // base_workspace é um cp -R SEM .git — o `git worktree add` acima estourava
            // `not a git repository` em 100% das propostas self-contained e o gate
            // (universal_certification ON) as dropava todas fail-closed. O provider
            // produzia; o gate jogava fora. Para base não-git: cp -R + git init +
            // baseline commit (o MESMO contrato que o explorer self-contained usa),
            // e o diff aplica sobre um baseline real.
            $this->mustRun(['bash', '-lc', 'cp -R '.escapeshellarg($baseWorkspace).' '.escapeshellarg($workspace)], 'framework_gate_copy_failed', 120.0);
            $this->mustRun(['git', '-C', $workspace, 'init', '-q'], 'framework_gate_git_init_failed', 30.0);
            $this->mustRun(['git', '-C', $workspace, 'add', '-A'], 'framework_gate_baseline_add_failed', 60.0);
            $this->mustRun(['git', '-C', $workspace, '-c', 'user.email=atlas-loop@local', '-c', 'user.name=Atlas Loop', '-c', 'commit.gpgsign=false', 'commit', '-q', '--allow-empty', '-m', 'gate baseline'], 'framework_gate_baseline_commit_failed', 60.0);
        }
        $this->copyLocalSupport($baseWorkspace, $workspace);

        $apply = new Process(['git', 'apply', '--whitespace=nowarn', '-'], $workspace, null, null, 60.0);
        $apply->setInput($diff);
        $apply->run();
        if (! $apply->isSuccessful()) {
            $this->removeGateWorkspace($baseWorkspace, $workspace);
            throw new RuntimeException('framework gate: proposal diff did not apply cleanly: '.mb_substr($apply->getErrorOutput() ?: $apply->getOutput(), -240));
        }

        return $workspace;
    }

    private function removeGateWorkspace(string $baseWorkspace, string $workspace): void
    {
        if ($baseWorkspace !== '' && is_dir($baseWorkspace)) {
            (new Process(['git', '-C', $baseWorkspace, 'worktree', 'remove', '--force', $workspace], null, null, null, 60.0))->run();
            (new Process(['git', '-C', $baseWorkspace, 'worktree', 'prune'], null, null, null, 30.0))->run();
        }
        if (is_dir($workspace)) {
            (new Process(['rm', '-rf', $workspace], null, null, null, 60.0))->run();
        }
    }

    private function copyLocalSupport(string $baseWorkspace, string $workspace): void
    {
        // Guard de destino: no caminho base-não-git o workspace nasce de um cp -R completo
        // do base — vendor/.env já estão lá; copiar de novo aninharia (vendor/vendor).
        foreach (['vendor'] as $dir) {
            if (is_dir($baseWorkspace.'/'.$dir) && ! is_dir($workspace.'/'.$dir)) {
                $this->mustRun(['bash', '-lc', 'cp -R '.escapeshellarg($baseWorkspace.'/'.$dir).' '.escapeshellarg($workspace.'/'.$dir)], 'framework_gate_support_copy_failed_'.$dir, 180.0);
            }
        }
        foreach (['.env', '.env.testing'] as $file) {
            if (is_file($baseWorkspace.'/'.$file) && ! is_file($workspace.'/'.$file)) {
                copy($baseWorkspace.'/'.$file, $workspace.'/'.$file);
            }
        }
        foreach ([
            'bootstrap/cache',
            'storage/app',
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/testing',
            'storage/framework/views',
            'storage/logs',
        ] as $relative) {
            $dir = $workspace.'/'.$relative;
            if (! is_dir($dir)) {
                @mkdir($dir, 0o755, true);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function sealedHoldoutCommands(array $payload): array
    {
        $commands = [];
        foreach (['sealed_holdout_commands', 'wide_holdout_commands', 'final_holdout_commands'] as $key) {
            foreach (AiStringListNormalizer::trimmedStrings($payload[$key] ?? []) as $command) {
                $commands[] = $command;
            }
        }

        return AiStringListNormalizer::uniqueStrings($commands);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function semanticRefuterCommands(array $payload): array
    {
        $commands = [];
        foreach (['semantic_refuter_commands', 'provider_refuter_commands', 'refuter_commands'] as $key) {
            foreach (AiStringListNormalizer::trimmedStrings($payload[$key] ?? []) as $command) {
                $commands[] = $command;
            }
        }

        return AiStringListNormalizer::uniqueStrings($commands);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function mutationPropertyCommands(array $payload): array
    {
        $commands = [];
        foreach (['mutation_property_commands', 'property_commands', 'property_based_commands'] as $key) {
            foreach (AiStringListNormalizer::trimmedStrings($payload[$key] ?? []) as $command) {
                $commands[] = $command;
            }
        }

        return AiStringListNormalizer::uniqueStrings($commands);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function crossFileConsumerCommands(array $payload): array
    {
        $commands = [];
        foreach (['cross_file_consumer_commands', 'consumer_commands', 'consumer_contract_commands'] as $key) {
            foreach (AiStringListNormalizer::trimmedStrings($payload[$key] ?? []) as $command) {
                $commands[] = $command;
            }
        }

        return AiStringListNormalizer::uniqueStrings($commands);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<array<string,mixed>>
     */
    private function crossFileConsumerContracts(array $payload): array
    {
        $contracts = [];
        foreach (['cross_file_consumer_contracts', 'consumer_contracts', 'code_graph_consumer_contracts'] as $key) {
            foreach ((array) ($payload[$key] ?? []) as $contract) {
                if (is_array($contract)) {
                    $contracts[] = $contract;
                }
            }
        }

        return $contracts;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function semanticRefutersRequired(array $payload, int $configured): int
    {
        foreach (['provider_refuters_required', 'refuters_required', 'refuters'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return max(0, (int) $payload[$key]);
            }
        }

        return $configured;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $explorerTask
     * @return list<string>
     */
    private function semanticAllowedFiles(array $payload, array $explorerTask): array
    {
        $files = [];
        foreach ([$payload['allowed_files'] ?? [], $explorerTask['allowed_files'] ?? []] as $source) {
            foreach (AiStringListNormalizer::trimmedStrings($source) as $file) {
                $files[] = $file;
            }
        }

        return AiStringListNormalizer::uniqueStrings($files);
    }

    /**
     * @param  list<string>  $argv
     */
    private function mustRun(array $argv, string $stage, float $timeout): void
    {
        $process = new Process($argv, null, null, null, $timeout);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException($stage.': '.mb_substr($process->getErrorOutput() ?: $process->getOutput(), -240));
        }
    }
}
