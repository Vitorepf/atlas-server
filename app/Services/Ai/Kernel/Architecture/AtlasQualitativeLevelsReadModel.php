<?php

namespace App\Services\Ai\Kernel\Architecture;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;
use App\Services\Engineering\EngineeringDocumentationHealthService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

class AtlasQualitativeLevelsReadModel
{
    public const SCHEMA_VERSION = 'atlas.qualitative_levels.v1';

    public function __construct(
        private readonly EngineeringDocumentationHealthService $documentation,
        private readonly AtlasAiDomainCatalogService $domains,
        private readonly AtlasRivalsStrategyReadModel $rivalsStrategy,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $since ??= now()->subDays(30);
        $until ??= now();

        $docs = $this->documentation->report();
        $domains = $this->domains->inspect();
        $ledger = $this->ledgerSignals($since, $until);
        $rivals = $this->rivalsStrategy->report($since, $until);
        $evidence = $this->evidence($docs, $domains, $ledger, $rivals);
        $gates = $this->gates($docs, $domains, $ledger, $rivals);
        $level = $this->currentLevel($gates, $ledger);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'current_level' => $level,
            'current_level_label' => $this->levelLabel($level),
            'next_level' => $this->nextLevel($level),
            'next_level_blockers' => $this->nextLevelBlockers($level, $gates),
            'window' => [
                'since' => $since->toJSON(),
                'until' => $until->toJSON(),
            ],
            'evidence' => $evidence,
            'advanced_readiness' => $this->advancedReadiness($ledger, $rivals),
            'missing_gates' => array_values(array_filter(
                $gates,
                fn (array $gate): bool => $gate['status'] !== 'passed',
            )),
            'gates' => $gates,
            'rules' => [
                'read_model_only' => true,
                'no_behavior_change' => true,
                'no_strategy_execution' => true,
                'human_agency_required' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $ledger
     * @param  array<string,mixed>  $rivals
     * @return array<string,mixed>
     */
    private function advancedReadiness(array $ledger, array $rivals): array
    {
        return [
            'p6_presence_eclipse' => [
                'schema_version' => 'atlas.qualitative_levels.p6_presence_readiness.v1',
                'status' => 'started_not_promotable',
                'promotion_allowed' => false,
                'implemented_evidence' => [
                    'mobile_presence_eclipse_contract' => 'atlas.proactive.presence_eclipse.v1',
                    'read_model_command' => 'php artisan atlas:ai:proactive-layer-report --hours=720 --json',
                    'safety_properties' => [
                        'explicit_opt_out_required',
                        'manual_eclipse_required',
                        'quiet_hours_required',
                        'no_surveillance_default',
                        'pointer_only_push',
                        'hash_only_delivery_receipts',
                    ],
                ],
                'missing_evidence' => [
                    'broader_environment_presence_surfaces',
                    'cross_surface_opt_in_registry',
                    'friction_regret_measurement',
                    'human_reviewed_presence_rollout',
                ],
                'prohibited_claims' => [
                    'declare_p6_or_higher',
                    'ambient_presence_without_opt_in',
                    'surveillance_or_raw_context_push',
                    'autonomous_dispatch_from_presence_signal',
                ],
            ],
            'p7_longitudinal_memory' => [
                'schema_version' => 'atlas.qualitative_levels.p7_longitudinal_readiness.v1',
                'status' => 'roadmap_only_not_promotable',
                'promotion_allowed' => false,
                'available_evidence' => [
                    'ledger_available' => (bool) ($ledger['available'] ?? false),
                    'ledger_event_count' => (int) ($ledger['event_count'] ?? 0),
                    'rivals_scored_review_count' => (int) ($rivals['scored_review_count'] ?? 0),
                    'roadmap' => 'docs/engineering-knowledge-base/evolution/personal-longitudinal-roadmap.md',
                ],
                'missing_evidence' => [
                    'years_scale_history',
                    'privacy_vault_and_forgetting_review',
                    'human_reviewed_longitudinal_patterns',
                    'non_obvious_pattern_verification',
                    'agency_preservation_review',
                ],
                'prohibited_claims' => [
                    'declare_p7_or_long_lived_mirror',
                    'infer_identity_or_health_patterns_without_opt_in',
                    'send_raw_personal_memory_to_provider',
                    'auto_change_calendar_health_plan_identity_or_curriculum',
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ledgerSignals(CarbonInterface $since, CarbonInterface $until): array
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return [
                'available' => false,
                'event_count' => 0,
                'event_type_counts' => [],
                'self_improvement_event_count' => 0,
                'provider_event_count' => 0,
                'repair_event_count' => 0,
                'gate_event_count' => 0,
            ];
        }

        $events = AtlasLedgerEvent::query()
            ->whereBetween('occurred_at', [$since, $until])
            ->get(['event_type', 'emitter_stage']);

        $eventTypes = $events->pluck('event_type')->filter()->countBy()->all();

        return [
            'available' => true,
            'event_count' => $events->count(),
            'event_type_counts' => $eventTypes,
            'self_improvement_event_count' => $events
                ->filter(fn (AtlasLedgerEvent $event): bool => str_contains((string) $event->emitter_stage, 'self_improvement'))
                ->count(),
            'provider_event_count' => $events
                ->filter(fn (AtlasLedgerEvent $event): bool => str_contains((string) $event->event_type, 'PROVIDER'))
                ->count(),
            'repair_event_count' => $events
                ->filter(fn (AtlasLedgerEvent $event): bool => str_contains((string) $event->event_type, 'REPAIR'))
                ->count(),
            'gate_event_count' => $events
                ->filter(fn (AtlasLedgerEvent $event): bool => str_contains((string) $event->event_type, 'GATE'))
                ->count(),
        ];
    }

    /**
     * @param  array<string,mixed>  $docs
     * @param  array<string,mixed>  $domains
     * @param  array<string,mixed>  $ledger
     * @return array<string,mixed>
     */
    private function evidence(array $docs, array $domains, array $ledger, array $rivals): array
    {
        return [
            'documentation_governance' => [
                'available' => ($docs['status'] ?? null) === 'ok',
                'required_doc_count' => (int) data_get($docs, 'summary.required_doc_count', 0),
                'required_missing_count' => (int) data_get($docs, 'summary.required_missing_count', 0),
                'frontmatter_violation_count' => (int) data_get($docs, 'summary.frontmatter_violation_count', 0),
            ],
            'domain_system' => [
                'available' => ($domains['status'] ?? null) === 'ok',
                'ready_domains' => (int) data_get($domains, 'summary.ready_domains', 0),
                'scaffold_domains' => (int) data_get($domains, 'summary.scaffold_domains', 0),
                'flow_count' => (int) data_get($domains, 'summary.flows', 0),
                'strategic_decision_scaffolded' => collect((array) ($domains['domains'] ?? []))
                    ->contains(fn (array $domain): bool => (string) ($domain['id'] ?? '') === 'strategic_decision'),
            ],
            'evidence_ledger' => $ledger,
            'rivals_strategy' => [
                'available' => (bool) ($rivals['available'] ?? false),
                'case_count' => (int) ($rivals['case_count'] ?? 0),
                'scheduled_review_count' => (int) ($rivals['scheduled_review_count'] ?? 0),
                'scored_review_count' => (int) ($rivals['scored_review_count'] ?? 0),
                'average_regret_score' => $rivals['average_regret_score'] ?? null,
                'average_alignment_score' => $rivals['average_alignment_score'] ?? null,
                'average_agency_score' => $rivals['average_agency_score'] ?? null,
                'strategy_multiplier_score' => $rivals['strategy_multiplier_score'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $docs
     * @param  array<string,mixed>  $domains
     * @param  array<string,mixed>  $ledger
     * @return array<int,array{id:string,status:string,reason:string}>
     */
    private function gates(array $docs, array $domains, array $ledger, array $rivals): array
    {
        $strategicDomain = collect((array) ($domains['domains'] ?? []))
            ->contains(fn (array $domain): bool => (string) ($domain['id'] ?? '') === 'strategic_decision');

        return [
            $this->gate('documentation_governance', ($docs['status'] ?? null) === 'ok', 'Required docs and frontmatter must be valid.'),
            $this->gate('domain_catalog_ready', ($domains['status'] ?? null) === 'ok', 'Domain catalog and orchestrator registry must validate.'),
            $this->gate('evidence_ledger_available', (bool) ($ledger['available'] ?? false), 'Qualitative levels require Evidence Ledger signals.'),
            $this->gate('provider_performance_evidence', (int) ($ledger['provider_event_count'] ?? 0) > 0, 'P2+ needs provider performance evidence.'),
            $this->gate('repair_and_gate_evidence', (int) ($ledger['repair_event_count'] ?? 0) > 0 && (int) ($ledger['gate_event_count'] ?? 0) > 0, 'P3 needs repair and quality gate evidence.'),
            $this->gate('self_improvement_evidence', (int) ($ledger['self_improvement_event_count'] ?? 0) > 0, 'P5 needs Curator/Self-Improvement evidence.'),
            $this->gate('strategic_decision_scaffold', $strategicDomain, 'P4 needs strategic_decision domain scaffold before co-strategist work.'),
            $this->gate('rivals_strategy_benchmark', (bool) ($rivals['available'] ?? false) && (int) ($rivals['scored_review_count'] ?? 0) > 0 && ((float) ($rivals['average_agency_score'] ?? 0)) >= 70, 'P4+ needs scored Rivals benchmark with healthy agency.'),
            $this->gate('presence_eclipse_governance', false, 'P6 needs explicit opt-in, no-surveillance and eclipse governance.'),
            $this->gate('longitudinal_memory_evidence', false, 'P7 needs years-scale longitudinal evidence and privacy review.'),
        ];
    }

    /**
     * @return array{id:string,status:string,reason:string}
     */
    private function gate(string $id, bool $passed, string $reason): array
    {
        return [
            'id' => $id,
            'status' => $passed ? 'passed' : 'missing',
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<int,array{id:string,status:string,reason:string}>  $gates
     * @param  array<string,mixed>  $ledger
     */
    private function currentLevel(array $gates, array $ledger): string
    {
        if (! $this->passed($gates, ['documentation_governance', 'domain_catalog_ready'])) {
            return 'P0';
        }
        if (! $this->passed($gates, ['evidence_ledger_available']) || (int) ($ledger['event_count'] ?? 0) === 0) {
            return 'P1';
        }
        if (! $this->passed($gates, ['provider_performance_evidence'])) {
            return 'P1';
        }
        if (! $this->passed($gates, ['repair_and_gate_evidence'])) {
            return 'P2';
        }
        if (! $this->passed($gates, ['strategic_decision_scaffold', 'rivals_strategy_benchmark'])) {
            return 'P3';
        }
        if (! $this->passed($gates, ['self_improvement_evidence'])) {
            return 'P4';
        }

        return 'P5';
    }

    /**
     * @param  array<int,array{id:string,status:string,reason:string}>  $gates
     * @param  array<int,string>  $ids
     */
    private function passed(array $gates, array $ids): bool
    {
        $byId = collect($gates)->keyBy('id');

        foreach ($ids as $id) {
            if ((string) data_get($byId->get($id), 'status') !== 'passed') {
                return false;
            }
        }

        return true;
    }

    private function nextLevel(string $level): string
    {
        return match ($level) {
            'P0' => 'P1',
            'P1' => 'P2',
            'P2' => 'P3',
            'P3' => 'P4',
            'P4' => 'P5',
            'P5' => 'P6',
            'P6' => 'P7',
            default => 'P7',
        };
    }

    private function levelLabel(string $level): string
    {
        return match ($level) {
            'P0' => 'Unmeasured Foundation',
            'P1' => 'Reactive Contextualized',
            'P2' => 'Proactive Calibrated',
            'P3' => 'Long Task Autonomous',
            'P4' => 'Discordant Co-Strategist',
            'P5' => 'Audited Self-Modifiable',
            'P6' => 'Embodied Federated',
            'P7' => 'Long-Lived Mirror',
            default => 'Unknown',
        };
    }

    /**
     * @param  array<int,array{id:string,status:string,reason:string}>  $gates
     * @return array<int,string>
     */
    private function nextLevelBlockers(string $level, array $gates): array
    {
        $needed = match ($this->nextLevel($level)) {
            'P1' => ['documentation_governance', 'domain_catalog_ready'],
            'P2' => ['evidence_ledger_available', 'provider_performance_evidence'],
            'P3' => ['repair_and_gate_evidence'],
            'P4' => ['strategic_decision_scaffold', 'rivals_strategy_benchmark'],
            'P5' => ['self_improvement_evidence'],
            'P6' => ['presence_eclipse_governance'],
            'P7' => ['longitudinal_memory_evidence'],
            default => [],
        };
        $byId = collect($gates)->keyBy('id');

        return collect($needed)
            ->filter(fn (string $id): bool => (string) data_get($byId->get($id), 'status') !== 'passed')
            ->map(fn (string $id): string => (string) data_get($byId->get($id), 'reason', $id))
            ->values()
            ->all();
    }
}
