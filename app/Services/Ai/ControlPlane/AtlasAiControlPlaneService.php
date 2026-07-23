<?php

declare(strict_types=1);

namespace App\Services\Ai\ControlPlane;

use App\Services\Ai\Compounding\AtlasLearningSignalScanner;
use App\Services\Ai\ControlPlane\ControlPlane\BlockerSection;
use App\Services\Ai\ControlPlane\ControlPlane\ControlPlaneSupport;
use App\Services\Ai\ControlPlane\ControlPlane\SubsystemSection;
use App\Services\Ai\ControlPlane\ControlPlane\TraceSignalsSection;
use App\Services\Ai\ControlPlane\ControlPlane\WorkspaceContextSection;
use Carbon\CarbonImmutable;

/**
 * Atlas AI Observability & Control Plane (trace-level).
 *
 * Aggregates the per-trace signals that Atlas AI emits — router decisions,
 * receipts, evidence refs, quality evaluations, remediation actions, Dev/Forge
 * handoffs, provider distribution, rich_input usage — into a single
 * deterministic read model.
 *
 * Distinct from {@see AtlasControlPlaneSnapshotService}, which aggregates the
 * mission-level Atlas brain (domains/policies/tools/approvals). This service
 * is the read model for Atlas AI runtime auditing: "what flows ran in the
 * last N hours, did any silently drop a receipt or leak raw evidence?".
 *
 * Hard contract:
 *  - read-only and side-effect-free;
 *  - never executes a provider, never runs benchmark/rivals;
 *  - never declares external superiority;
 *  - tolerant of missing tables — degraded sections surface
 *    `status: missing` instead of throwing;
 *  - never returns raw response_text / operator_input; only hashes, ids, refs;
 *  - hash is deterministic over canonical content (excluding generated_at).
 */
class AtlasAiControlPlaneService
{
    public const SCHEMA_VERSION = 'atlas.ai.control_plane.v1';

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_WATCH = 'watch';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_WINDOW_HOURS = 24;

    /** Max items per list section to keep the report bounded. */
    public const RECENT_LIMIT = 20;

    public const BLOCKER_LIMIT = 50;

    /**
     * Runtime projections required for a workspace to be considered fully
     * operational by the control plane.
     *
     * @var array<int,string>
     */
    public const REQUIRED_WORKSPACE_INTELLIGENCE_FAMILIES = ['AWCO', 'AWEF', 'AWIL', 'AWNSB', 'AWTR'];

