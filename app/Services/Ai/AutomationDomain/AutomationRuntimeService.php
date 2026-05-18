<?php

namespace App\Services\Ai\AutomationDomain;

use App\Models\AiAutomationRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Orchestrates the Automation / Tool Factory flow end-to-end:
 *
 *   need -> ToolSelection -> Plan (browser|api|terminal|builder)
 *        -> PolicyBridge (recorded as plan.policy_decision and Receipt)
 *        -> EvidenceBridge (Receipt + Artifact)
 *        -> close run
 *
 * Optionally integrates with the Evidence Runtime (Meta 4) via
 * AutomationEvidenceBridge. Tolerant: when Evidence Runtime is absent the
 * run still persists; bridges report `evidence_attached=false`.
 *
 * Atlas Automation Runtime is anti-action: it NEVER opens a real browser,
 * runs a real shell command, or calls a real remote API. Real execution is
 * delegated to the Tool Runtime AFTER Policy approves the plan.
 */
class AutomationRuntimeService
{
    public function __construct(
        private readonly AutomationPlanService $plans,
        private readonly BrowserAutomationPlanningService $browser,
        private readonly ApiAutomationPlanningService $api,
        private readonly TerminalAutomationPlanningService $terminal,
        private readonly ToolBuilderPlanService $toolBuilder,
        private readonly ToolSelectionWorkflowService $toolSelection,
        private readonly ToolEvolutionLoopService $evolution,
        private readonly AutomationEvidenceBridge $evidenceBridge,
        private readonly AutomationPolicyBridge $policyBridge,
    ) {}

