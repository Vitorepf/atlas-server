<?php

namespace App\Services\Ai\Kernel\Architecture;

use App\Services\Ai\AtlasMemoryQualityService;
use App\Services\Ai\Capture\CaptureInboxPipelineReadModel;
use App\Services\Ai\Mobile\ProactiveLayerReadModel;
use App\Services\Ai\Runtime\ToolActionRuntimeReadModel;
use App\Services\Ai\Scheduling\LongRunningWorkReadModel;
use App\Services\Ai\Tasks\TaskOrchestrationReadModel;
use App\Services\Ai\Telemetry\AiProviderCostRateService;
use App\Services\Engineering\EngineeringDocumentationHealthService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

class AtlasStructureMotherAuditReadModel
{
    public const SCHEMA_VERSION = 'atlas.structure_mother_audit.v1';

    public function __construct(
        private readonly AtlasMemoryQualityService $memoryQuality,
        private readonly EngineeringDocumentationHealthService $documentation,
        private readonly CaptureInboxPipelineReadModel $captureInbox,
        private readonly TaskOrchestrationReadModel $tasks,
        private readonly ToolActionRuntimeReadModel $tools,
        private readonly LongRunningWorkReadModel $longRunningWork,
        private readonly AtlasRivalsStrategyReadModel $rivals,
        private readonly ProactiveLayerReadModel $proactive,
        private readonly AtlasQualitativeLevelsReadModel $qualitativeLevels,
        private readonly AiProviderCostRateService $costRates,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function report(array $options = []): array
    {
        $hours = max(1, min(87600, (int) ($options['hours'] ?? 720)));
        $workspace = $this->workspace($options['workspace'] ?? null);
        $since = now()->subHours($hours);
        $until = now();

        $memory = $this->memoryQuality->scorecard(['workspace' => $workspace]);
        $docs = $this->documentation->report();
        $openBrain = $this->openBrainReadiness($docs);
        $capture = $this->captureInbox->report($hours);
        $tasks = $this->tasks->report($since, $until);
        $tools = $this->tools->report($since, $until, $workspace);
        $longRunning = $this->longRunningWork->report($since, $until);
        $rivals = $this->rivals->report($since, $until->copy()->addYear());
        $proactive = $this->proactive->report($since, $until);
        $levels = $this->qualitativeLevels->report($since, $until);
        $missingCostRates = $this->costRates->missingRates($since, $until, 10);

        $modules = [
            $this->module(
                id: 'memory_context_engine',
                label: 'Memory/Context Engine',
                status: $this->memoryStatus($memory),
                evidence: [
                    'memory_quality_status' => $memory['status'] ?? 'unknown',
                    'score' => $memory['score'] ?? null,
                    'active_count' => data_get($memory, 'counts.active'),
                    'provider_safe_active_count' => data_get($memory, 'counts.provider_safe_active'),
                    'latest_snapshot_status' => data_get($memory, 'latest_snapshot.status'),
                ],
                command: 'php artisan atlas:memory:quality scorecard --workspace='.$workspace.' --json',
                blockers: $this->memoryBlockers($memory),
            ),
            $this->module(
                id: 'knowledge_base_open_brain',
                label: 'Knowledge Base/Open Brain',
                status: $openBrain['status'],
                evidence: $openBrain,
                command: 'php artisan atlas:engineering:knowledge docs-health --json',
                blockers: $openBrain['blockers'],
            ),
            $this->module(
                id: 'inbox_capture_pipeline',
                label: 'Inbox/Capture Pipeline',
                status: $this->simpleStatus($capture),
                evidence: $this->compact($capture, [
                    'capture_count',
                    'quarantined_capture_count',
                    'missing_quarantine_count',
                    'content_intelligence_count',
                    'missing_content_intelligence_count',
                    'unsafe_capture_count',
                    'proposal_backlink_gap_count',
                ]),
                command: 'php artisan atlas:ai:capture-inbox-pipeline-report --hours='.$hours.' --json',
                blockers: $this->reportReasons($capture),
            ),
            $this->module(
                id: 'task_agent_orchestration',
                label: 'Task/Agent Orchestration',
                status: $this->simpleStatus($tasks),
                evidence: $this->compact($tasks, [
                    'task_count',
                    'event_count',
                    'receipt_event_count',
                    'missing_receipt_event_count',
                    'incomplete_event_receipt_count',
                    'unsafe_event_receipt_count',
                    'external_review_required_task_count',
                ]),
                command: 'php artisan atlas:ai:task-orchestration-report --hours='.$hours.' --json',
                blockers: $this->reportReasons($tasks),
            ),
            $this->module(
                id: 'tool_action_runtime',
                label: 'Tool/Action Runtime',
                status: $this->simpleStatus($tools),
                evidence: $this->compact($tools, [
                    'definition_count',
                    'ready_installation_count',
                    'evidence_run_count',
                    'failed_required_run_count',
                    'blocking_open_finding_count',
                    'latest_missing_action_runtime_contract_count',
                ]),
                command: 'php artisan atlas:ai:tool-action-runtime-report --hours='.$hours.' --workspace='.$workspace.' --json',
                blockers: $this->reportReasons($tools),
            ),
            $this->module(
                id: 'autonomy_long_running_work',
                label: 'Autonomy/Long-Running Work',
                status: $this->simpleStatus($longRunning),
                evidence: $this->compact($longRunning, [
                    'scheduled_task_count',
                    'due_task_count',
                    'overdue_task_count',
                    'recent_run_count',
                    'unsafe_autonomy_receipt_count',
                ]),
                command: 'php artisan atlas:ai:long-running-work-report --hours='.$hours.' --json',
                blockers: $this->reportReasons($longRunning),
            ),
            $this->module(
                id: 'evaluation_rivals_framework',
                label: 'Evaluation/Rivals Framework',
                status: $this->rivalsImplementationStatus($rivals),
                evidence: [
                    'case_count' => $rivals['case_count'] ?? 0,
                    'scheduled_review_count' => $rivals['scheduled_review_count'] ?? 0,
                    'scored_review_count' => $rivals['scored_review_count'] ?? 0,
                    'average_agency_score' => $rivals['average_agency_score'] ?? null,
                    'p4_promotion_readiness' => $rivals['p4_promotion_readiness'] ?? null,
                ],
                command: 'php artisan atlas:ai:rivals-strategy report --hours='.$hours.' --json',
                blockers: [],
                operationalBlockers: $this->rivalsBlockers($rivals),
            ),
            $this->module(
                id: 'notification_proactive_layer',
                label: 'Notification/Proactive Layer',
                status: $this->proactiveImplementationStatus($proactive),
                evidence: [
                    ...$this->compact($proactive, [
                        'run_count',
                        'failed_run_count',
                        'insight_item_count',
                        'active_insight_item_count',
                        'critical_insight_item_count',
                        'active_critical_insight_item_count',
                        'push_delivery_count',
                    ]),
                    'critical_review_contract' => $proactive['critical_review_contract'] ?? null,
                ],
                command: 'php artisan atlas:ai:proactive-layer-report --hours='.$hours.' --json',
                blockers: [],
                operationalBlockers: $this->proactiveBlockers($proactive),
            ),
        ];

        $blockers = collect($modules)
            ->flatMap(fn (array $module): array => collect((array) ($module['operational_blockers'] ?? $module['blockers'] ?? []))
                ->map(fn (string $blocker): array => [
                    'module_id' => $module['id'],
                    'module' => $module['label'],
                    'blocker' => $blocker,
                ])
                ->all())
            ->values()
            ->all();
        $readyCount = collect($modules)->where('implementation_status', 'ready')->count();
        $blockedCount = collect($modules)->where('implementation_status', 'blocked')->count();
        $attentionCount = collect($modules)->where('implementation_status', 'attention')->count();
        $implementationComplete = $blockedCount === 0 && $attentionCount === 0;
        $complete = $implementationComplete && $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $complete ? 'complete' : ($implementationComplete ? 'operational_blocked' : ($blockedCount > 0 ? 'blocked' : 'attention')),
            'complete' => $complete,
            'implementation_complete' => $implementationComplete,
            'hours' => $hours,
            'workspace' => $workspace,
            'window' => ['since' => $since->toJSON(), 'until' => $until->toJSON()],
            'summary' => [
                'module_count' => count($modules),
                'ready_count' => $readyCount,
                'attention_count' => $attentionCount,
                'blocked_count' => $blockedCount,
                'blocker_count' => count($blockers),
                'operational_blocker_count' => count($blockers),
                'qualitative_level' => $levels['current_level'] ?? null,
                'next_qualitative_level' => $levels['next_level'] ?? null,
                'next_level_blockers' => $levels['next_level_blockers'] ?? [],
            ],
            'completion_checklist' => $this->completionChecklist($modules, $complete),
            'prompt_to_artifact_checklist' => $this->promptToArtifactChecklist($modules, $complete, $hours, $workspace),
            'operator_action_plan' => $this->operatorActionPlan($modules, $complete, $missingCostRates),
            'modules' => $modules,
            'blockers' => $blockers,
            'rules' => [
                'read_model_only' => true,
                'no_provider_call' => true,
                'no_runtime_execution' => true,
                'no_memory_promotion' => true,
                'no_voice_livekit_work' => true,
                'self_construction_control_plane_excluded' => true,
            ],
            'implementation_gate' => [
                'status' => $implementationComplete ? 'passed' : 'blocked',
                'reason' => $implementationComplete
                    ? 'all_structure_mother_modules_have_governed_implementation_surfaces'
                    : 'one_or_more_structure_mother_modules_missing_implementation_readiness',
            ],
            'completion_gate' => [
                'status' => $complete ? 'passed' : 'blocked',
                'reason' => $complete
                    ? 'all_structure_mother_modules_ready'
                    : ($implementationComplete
                        ? 'structure_mother_has_operational_human_or_calendar_blockers'
                        : 'structure_mother_has_real_blockers_or_attention_items'),
                'update_goal_allowed' => $complete,
            ],
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function openBrainReadiness(array $docs): array
    {
        $tables = [
            'atlas_engineering_knowledge_items' => Schema::hasTable('atlas_engineering_knowledge_items'),
            'atlas_engineering_code_modules' => Schema::hasTable('atlas_engineering_code_modules'),
            'atlas_open_brain_access_logs' => Schema::hasTable('atlas_open_brain_access_logs'),
        ];
        $blockers = [];
        if (($docs['status'] ?? null) !== 'ok') {
            $blockers[] = 'engineering_knowledge_docs_health_not_ok';
        }
        foreach ($tables as $table => $exists) {
            if (! $exists) {
                $blockers[] = $table.'_missing';
            }
        }

        return [
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'docs_status' => $docs['status'] ?? 'unknown',
            'doc_count' => data_get($docs, 'summary.doc_count'),
            'required_missing_count' => data_get($docs, 'summary.required_missing_count'),
            'frontmatter_violation_count' => data_get($docs, 'summary.frontmatter_violation_count'),
            'oversized_count' => data_get($docs, 'summary.oversized_count'),
            'tables' => $tables,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  array<int,string>  $keys
     * @return array<string,mixed>
     */
    private function compact(array $report, array $keys): array
    {
        $payload = ['status' => $report['status'] ?? 'unknown'];
        foreach ($keys as $key) {
            $payload[$key] = $report[$key] ?? null;
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function module(string $id, string $label, string $status, array $evidence, string $command, array $blockers = [], array $operationalBlockers = []): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'implementation_status' => $status,
            'operational_status' => $operationalBlockers === [] ? 'clear' : 'blocked',
            'evidence' => $evidence,
            'canonical_command' => $command,
            'blockers' => array_values($blockers),
            'operational_blockers' => array_values($operationalBlockers),
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function simpleStatus(array $report): string
    {
        return match ((string) ($report['status'] ?? 'unknown')) {
            'ok', 'ready' => 'ready',
            'storage_unavailable' => 'blocked',
            default => 'attention',
        };
    }

    /**
     * @param  array<string,mixed>  $memory
     */
    private function memoryStatus(array $memory): string
    {
        $status = (string) ($memory['status'] ?? 'unknown');
        $score = (int) ($memory['score'] ?? 0);
        if (in_array($status, ['not_migrated', 'empty', 'critical'], true)) {
            return 'blocked';
        }

        return $score >= 85 ? 'ready' : 'attention';
    }

    /**
     * @param  array<string,mixed>  $memory
     * @return array<int,string>
     */
    private function memoryBlockers(array $memory): array
    {
        if ($this->memoryStatus($memory) === 'ready') {
            return [];
        }

        return collect((array) ($memory['issues'] ?? []))
            ->map(fn (mixed $issue): string => is_array($issue) ? (string) ($issue['code'] ?? 'memory_quality_issue') : 'memory_quality_issue')
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<int,string>
     */
    private function reportReasons(array $report): array
    {
        return array_values(array_filter(array_map(
            'strval',
            (array) data_get($report, 'review_signal.reasons', []),
        )));
    }

    /**
     * @param  array<string,mixed>  $rivals
     */
    private function rivalsImplementationStatus(array $rivals): string
    {
        if (! (bool) ($rivals['available'] ?? false)) {
            return 'blocked';
        }
        if ((int) ($rivals['case_count'] ?? 0) > 0
            && (int) ($rivals['scheduled_review_count'] ?? 0) > 0
            && is_array($rivals['p4_promotion_readiness'] ?? null)) {
            return 'ready';
        }

        return 'attention';
    }

    /**
     * @param  array<string,mixed>  $rivals
     * @return array<int,string>
     */
    private function rivalsBlockers(array $rivals): array
    {
        if ((string) data_get($rivals, 'p4_promotion_readiness.status') === 'ready') {
            return [];
        }

        return [(string) (data_get($rivals, 'p4_promotion_readiness.reason') ?: 'rivals_strategy_not_ready')];
    }

    /**
     * @param  array<string,mixed>  $proactive
     */
    private function proactiveImplementationStatus(array $proactive): string
    {
        if ((string) ($proactive['status'] ?? 'unknown') === 'storage_unavailable') {
            return 'blocked';
        }
        if (is_array($proactive['critical_review_contract'] ?? null)
            && data_get($proactive, 'critical_review_contract.agent_resolution_allowed') === false
            && data_get($proactive, 'critical_review_contract.auto_dismiss_allowed') === false) {
            return 'ready';
        }

        return $this->simpleStatus($proactive);
    }

    /**
     * @param  array<string,mixed>  $proactive
     * @return array<int,string>
     */
    private function proactiveBlockers(array $proactive): array
    {
        $blockers = $this->reportReasons($proactive);
        if ((int) data_get($proactive, 'critical_review_contract.critical_active_count', 0) > 0) {
            $blockers[] = 'critical_proactive_insights_require_operator_review';
        }

        return array_values(array_unique($blockers));
    }

    private function workspace(mixed $workspace): string
    {
        $workspace = is_scalar($workspace) && trim((string) $workspace) !== ''
            ? trim((string) $workspace)
            : base_path();

        return realpath($workspace) ?: $workspace;
    }

    /**
     * @param  array<int,array<string,mixed>>  $modules
     * @return array<string,mixed>
     */
    private function operatorActionPlan(array $modules, bool $complete, array $missingCostRates): array
    {
        $byId = collect($modules)->keyBy('id');
        $rivals = (array) data_get($byId->get('evaluation_rivals_framework'), 'evidence.p4_promotion_readiness', []);
        $critical = (array) data_get($byId->get('notification_proactive_layer'), 'evidence.critical_review_contract', []);
        $actions = [];

        if ((string) ($rivals['status'] ?? 'unknown') !== 'ready') {
            $actions[] = [
                'id' => 'record_real_rivals_review_when_due',
                'module_id' => 'evaluation_rivals_framework',
                'type' => 'calendar_human_review',
                'status' => 'pending',
                'due_at' => data_get($rivals, 'next_review.review_due_at'),
                'command' => data_get($rivals, 'next_review.record_command'),
                'operator_required' => true,
                'synthetic_completion_allowed' => false,
                'notes' => [
                    'Wait until the review horizon is real.',
                    'Record regret/alignment/agency only from operator review evidence.',
                ],
            ];
        }

        if ((int) ($critical['critical_active_count'] ?? 0) > 0) {
            $actions[] = [
                'id' => 'review_critical_proactive_insights',
                'module_id' => 'notification_proactive_layer',
                'type' => 'human_inbox_review',
                'status' => 'pending',
                'operator_required' => true,
                'agent_auto_resolve_allowed' => false,
                'agent_auto_dismiss_allowed' => false,
                'critical_active_count' => (int) ($critical['critical_active_count'] ?? 0),
                'review_paths' => collect((array) ($critical['items'] ?? []))
                    ->map(fn (array $item): ?string => is_string($item['review_path'] ?? null) ? $item['review_path'] : null)
                    ->filter()
                    ->values()
                    ->all(),
                'item_commands' => (array) data_get($critical, 'operator_review_plan.item_commands', []),
                'allowed_actions' => (array) ($critical['allowed_actions'] ?? []),
            ];
        }

        $importableMissingRates = collect($missingCostRates)
            ->filter(fn (array $rate): bool => (bool) ($rate['can_import_rate'] ?? false))
            ->values()
            ->all();
        if ($importableMissingRates !== []) {
            $actions[] = [
                'id' => 'configure_missing_provider_cost_rates',
                'module_id' => 'notification_proactive_layer',
                'type' => 'human_cost_rate_review',
                'status' => 'pending',
                'operator_required' => true,
                'agent_may_not_infer_prices' => true,
                'current_provider_pricing_required' => true,
                'list_missing_command' => 'php artisan atlas:ai:telemetry:cost-rates --missing --hours=240 --json',
                'missing_rate_count' => count($importableMissingRates),
                'missing_rates' => collect($importableMissingRates)
                    ->map(fn (array $rate): array => [
                        'provider' => $rate['provider'] ?? null,
                        'model' => $rate['model'] ?? null,
                        'reason' => $rate['reason'] ?? null,
                        'traces' => $rate['traces'] ?? null,
                        'configure_command' => "php artisan atlas:ai:telemetry:cost-rates --provider='".str_replace("'", "'\"'\"'", (string) ($rate['provider'] ?? ''))."' --model='".str_replace("'", "'\"'\"'", (string) ($rate['model'] ?? ''))."' --input-microusd=<current_input_microusd_per_1k> --output-microusd=<current_output_microusd_per_1k> --json",
                    ])
                    ->values()
                    ->all(),
                'notes' => [
                    'Use current provider pricing evidence.',
                    'Do not synthesize, guess or backfill prices without operator review.',
                ],
            ];
        }

        return [
            'schema_version' => 'atlas.structure_mother.operator_action_plan.v1',
            'status' => $complete ? 'clear' : ($actions === [] ? 'blocked_without_action_plan' : 'pending_operator_or_calendar_action'),
            'action_count' => count($actions),
            'actions' => $actions,
            'rules' => [
                'agent_may_not_fabricate_rivals_scores' => true,
                'agent_may_not_auto_resolve_critical_insights' => true,
                'agent_may_not_infer_provider_prices' => true,
                'operator_or_calendar_required_for_completion' => ! $complete,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $modules
     * @return array<int,array<string,mixed>>
     */
    private function completionChecklist(array $modules, bool $complete): array
    {
        $byId = collect($modules)->keyBy('id');

        return [
            $this->checklistItem(
                requirement: 'Memory/Context Engine implemented and governed',
                artifact: 'atlas:memory:quality scorecard + Memory/Open Brain contracts',
                evidenceCommand: (string) data_get($byId->get('memory_context_engine'), 'canonical_command'),
                status: (string) data_get($byId->get('memory_context_engine'), 'status', 'unknown'),
                blockers: (array) data_get($byId->get('memory_context_engine'), 'blockers', []),
            ),
            $this->checklistItem(
                requirement: 'Knowledge Base/Open Brain indexed, provider-safe and auditable',
                artifact: 'Engineering KB docs/index-code + Open Brain access-log safety tables',
                evidenceCommand: (string) data_get($byId->get('knowledge_base_open_brain'), 'canonical_command'),
                status: (string) data_get($byId->get('knowledge_base_open_brain'), 'status', 'unknown'),
                blockers: (array) data_get($byId->get('knowledge_base_open_brain'), 'blockers', []),
            ),
            $this->checklistItem(
                requirement: 'Inbox/Capture Pipeline keeps raw captures quarantined before promotion',
                artifact: 'Capture/Inbox pipeline report and conservative backfill command',
                evidenceCommand: (string) data_get($byId->get('inbox_capture_pipeline'), 'canonical_command'),
                status: (string) data_get($byId->get('inbox_capture_pipeline'), 'status', 'unknown'),
                blockers: (array) data_get($byId->get('inbox_capture_pipeline'), 'blockers', []),
            ),
            $this->checklistItem(
                requirement: 'Task/Agent Orchestration has receipts and no hidden external execution authority',
                artifact: 'Task orchestration report and receipt/hash-chain backfill',
                evidenceCommand: (string) data_get($byId->get('task_agent_orchestration'), 'canonical_command'),
                status: (string) data_get($byId->get('task_agent_orchestration'), 'status', 'unknown'),
                blockers: (array) data_get($byId->get('task_agent_orchestration'), 'blockers', []),
            ),
            $this->checklistItem(
                requirement: 'Tool/Action Runtime is evidenced without executing tools in the audit',
                artifact: 'Tool/action runtime report with latest action-runtime contract coverage',
                evidenceCommand: (string) data_get($byId->get('tool_action_runtime'), 'canonical_command'),
                status: (string) data_get($byId->get('tool_action_runtime'), 'status', 'unknown'),
                blockers: (array) data_get($byId->get('tool_action_runtime'), 'blockers', []),
            ),
            $this->checklistItem(
                requirement: 'Autonomy/Long-Running Work is schedulable but escalation-safe',
                artifact: 'Long-running work report and autonomy receipt checks',
                evidenceCommand: (string) data_get($byId->get('autonomy_long_running_work'), 'canonical_command'),
                status: (string) data_get($byId->get('autonomy_long_running_work'), 'status', 'unknown'),
                blockers: (array) data_get($byId->get('autonomy_long_running_work'), 'blockers', []),
            ),
            $this->checklistItem(
                requirement: 'Evaluation/Rivals Framework proves P4 only with real scored review and healthy agency',
                artifact: 'Rivals Strategy report with P4 promotion readiness contract',
                evidenceCommand: (string) data_get($byId->get('evaluation_rivals_framework'), 'canonical_command'),
                status: (string) data_get($byId->get('evaluation_rivals_framework'), 'status', 'unknown'),
                blockers: (array) data_get($byId->get('evaluation_rivals_framework'), 'blockers', []),
            ),
            $this->checklistItem(
                requirement: 'Notification/Proactive Layer surfaces critical insights without agent auto-resolution',
                artifact: 'Proactive layer report with critical review contract',
                evidenceCommand: (string) data_get($byId->get('notification_proactive_layer'), 'canonical_command'),
                status: (string) data_get($byId->get('notification_proactive_layer'), 'status', 'unknown'),
                blockers: (array) data_get($byId->get('notification_proactive_layer'), 'blockers', []),
            ),
            $this->checklistItem(
                requirement: 'Voice/LiveKit remains parked as later surface',
                artifact: 'Structure mother audit rules',
                evidenceCommand: 'php artisan atlas:ai:structure-mother-audit --json',
                status: 'ready',
                blockers: [],
                evidence: ['no_voice_livekit_work' => true],
            ),
            $this->checklistItem(
                requirement: 'Self-Construction OS / Agent Control Plane is excluded from this lane',
                artifact: 'Structure mother audit rules',
                evidenceCommand: 'php artisan atlas:ai:structure-mother-audit --json',
                status: 'ready',
                blockers: [],
                evidence: ['self_construction_control_plane_excluded' => true],
            ),
            $this->checklistItem(
                requirement: 'Goal completion may be marked only when all eight modules are ready',
                artifact: 'structure_mother_audit.completion_gate',
                evidenceCommand: 'php artisan atlas:ai:structure-mother-audit --json',
                status: $complete ? 'ready' : 'blocked',
                blockers: $complete ? [] : ['completion_gate_blocked'],
                evidence: ['update_goal_allowed' => $complete],
            ),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $modules
     * @return array<string,mixed>
     */
    private function promptToArtifactChecklist(array $modules, bool $complete, int $hours, string $workspace): array
    {
        $byId = collect($modules)->keyBy('id');
        $moduleItems = collect([
            'memory_context_engine',
            'knowledge_base_open_brain',
            'inbox_capture_pipeline',
            'task_agent_orchestration',
            'tool_action_runtime',
            'autonomy_long_running_work',
            'evaluation_rivals_framework',
            'notification_proactive_layer',
        ])->map(function (string $moduleId) use ($byId): array {
            $module = (array) $byId->get($moduleId, []);

            return [
                'requirement' => (string) ($module['label'] ?? $moduleId),
                'artifact' => (string) ($module['id'] ?? $moduleId),
                'evidence_command' => (string) ($module['canonical_command'] ?? ''),
                'implementation_status' => (string) ($module['implementation_status'] ?? 'unknown'),
                'operational_status' => (string) ($module['operational_status'] ?? 'unknown'),
                'blockers' => (array) ($module['blockers'] ?? []),
                'operational_blockers' => (array) ($module['operational_blockers'] ?? []),
                'covered' => (string) ($module['implementation_status'] ?? 'unknown') === 'ready',
            ];
        })->values()->all();

        return [
            'schema_version' => 'atlas.structure_mother.prompt_to_artifact_checklist.v1',
            'objective' => 'Concluir a estrutura mae enterprise do Atlas AI de ponta a ponta nos oito modulos, mantendo Voice estacionado e Self-Construction fora desta lane.',
            'workspace' => $workspace,
            'hours' => $hours,
            'result' => [
                'implementation_complete' => collect($moduleItems)->every(fn (array $item): bool => (bool) $item['covered']),
                'complete' => $complete,
                'update_goal_allowed' => $complete,
            ],
            'named_files' => $this->handoffFileChecklist($workspace),
            'modules' => $moduleItems,
            'rules' => [
                [
                    'requirement' => 'Voice/LiveKit parked as later surface.',
                    'artifact' => 'structure_mother_audit.rules.no_voice_livekit_work',
                    'status' => 'covered',
                ],
                [
                    'requirement' => 'Atlas Self-Construction OS / Agent Control Plane excluded.',
                    'artifact' => 'structure_mother_audit.rules.self_construction_control_plane_excluded',
                    'status' => 'covered',
                ],
                [
                    'requirement' => 'Audit must not call providers, execute runtime, promote memory or mutate policy.',
                    'artifact' => 'structure_mother_audit.rules',
                    'status' => 'covered',
                ],
                [
                    'requirement' => 'Preserve dirty worktree and concurrent session changes.',
                    'artifact' => 'git status --short; git diff --stat',
                    'status' => 'operator_reviewed',
                ],
            ],
            'validation_commands' => [
                'php artisan test <tests focados>',
                'php artisan atlas:ai:structure-mother-audit --hours='.$hours.' --workspace='.$workspace.' --json',
                'php artisan atlas:ai:architecture-validate --json',
                'php artisan atlas:ai:runtime-boundary --json',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:engineering:knowledge sync --prune --json',
                'php artisan atlas:engineering:knowledge index-code --prune --summary-only --json',
                'git diff --check',
            ],
            'completion_blockers' => $complete ? [] : [
                'calendar_or_operator_action_required_before_goal_completion',
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function handoffFileChecklist(string $workspace): array
    {
        $serverHandoff = $workspace.'/docs/engineering-knowledge-base/atlas-structure-mother-handoff.md';
        $rootHandoff = dirname($workspace).'/docs/engineering-knowledge-base/atlas-structure-mother-handoff.md';

        return [
            [
                'requirement' => 'Read root handoff before architecture decisions when present.',
                'artifact' => $rootHandoff,
                'exists' => is_file($rootHandoff),
                'status' => is_file($rootHandoff) ? 'covered' : 'not_present_optional',
            ],
            [
                'requirement' => 'Read server-local handoff before architecture decisions when present.',
                'artifact' => $serverHandoff,
                'exists' => is_file($serverHandoff),
                'status' => is_file($serverHandoff) ? 'covered' : 'missing_required_for_current_server_context',
            ],
        ];
    }

    /**
     * @param  array<int,string>  $blockers
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function checklistItem(string $requirement, string $artifact, string $evidenceCommand, string $status, array $blockers, array $evidence = []): array
    {
        return [
            'requirement' => $requirement,
            'artifact' => $artifact,
            'evidence_command' => $evidenceCommand,
            'status' => $status,
            'passed' => $status === 'ready',
            'blockers' => array_values($blockers),
            'evidence' => $evidence,
        ];
    }
}