    public function __construct(
        private readonly AtlasLearningSignalScanner $learningScanner,
        private readonly ControlPlaneSupport $support,
        private readonly TraceSignalsSection $traceSignals,
        private readonly WorkspaceContextSection $workspaceContext,
        private readonly BlockerSection $blocker,
        private readonly SubsystemSection $subsystems,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(int $hours = self::DEFAULT_WINDOW_HOURS): array
    {
        $hours = max(1, $hours);
        $generatedAt = CarbonImmutable::now();
        $since = $generatedAt->subHours($hours);

        $tracesSection = $this->traceSignals->tracesSection($since);
        $flowsSection = $this->traceSignals->flowsSection($since, $tracesSection['ids']);
        $recentTraces = $this->traceSignals->recentTraces($since);
        $failures = $this->traceSignals->failures($since);
        $handoffs = $this->traceSignals->handoffs($since, $tracesSection['ids']);
        $receipts = $this->traceSignals->receipts($since);
        $evidence = $this->traceSignals->evidence($since, $tracesSection['ids']);
        $quality = $this->traceSignals->quality($since, $tracesSection['ids']);
        $providerDecisions = $this->traceSignals->providerDecisions($since);
        $contextOperations = $this->traceSignals->contextOperations($tracesSection['ids']);
        $persistentContext = $this->workspaceContext->persistentContext($since);
        $workspaceIntelligence = $this->workspaceContext->workspaceIntelligence($since);
        $aemor = $this->subsystems->aemor($since);
        $intelligenceFactory = $this->subsystems->intelligenceFactory($since);
        $strategicReality = $this->subsystems->strategicReality($since);
        $runtimeEfficiency = $this->subsystems->runtimeEfficiency($since);
        $agenticWorkcell = $this->subsystems->agenticWorkcell($since);
        $autonomousWorkExecution = $this->subsystems->autonomousWorkExecution($since);
        $verifiedExecution = $this->subsystems->verifiedExecution($since);
        $autonomousEvolution = $this->subsystems->autonomousEvolution($since);
        $autonomousRealitySandbox = $this->subsystems->autonomousRealitySandbox($since);
        $swarmCompany = $this->subsystems->swarmCompany($since);
        $externalExecution = $this->subsystems->externalExecution($since);
        $blockers = $this->blocker->blockers($since, $tracesSection['ids']);
        foreach ((array) ($externalExecution['blockers'] ?? []) as $blocker) {
            if (count($blockers) >= self::BLOCKER_LIMIT) {
                break;
            }
            if (is_array($blocker)) {
                $blockers[] = $blocker;
            }
        }
        foreach ((array) ($persistentContext['blockers'] ?? []) as $blocker) {
            if (count($blockers) >= self::BLOCKER_LIMIT) {
                break;
            }
            if (is_array($blocker)) {
                $blockers[] = $blocker;
            }
        }
        foreach ((array) ($workspaceIntelligence['blockers'] ?? []) as $blocker) {
            if (count($blockers) >= self::BLOCKER_LIMIT) {
                break;
            }
            if (is_array($blocker)) {
                $blockers[] = $blocker;
            }
        }
        $blockerClassification = $this->blocker->classifyBlockers($blockers);
        $learning = $this->learningScanner->controlPlaneSummary($since);
        $approvals = $this->subsystems->approvalsSection($since);

        $summary = [
            'total_traces' => $tracesSection['total'],
            'succeeded' => $tracesSection['by_status']['succeeded'] ?? 0,
            'failed' => $tracesSection['by_status']['failed'] ?? 0,
            'processing' => $tracesSection['by_status']['processing'] ?? 0,
            'queued' => $tracesSection['by_status']['queued'] ?? 0,
            'cancelled' => $tracesSection['by_status']['cancelled'] ?? 0,
            'unique_flows' => count($flowsSection),
            'unique_providers' => count($tracesSection['by_provider']),
            'blockers_count' => count($blockers),
            'system_blockers_count' => $blockerClassification['system_blockers_count'],
            'operator_queue_count' => $blockerClassification['operator_queue_count'],
            'clarification_queue_count' => $blockerClassification['clarification_queue_count'],
            'handoffs_count' => $handoffs['dev']['count'] + $handoffs['forge']['count'],
            'failures_count' => count($failures),
            'context_operations_blockers_count' => $contextOperations['blockers_count'],
            'verified_compactions_count' => $contextOperations['verified_compaction']['total'],
            'persistent_context_total' => $persistentContext['total'],
            'persistent_context_blocked' => $persistentContext['blocked'],
            'workspace_intelligence_snapshots_total' => $workspaceIntelligence['summary']['total'] ?? 0,
            'workspace_intelligence_blocked' => ($workspaceIntelligence['summary']['blocked'] ?? 0) + ($workspaceIntelligence['summary']['stale'] ?? 0) + ($workspaceIntelligence['summary']['missing_required_families'] ?? 0) + ($workspaceIntelligence['summary']['artifact_graph_blocked'] ?? 0) + ($workspaceIntelligence['summary']['artifact_graph_stale'] ?? 0),
            'workspace_intelligence_workspaces_total' => $workspaceIntelligence['summary']['workspaces_total'] ?? 0,
            'aemor_episodes_total' => $aemor['summary']['episodes_total'] ?? 0,
            'aemor_blocked_outcomes' => $aemor['summary']['blocked'] ?? 0,
            'aemor_failed_outcomes' => $aemor['summary']['failed'] ?? 0,
            'intelligence_factory_capabilities_total' => $intelligenceFactory['summary']['capabilities_total'] ?? 0,
            'intelligence_factory_open_gaps' => $intelligenceFactory['summary']['open_gaps'] ?? 0,
            'intelligence_factory_blocked_decisions' => $intelligenceFactory['summary']['blocked_decisions'] ?? 0,
            'intelligence_factory_evolution_events' => $intelligenceFactory['summary']['evolution_events_total'] ?? 0,
            'intelligence_factory_capability_used_events' => $intelligenceFactory['summary']['capability_used_events'] ?? 0,
            'strategic_reality_entities_total' => $strategicReality['summary']['reality_entities_total'] ?? 0,
            'strategic_reality_decisions_total' => $strategicReality['summary']['strategic_decisions_total'] ?? 0,
            'strategic_reality_blocked_decisions' => $strategicReality['summary']['blocked_decisions'] ?? 0,
            'strategic_reality_critical_risks' => $strategicReality['summary']['critical_risks'] ?? 0,
            'runtime_efficiency_decisions_total' => $runtimeEfficiency['summary']['decisions_total'] ?? 0,
            'runtime_efficiency_fast_path' => $runtimeEfficiency['summary']['fast_path'] ?? 0,
            'runtime_efficiency_deep_path' => $runtimeEfficiency['summary']['deep_path'] ?? 0,
            'runtime_efficiency_forge_path' => $runtimeEfficiency['summary']['forge_path'] ?? 0,
            'runtime_efficiency_blocked' => $runtimeEfficiency['summary']['blocked'] ?? 0,
            'agentic_workcells_total' => $agenticWorkcell['summary']['workcells_total'] ?? 0,
            'agentic_workcell_blocked' => $agenticWorkcell['summary']['blocked'] ?? 0,
            'agentic_workcell_org_patterns' => $agenticWorkcell['summary']['org_patterns_total'] ?? 0,
            'aweos_executions_total' => $autonomousWorkExecution['summary']['executions_total'] ?? 0,
            'aweos_blocked' => $autonomousWorkExecution['summary']['blocked'] ?? 0,
            'aweos_certified' => $autonomousWorkExecution['summary']['certified'] ?? 0,
            'aweos_gold_outcomes' => $autonomousWorkExecution['summary']['gold_outcomes'] ?? 0,
            'verified_execution_total' => $verifiedExecution['summary']['executions_total'] ?? 0,
            'verified_execution_blocked' => $verifiedExecution['summary']['blocked'] ?? 0,
            'verified_execution_certified' => $verifiedExecution['summary']['certified'] ?? 0,
            'verified_execution_gold_certifications' => $verifiedExecution['summary']['gold_certifications'] ?? 0,
            'aael_opportunities_total' => $autonomousEvolution['summary']['opportunities_total'] ?? 0,
            'aael_cycles_total' => $autonomousEvolution['summary']['cycles_total'] ?? 0,
            'aael_experiments_total' => $autonomousEvolution['summary']['experiments_total'] ?? 0,
            'aael_operator_review_required' => $autonomousEvolution['summary']['operator_review_required'] ?? 0,
            'aael_blocked' => $autonomousEvolution['summary']['blocked'] ?? 0,
            'aars_scenarios_total' => $autonomousRealitySandbox['summary']['scenarios_total'] ?? 0,
            'aars_simulations_total' => $autonomousRealitySandbox['summary']['simulations_total'] ?? 0,
            'aars_high_risk' => $autonomousRealitySandbox['summary']['high_risk'] ?? 0,
            'aars_blocked' => $autonomousRealitySandbox['summary']['blocked'] ?? 0,
            'swarm_company_roles_total' => $swarmCompany['summary']['role_runs_total'] ?? 0,
            'swarm_company_blocked_releases' => $swarmCompany['summary']['blocked_release_packs'] ?? 0,
            'external_execution_mandates_total' => $externalExecution['summary']['mandates_total'] ?? 0,
            'external_execution_pending_approval' => $externalExecution['summary']['pending_operator_review'] ?? 0,
            'external_execution_unsafe_enabled' => $externalExecution['summary']['unsafe_external_execution_enabled'] ?? 0,
            'external_execution_missing_receipt_bindings' => $externalExecution['summary']['missing_receipt_binding_count'] ?? 0,
        ];

        $status = $this->resolveStatus($summary, $blockers);

        $readinessRefs = $this->readinessRefs();

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $generatedAt->toJSON(),
            'window' => [
                'hours' => $hours,
                'since' => $since->toJSON(),
                'until' => $generatedAt->toJSON(),
            ],
            'status' => $status,
            'summary' => $summary,
            'flows' => $flowsSection,
            'recent_traces' => $recentTraces,
            'failures' => $failures,
            'handoffs' => $handoffs,
            'receipts' => $receipts,
            'evidence' => $evidence,
            'quality' => $quality,
            'provider_decisions' => $providerDecisions,
            'context_operations' => $contextOperations,
            'persistent_context' => $persistentContext,
            'workspace_intelligence' => $workspaceIntelligence,
            'aemor' => $aemor,
            'intelligence_factory' => $intelligenceFactory,
            'strategic_reality' => $strategicReality,
            'runtime_efficiency' => $runtimeEfficiency,
            'agentic_workcell' => $agenticWorkcell,
            'autonomous_work_execution' => $autonomousWorkExecution,
            'verified_execution' => $verifiedExecution,
            'autonomous_evolution' => $autonomousEvolution,
            'autonomous_reality_sandbox' => $autonomousRealitySandbox,
            'swarm_company' => $swarmCompany,
            'external_execution' => $externalExecution,
            'blockers' => $blockers,
            'learning' => $learning,
            'approvals' => $approvals,
            'readiness_refs' => $readinessRefs,
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'allows_external_superiority_claim' => false,
                'declares_atlas_complete' => false,
            ],
        ];