    /**
     * Start a new Automation run.
     *
     * @param  array<string,mixed>  $args
     */
    public function startRun(array $args): AiAutomationRun
    {
        $runKind = (string) ($args['run_kind'] ?? '');
        if (! in_array($runKind, AutomationDomainCanon::RUN_KINDS, true)) {
            throw AutomationDomainException::invalidRunKind($runKind);
        }

        $objective = (string) ($args['objective'] ?? '');
        if ($objective === '') {
            throw AutomationDomainException::missingField('objective');
        }

        $hashInput = [
            'run_kind' => $runKind,
            'mission_id' => $args['mission_id'] ?? null,
            'work_order_id' => $args['work_order_id'] ?? null,
            'objective' => $objective,
            'inputs' => $args['inputs'] ?? [],
            'started_at_token' => $args['hash_seed'] ?? (string) Str::uuid(),
        ];

        return AiAutomationRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $args['mission_id'] ?? null,
            'work_order_id' => $args['work_order_id'] ?? null,
            'run_kind' => $runKind,
            'status' => AutomationDomainCanon::STATUS_OPEN,
            'objective' => $objective,
            'inputs' => $args['inputs'] ?? null,
            'next_action' => 'plan_or_decide',
            'receipt_hash' => AutomationCanonicalHash::sha256($hashInput),
        ]);
    }

    public function closeRun(AiAutomationRun $run, string $status, ?string $nextAction = null, ?array $outputs = null, ?array $blockers = null): AiAutomationRun
    {
        if (! in_array($status, AutomationDomainCanon::RUN_STATUSES, true)) {
            throw new \InvalidArgumentException("invalid run status [{$status}]");
        }
        $run->status = $status;
        if ($nextAction !== null) {
            $run->next_action = $nextAction;
        }
        if ($outputs !== null) {
            $run->outputs = $outputs;
        }
        if ($blockers !== null) {
            $run->blockers = $blockers;
        }
        if (in_array($status, [AutomationDomainCanon::STATUS_CLOSED, AutomationDomainCanon::STATUS_FAILED], true)) {
            $run->completed_at = Carbon::now();
        }
        $run->save();

        return $run;
    }

    /**
     * End-to-end safe smoke flow used by tests and the CLI `smoke` action.
     *
     * Exercises:
     *
     *   1. tool selection (need = "fetch public GitHub repo metadata")
     *   2. API plan against an official endpoint (`GET`, no auth) -> planned
     *   3. terminal plan (read-only `ls` inside workspace) -> planned
     *   4. browser plan with `auth_required=true` (Instagram-style) -> blocked
     *   5. tool builder blueprint with `risk_level=high` -> blocked
     *   6. policy gate evaluation for blocked plans
     *   7. evidence receipt emission for the run
     *   8. evolution event from an in-memory observation
     *   9. closes the run with deterministic outputs.
     *
     * No external network/disk/process side effects.
     *
     * @return array{run: AiAutomationRun, summary: array<string,mixed>}
     */
    public function smokeRun(): array
    {
        $run = $this->startRun([
            'run_kind' => AutomationDomainCanon::RUN_TOOL_BUILD,
            'objective' => 'Automation smoke: exercise planning, tool selection, builder, evolution and policy gating.',
            'inputs' => ['source' => 'cli-smoke'],
        ]);

        $toolDecision = $this->toolSelection->decide([
            'need' => 'Fetch GitHub repository metadata (stars, last commit, license).',
            'capability_id' => 'api.readonly',
            'official_api_available' => true,
            'security_sensitive' => false,
            'risk_level' => 'low',
        ], $run);

        $apiPlan = $this->api->plan($run, [
            'endpoint' => 'https://api.github.com/repos/atlas/example',
            'method' => 'GET',
            'auth_required' => false,
            'expected_cost_band' => 'free',
            'title' => 'GitHub repo metadata (read-only, no auth)',
        ]);

        $terminalPlan = $this->terminal->plan($run, [
            'command' => 'ls -la docs/',
            'working_dir' => '/Users/operator/atlas-workspace',
            'title' => 'List docs/ for repository overview',
        ]);

        $browserPlan = $this->browser->plan($run, [
            'target_url' => 'https://instagram.com/profile/atlas',
            'scope' => 'read-public-profile',
            'auth_required' => true,
            'tos_restrictive' => true,
            'anti_bot_protection' => true,
            'title' => 'Instagram profile read (auth-required, blocked by default)',
        ]);

        $builderPlan = $this->toolBuilder->plan($run, [
            'tool_id' => 'atlas.demo.deploy_helm',
            'name' => 'Atlas demo Helm deployer',
            'purpose' => 'Deploy demo Helm chart to a non-prod cluster (high-risk template).',
            'risk_level' => 'high',
            'external_action' => true,
            'requires_credentials' => true,
            'destructive' => false,
        ]);

        // Policy gate ALL plans uniformly. Blocked plans get a `blocked`
        // safety decision; planned plans get `allow` or `require_approval`
        // depending on policy.
        $policyDecisions = [];
        foreach ([$apiPlan, $terminalPlan, $browserPlan, $builderPlan] as $plan) {
            $policyDecisions[$plan->id] = $this->policyBridge->evaluatePlan($plan, $run);
        }

        // Evidence: emit one receipt summarising the planning step.
        $receipt = $this->evidenceBridge->emitPlanningReceipt($run, [
            'tool_decision_id' => $toolDecision->id,
            'plan_count' => 4,
            'blocked_count' => collect([$apiPlan, $terminalPlan, $browserPlan, $builderPlan])
                ->where('status', AutomationDomainCanon::PLAN_STATUS_BLOCKED)
                ->count(),
        ]);

        // Evolution: emit one in-memory event for the smoke tool itself.
        $evolutionEvent = $this->evolution->emitEvent([
            'tool_id' => 'atlas.demo.smoke',
            'event_kind' => AutomationDomainCanon::EVOLUTION_SUCCESS_STREAK,
            'observation' => ['passed' => 7, 'failed' => 0, 'sample_size' => 7],
            'recommendation' => AutomationDomainCanon::EVOLUTION_RECOMMENDATION_KEEP,
        ], $run);

        $blocked = collect($policyDecisions)
            ->filter(static fn ($d): bool => $d !== null && (string) ($d['decision'] ?? '') !== 'allow')
            ->count();

        $this->closeRun($run, AutomationDomainCanon::STATUS_CLOSED, 'await_operator_decision', [
            'tool_decision_id' => $toolDecision->id,
            'plans' => [
                'api' => $apiPlan->id,
                'terminal' => $terminalPlan->id,
                'browser' => $browserPlan->id,
                'tool_builder' => $builderPlan->id,
            ],
            'evolution_event_id' => $evolutionEvent->id,
            'receipt_id' => $receipt?->id,
            'plans_blocked' => $blocked,
        ]);

        return [
            'run' => $run->refresh(),
            'summary' => [
                'tool_decision_id' => $toolDecision->id,
                'tool_decision_kind' => $toolDecision->decision_kind,
                'plans' => [
                    'api' => ['id' => $apiPlan->id, 'status' => $apiPlan->status, 'hash' => $apiPlan->plan_hash],
                    'terminal' => ['id' => $terminalPlan->id, 'status' => $terminalPlan->status, 'hash' => $terminalPlan->plan_hash],
                    'browser' => ['id' => $browserPlan->id, 'status' => $browserPlan->status, 'hash' => $browserPlan->plan_hash],
                    'tool_builder' => ['id' => $builderPlan->id, 'status' => $builderPlan->status, 'hash' => $builderPlan->plan_hash],
                ],
                'policy_decisions' => $policyDecisions,
                'evolution' => [
                    'id' => $evolutionEvent->id,
                    'event_kind' => $evolutionEvent->event_kind,
                    'recommendation' => $evolutionEvent->recommendation,
                ],
                'receipt_id' => $receipt?->id,
                'plans_blocked' => $blocked,
            ],
        ];
    }
}
