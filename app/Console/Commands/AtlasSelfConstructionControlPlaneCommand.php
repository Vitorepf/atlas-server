<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LoadsFactsJsonOption;
use App\Services\Ai\SelfConstruction\ControlPlane\AtlasSelfConstructionAutonomyModePolicy;
use App\Services\Ai\SelfConstruction\ControlPlane\AtlasSelfConstructionBrainAuditAutoPriorityPolicy;
use App\Services\Ai\SelfConstruction\ControlPlane\AtlasSelfConstructionNextActionSelector;
use App\Services\Ai\SelfConstruction\ControlPlane\AtlasSelfConstructionOrganReadinessComposer;
use App\Services\Ai\SelfConstruction\ControlPlane\AtlasSelfConstructionScopeRiskBudgetGate;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only operator CLI for the Self-Construction Control Plane.
 *
 *   inspect       describe required services + non-execution guarantees.
 *   mode          read facts.autonomy_mode and emit a policy verdict.
 *   scope         read facts.scope_gate and emit the scope risk-budget verdict.
 *   scope-gate    run the deterministic AtlasSelfConstructionScopeRiskBudgetGate organ
 *                 over raw facts (requested_scope/risk_class/task_budget/cost_budget_units/
 *                 project_lane/forbidden_organs/touched_organs/rollback_ready) and emit
 *                 its {allowed,blockers,normalized_scope,max_tasks,max_cost_units,risk_floor}
 *                 envelope. The pure organ has no other production call path.
 *   next          compose organ-readiness + mode + scope + work_queue facts and print the next
 *                 action WITHOUT executing it.
 *
 * NEVER enqueues, dispatches, executes, merges, or writes ledgers.
 */
final class AtlasSelfConstructionControlPlaneCommand extends Command
{
    use LoadsFactsJsonOption;

    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:self-construction:control-plane {action : inspect|mode|scope|scope-gate|next|policy|audit-priority} {--facts= : path to a JSON facts payload} {--json}';

    protected $description = 'Read-only Control-Plane CLI: inspect | mode | scope | scope-gate | next.';