        $payload['hash'] = $this->support->hashPayload($payload);

        return $payload;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function readinessRefs(): array
    {
        return [
            [
                'name' => 'router_runtime',
                'command' => 'php artisan atlas:ai:router-runtime readiness --json',
                'endpoint' => '/ai/router-runtime/readiness',
                'schema' => 'atlas.ai.router_runtime_readiness.v1',
            ],
            [
                'name' => 'hyperflow_specialist_flows',
                'command' => 'php artisan atlas:ai:hyperflow-specialists readiness --json',
                'schema' => 'atlas.ai.hyperflow_specialist_flows_readiness.v1',
            ],
            [
                'name' => 'hyperflow_certification',
                'service' => 'AtlasAiHyperflowCertificationService',
                'schema' => 'atlas.ai.hyperflow_certification.v1',
            ],
            [
                'name' => 'context_intelligence',
                'command' => 'php artisan atlas:context-intelligence:certify --json --strict',
                'schema' => 'atlas.context_intelligence.certification.v1',
            ],
            [
                'name' => 'conversation_ops',
                'command' => 'php artisan atlas:conversation-ops:certify --json --strict',
                'schema' => 'atlas.conversation_ops.certification.v1',
            ],
            [
                'name' => 'persistent_context_runtime',
                'command' => 'php artisan atlas:persistent-context:certify --json --strict',
                'schema' => 'atlas.persistent_context.certification.v1',
            ],
            [
                'name' => 'aemor_runtime',
                'command' => 'php artisan atlas:aemor:certify --json --strict',
                'schema' => 'atlas.aemor.certification.v1',
            ],
            // GOD-DEBULK 3b: intelligence_factory readiness ref removed — atlas:intelligence-factory:*
            // commands quarantined to archive/ (blueprint 91c334a27 §2.2). Tables/models stay monitored
            // by the intelligence_factory section above.
            [
                'name' => 'strategic_reality_engine',
                'command' => 'php artisan atlas:strategic-reality:certify --json --strict',
                'schema' => 'atlas.strategic_reality.certification.v1',
            ],
            [
                'name' => 'runtime_efficiency_governor',
                'command' => 'php artisan atlas:runtime-efficiency:certify --json --strict',
                'schema' => 'atlas.runtime_efficiency_governor.certification.v1',
            ],
            [
                'name' => 'agentic_workcell_runtime',
                'command' => 'php artisan atlas:agentic-workcell:certify --json --strict',
                'schema' => 'atlas.agentic_workcell.certification.v1',
            ],
            [
                'name' => 'autonomous_work_execution_os',
                'command' => 'php artisan atlas:aweos:certify --json --strict',
                'schema' => 'atlas.aweos.certification.v1',
            ],
            [
                'name' => 'verified_execution_runtime',
                'command' => 'php artisan atlas:aver:certify --json --strict',
                'schema' => 'atlas.aver.certification.v1',
            ],
            [
                'name' => 'autonomous_evolution_loop',
                'command' => 'php artisan atlas:aael:certify --json --strict',
                'schema' => 'atlas.aael.certification.v1',
            ],
            [
                'name' => 'autonomous_reality_sandbox',
                'command' => 'php artisan atlas:aars:certify --json --strict',
                'schema' => 'atlas.aars.certification.v1',
            ],
            [
                'name' => 'swarm_company_runtime',
                'service' => 'AtlasRealEngineeringCompanyRuntimeService + AgentControlPlane runtime',
                'schema' => 'atlas.ai.engineering_company.control_plane.v1',
            ],
            [
                'name' => 'governed_external_execution',
                'service' => 'ExternalActionMandateRegistryService',
                'schema' => 'atlas.ai.holding.enterprise_external_action_mandate_registry.v1',
            ],
        ];
    }

    /**
     * @param  array<string,int>  $summary
     * @param  array<int,array<string,mixed>>  $blockers
     */
    private function resolveStatus(array $summary, array $blockers): string
    {
        if (($summary['system_blockers_count'] ?? 0) > 0 || ($summary['failed'] ?? 0) > 0) {
            return self::STATUS_BLOCKED;
        }
        if (
            ($summary['operator_queue_count'] ?? 0) > 0
            || ($summary['external_execution_pending_approval'] ?? 0) > 0
            || ($summary['processing'] ?? 0) > 0
            || ($summary['queued'] ?? 0) > 0
        ) {
            return self::STATUS_WATCH;
        }

        return self::STATUS_HEALTHY;
    }

}