    public function handle(
        AtlasSelfConstructionOrganReadinessComposer $organReadiness,
        AtlasSelfConstructionNextActionSelector $selector,
        AtlasSelfConstructionAutonomyModePolicy $autonomyPolicy,
        AtlasSelfConstructionScopeRiskBudgetGate $scopeRiskBudgetGate,
        AtlasSelfConstructionBrainAuditAutoPriorityPolicy $auditPriorityPolicy,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'inspect' => $this->inspectAction(),
            'mode' => $this->modeAction(),
            'scope' => $this->scopeAction(),
            'scope-gate' => $this->scopeGateAction($scopeRiskBudgetGate),
            'next' => $this->nextAction($organReadiness, $selector),
            'policy' => $this->policyAction($autonomyPolicy),
            'audit-priority' => $this->auditPriorityAction($auditPriorityPolicy),
            default => $this->usage('unknown action: '.$action),
        };
    }

    /**
     * `audit-priority` consults AtlasSelfConstructionBrainAuditAutoPriorityPolicy on the supplied
     * facts payload (audit_snapshot + pending_actions) and emits its FACT-only priority verdict.
     * Wires the policy into the operator-visible CLI so it is no longer an orphan.
     */
    private function auditPriorityAction(AtlasSelfConstructionBrainAuditAutoPriorityPolicy $policy): int
    {
        $facts = $this->loadFacts();
        if ($facts === null) {
            return self::EXIT_USAGE;
        }
        $this->emit($policy->apply($facts));

        return self::EXIT_OK;
    }

    /**
     * `policy` consults the AtlasSelfConstructionAutonomyModePolicy on the supplied facts payload
     * and emits its FACT-only verdict envelope (schema/mode/blockers/reasons/readiness_summary).
     * Wires the policy into the operator-visible CLI so it is no longer an orphan.
     */
    private function policyAction(AtlasSelfConstructionAutonomyModePolicy $policy): int
    {
        $facts = $this->loadFacts();
        if ($facts === null) {
            return self::EXIT_USAGE;
        }
        $this->emit($policy->decide($facts));

        return self::EXIT_OK;
    }

    private function inspectAction(): int
    {
        $this->emit([
            'required_services' => [
                AtlasSelfConstructionOrganReadinessComposer::class,
                AtlasSelfConstructionNextActionSelector::class,
            ],
            'non_execution_guarantees' => [
                'never_enqueues',
                'never_dispatches_workers',
                'never_calls_providers',
                'never_runs_git_or_shell',
                'never_writes_ledgers',
                'never_merges_to_main',
            ],
        ]);

        return self::EXIT_OK;
    }

    private function modeAction(): int
    {
        $facts = $this->loadFacts();
        if ($facts === null) {
            return self::EXIT_USAGE;
        }
        $mode = is_array($facts['autonomy_mode'] ?? null) ? $facts['autonomy_mode'] : ['mode' => 'off'];
        $modeName = (string) ($mode['mode'] ?? 'off');
        $this->emit([
            'autonomy_mode' => $modeName,
            'allowed_to_execute' => $modeName === 'execute',
            'reasons' => array_values((array) ($mode['reasons'] ?? [])),
        ]);

        return self::EXIT_OK;
    }

    private function scopeAction(): int
    {
        $facts = $this->loadFacts();
        if ($facts === null) {
            return self::EXIT_USAGE;
        }
        $scope = is_array($facts['scope_gate'] ?? null) ? $facts['scope_gate'] : ['allowed' => false];
        $this->emit([
            'scope_gate_allowed' => (bool) ($scope['allowed'] ?? false),
            'reasons' => array_values((array) ($scope['reasons'] ?? [])),
        ]);

        return self::EXIT_OK;
    }

    /**
     * Runs the deterministic scope+risk+budget gate organ over the raw facts. Pure read-only:
     * no I/O, no side effects, deterministic output envelope. Wired here so the
     * AtlasSelfConstructionScopeRiskBudgetGate reaches a real production call path.
     */
    private function scopeGateAction(AtlasSelfConstructionScopeRiskBudgetGate $gate): int
    {
        $facts = $this->loadFacts();
        if ($facts === null) {
            return self::EXIT_USAGE;
        }

        $payload = [
            'requested_scope' => is_array($facts['requested_scope'] ?? null) ? $facts['requested_scope'] : [],
            'risk_class' => (string) ($facts['risk_class'] ?? 'low'),
            'task_budget' => (int) ($facts['task_budget'] ?? 0),
            'cost_budget_units' => (int) ($facts['cost_budget_units'] ?? 0),
            'project_lane' => is_array($facts['project_lane'] ?? null) ? $facts['project_lane'] : [],
            'forbidden_organs' => array_values((array) ($facts['forbidden_organs'] ?? [])),
            'touched_organs' => array_values((array) ($facts['touched_organs'] ?? [])),
            'rollback_ready' => (bool) ($facts['rollback_ready'] ?? false),
        ];

        $verdict = $gate->evaluate($payload);
        $this->emit(['scope_risk_budget_gate' => $verdict]);

        return self::EXIT_OK;
    }

    private function nextAction(AtlasSelfConstructionOrganReadinessComposer $organReadiness, AtlasSelfConstructionNextActionSelector $selector): int
    {
        $facts = $this->loadFacts();
        if ($facts === null) {
            return self::EXIT_USAGE;
        }
        $readiness = $organReadiness->compose((array) ($facts['organ_facts'] ?? []));
        $verdict = $selector->select(
            $readiness,
            (array) ($facts['scope_gate'] ?? []),
            (array) ($facts['autonomy_mode'] ?? []),
            (array) ($facts['work_queue'] ?? []),
        );
        $this->emit($verdict);

        return self::EXIT_OK;
    }

}
