<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtlasAreaFocusLoopReadModelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Software Company Stewardship Stack · Evolution Read Model (AP-730).
 *
 * Materializes the levels above Area Focus Loop without creating a new OS,
 * executor, brancher, proposal registry or authority surface. The service is
 * read-only / proposal-only: it projects Area Stewardship, Portfolio
 * Stewardship, Autonomous Executive and Self-Expanding Software Company from
 * the existing Area Focus owner and returns operator-reviewable proposals.
 *
 * Hard guarantees: no provider calls, no repo mutation, no branch/worktree
 * creation, no Dev/Forge dispatch, no merge/deploy/secrets/destructive changes.
 */
class StewardshipEvolutionReadModelService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.evolution_ladder.v1';

    public const AREA_STEWARD_SCHEMA = 'atlas.area.stewardship.v1';

    public const PORTFOLIO_SCHEMA = 'atlas.portfolio.stewardship.v1';

    public const EXECUTIVE_SCHEMA = 'atlas.executive.recommendation.v1';

    public const SELF_EXPANDING_SCHEMA = 'atlas.software_company.new_area_proposal.v1';

    public const STATUS_READY_PROPOSAL_ONLY = 'ready_proposal_only';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    private const DEFAULT_PORTFOLIO_ID = 'atlas_software_company';

    public function __construct(
        private readonly AtlasAreaFocusLoopReadModelService $areaFocus,
    ) {}

    /**
     * Project the whole future ladder as a deterministic read model.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input = []): array
    {
        $areaId = trim((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID)) ?: self::DEFAULT_AREA_ID;
        $areaFocus = $this->areaFocusReport($areaId, $input);

        if (($areaFocus['status'] ?? '') === AtlasAreaFocusLoopReadModelService::STATUS_BLOCKED) {
            return $this->finalize([
                'schema_version' => self::REPORT_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'reason' => 'area_focus_not_ready',
                'area_id' => $areaId,
                'area_focus_loop' => $areaFocus,
                'claim_policy' => $this->claimPolicy(),
            ]);
        }

        $areaStewardship = $this->areaStewardship($areaFocus, $input);
        $portfolio = $this->portfolioStewardship($areaStewardship, $input);
        $executive = $this->autonomousExecutive($portfolio, $input);
        $selfExpanding = $this->selfExpandingCompany($portfolio, $executive, $input);

        return $this->finalize([
            'schema_version' => self::REPORT_SCHEMA,
            'status' => self::STATUS_READY_PROPOSAL_ONLY,
            'ap_contract' => 'AP-730',
            'stewardship_stack' => $this->stackEnvelope(),
            'area_focus_loop' => $areaFocus,
            'area_stewardship' => $areaStewardship,
            'portfolio_stewardship' => $portfolio,
            'autonomous_executive' => $executive,
            'self_expanding_software_company' => $selfExpanding,
            'promotion_gates' => $this->promotionGates(),
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function areaFocusReport(string $areaId, array $input): array
    {
        if (is_array($input['area_focus_report'] ?? null)) {
            return $input['area_focus_report'];
        }

        $forward = [];
        foreach (['contract', 'owner_doc_exists'] as $key) {
            if (array_key_exists($key, $input)) {
                $forward[$key] = $input[$key];
            }
        }

        return $this->areaFocus->project($forward + ['area_id' => $areaId]);
    }

    /**
     * @param  array<string,mixed>  $areaFocus
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function areaStewardship(array $areaFocus, array $input): array
    {
        $contract = is_array($areaFocus['area_contract'] ?? null) ? $areaFocus['area_contract'] : [];
        $health = is_array($areaFocus['health_summary'] ?? null) ? $areaFocus['health_summary'] : [];
        $areaId = (string) ($contract['area_id'] ?? $areaFocus['area_id'] ?? self::DEFAULT_AREA_ID);
        $score = $this->areaHealthScore($areaFocus);

        return [
            'schema_version' => self::AREA_STEWARD_SCHEMA,
            'status' => self::STATUS_READY_PROPOSAL_ONLY,
            'mode' => 'read_only',
            'area_id' => $areaId,
            'area_name' => (string) ($contract['area_name'] ?? 'Unknown Area'),
            'area_owner_docs' => array_values((array) ($contract['owner_docs'] ?? [])),
            'area_scope' => is_array($contract['repo_scope'] ?? null) ? $contract['repo_scope'] : [],
            'health_model' => [
                'schema_version' => 'atlas.area.health_model.v1',
                'score' => $score,
                'band' => $this->healthBand($score),
                'source' => 'area_focus_loop_read_model',
                'owner_docs_present' => (int) ($health['owner_docs_present'] ?? 0),
                'owner_docs_total' => (int) ($health['owner_docs_total'] ?? 0),
                'finding_seed_count' => (int) ($health['finding_seed_count'] ?? 0),
            ],
            'roadmap_candidates' => $this->areaRoadmapCandidates($areaFocus),
            'dev_forge_policy' => [
                'small_local_work' => 'atlas_dev',
                'cross_system_or_long_horizon_work' => 'forge',
                'gap_or_spec_work' => 'self_directed_evolution',
                'high_risk_or_sensitive_work' => 'operator_review',
            ],
            'operator_inbox' => [
                'destination' => (string) ($contract['inbox_destination'] ?? 'morning_inbox'),
                'decision_options' => ['accept', 'reject', 'defer', 'request_changes'],
                'auto_approval' => false,
            ],
            'promotion_state' => 'proposal_only_until_operator_approval',
        ];
    }

    /**
     * @param  array<string,mixed>  $areaStewardship
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function portfolioStewardship(array $areaStewardship, array $input): array
    {
        $areas = $this->portfolioAreas($areaStewardship, $input);
        $lowest = $this->lowestHealthArea($areas);
        $objective = trim((string) ($input['portfolio_objective'] ?? 'maximizar evolucao do Atlas como empresa de software autonoma'));

        return [
            'schema_version' => self::PORTFOLIO_SCHEMA,
            'status' => self::STATUS_READY_PROPOSAL_ONLY,
            'mode' => 'read_only',
            'portfolio_id' => (string) ($input['portfolio_id'] ?? self::DEFAULT_PORTFOLIO_ID),
            'portfolio_objective' => $objective,
            'areas' => $areas,
            'dependency_graph' => $this->dependencyGraph($areas),
            'portfolio_health' => [
                'score' => $this->portfolioHealthScore($areas),
                'lowest_health_area' => $lowest['area_id'] ?? null,
                'area_count' => count($areas),
            ],
            'resource_policy' => [
                'dev_capacity_units' => (int) ($input['dev_capacity_units'] ?? 2),
                'forge_capacity_units' => (int) ($input['forge_capacity_units'] ?? 1),
                'wip_limit' => (int) ($input['wip_limit'] ?? 3),
                'allocation_rule' => 'prioritize_lowest_health_with_highest_unlock',
            ],
            'rebalance_policy' => [
                'pause_when' => ['kill_switch', 'budget_exhausted', 'unresolved_critical_risk'],
                'accelerate_when' => ['health_below_70', 'blocks_multiple_areas', 'operator_accepts'],
            ],
            'candidate_areas' => $this->candidateAreas($areas),
            'operator_inbox' => [
                'destination' => 'morning_inbox',
                'auto_approval' => false,
                'decision_type' => 'portfolio_rebalance',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $portfolio
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function autonomousExecutive(array $portfolio, array $input): array
    {
        $areas = is_array($portfolio['areas'] ?? null) ? $portfolio['areas'] : [];
        $lowest = $this->lowestHealthArea($areas);
        $targetArea = (string) ($lowest['area_id'] ?? 'agentic_engineering_os');
        $score = (int) ($lowest['health_score'] ?? 0);

        return [
            'schema_version' => self::EXECUTIVE_SCHEMA,
            'status' => self::STATUS_READY_PROPOSAL_ONLY,
            'mode' => 'recommendation_only',
            'recommendation_id' => 'exec_'.substr(MissionCanonicalHash::sha256([$portfolio['portfolio_id'] ?? '', $targetArea, $score]), 0, 16),
            'portfolio_id' => (string) ($portfolio['portfolio_id'] ?? self::DEFAULT_PORTFOLIO_ID),
            'primary_recommendation' => [
                'action' => 'allocate_next_governed_cycle',
                'target_area' => $targetArea,
                'why' => 'lowest portfolio health / highest unlock candidate',
                'expected_unlock' => $this->expectedUnlock($targetArea),
            ],
            'capacity_allocation' => [
                'atlas_dev_cycles' => 2,
                'forge_cycles' => $score < 75 ? 1 : 0,
                'self_directed_evolution_cycles' => 1,
                'operator_review_required' => true,
            ],
            'tradeoffs' => [
                'do_now' => [$targetArea],
                'defer' => array_values(array_diff(array_map(static fn (array $a): string => (string) ($a['area_id'] ?? ''), $areas), [$targetArea, ''])),
                'risk' => 'recommendation may be wrong without operator feedback and outcome evidence',
            ],
            'decision_inbox' => [
                'destination' => 'morning_inbox',
                'decision_options' => ['accept', 'reject', 'defer', 'request_changes'],
                'irreversible_action_allowed' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $portfolio
     * @param  array<string,mixed>  $executive
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function selfExpandingCompany(array $portfolio, array $executive, array $input): array
    {
        $observed = $this->observedGaps($portfolio, $input);
        $proposals = [];
        foreach ($observed as $gap) {
            $proposal = $this->newAreaProposal($gap, $portfolio, $executive);
            $proposals[$proposal['proposal_id']] = $proposal;
        }

        return [
            'schema_version' => self::SELF_EXPANDING_SCHEMA,
            'status' => $proposals === [] ? 'no_expansion_needed' : self::STATUS_READY_PROPOSAL_ONLY,
            'mode' => 'proposal_only',
            'portfolio_id' => (string) ($portfolio['portfolio_id'] ?? self::DEFAULT_PORTFOLIO_ID),
            'observed_gap_count' => count($observed),
            'proposal_count' => count($proposals),
            'new_area_proposals' => array_values($proposals),
            'promotion_gate' => [
                'operator_approval_required' => true,
                'domain_runtime_creation_gate_required_when_domain' => true,
                'dual_review_required' => true,
                'evidence_required' => true,
                'auto_promotion' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $areaFocus
     */
    private function areaHealthScore(array $areaFocus): int
    {
        $health = is_array($areaFocus['health_summary'] ?? null) ? $areaFocus['health_summary'] : [];
        $total = max(1, (int) ($health['owner_docs_total'] ?? 1));
        $present = max(0, (int) ($health['owner_docs_present'] ?? 0));
        $seeds = max(0, (int) ($health['finding_seed_count'] ?? 0));

        return max(0, min(100, (int) round(72 + (($present / $total) * 28) - min(20, $seeds * 3))));
    }

    private function healthBand(int $score): string
    {
        return match (true) {
            $score >= 90 => 'excellent',
            $score >= 80 => 'healthy',
            $score >= 70 => 'watch',
            $score >= 50 => 'at_risk',
            default => 'critical',
        };
    }

    /**
     * @param  array<string,mixed>  $areaFocus
     * @return list<array<string,mixed>>
     */
    private function areaRoadmapCandidates(array $areaFocus): array
    {
        $actions = array_values(array_filter((array) ($areaFocus['next_actions'] ?? []), 'is_string'));
        if ($actions === []) {
            $actions = ['Run deep findings, curate specs, route safe work and measure outcome.'];
        }

        return array_map(static function (string $action, int $i): array {
            return [
                'candidate_id' => 'roadmap_'.($i + 1),
                'summary' => $action,
                'route' => str_contains(strtolower($action), 'forge') ? 'forge' : 'self_directed_evolution',
                'requires_operator_review' => true,
            ];
        }, $actions, array_keys($actions));
    }

    /**
     * @param  array<string,mixed>  $areaStewardship
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function portfolioAreas(array $areaStewardship, array $input): array
    {
        $areas = is_array($input['areas'] ?? null) ? array_values(array_filter($input['areas'], 'is_array')) : [];
        if ($areas === []) {
            $areas[] = [
                'area_id' => (string) $areaStewardship['area_id'],
                'area_name' => (string) $areaStewardship['area_name'],
                'health_score' => (int) ($areaStewardship['health_model']['score'] ?? 0),
                'status' => 'stewarded_seed',
                'dependencies' => ['atlas_dev', 'atlas_forge', 'self_directed_evolution', 'evidence'],
            ];
        }

        return array_map(function (array $area): array {
            $id = trim((string) ($area['area_id'] ?? 'unknown'));

            return [
                'area_id' => $id === '' ? 'unknown' : $id,
                'area_name' => (string) ($area['area_name'] ?? str_replace('_', ' ', $id)),
                'health_score' => max(0, min(100, (int) ($area['health_score'] ?? 70))),
                'status' => (string) ($area['status'] ?? 'candidate'),
                'dependencies' => array_values(array_filter((array) ($area['dependencies'] ?? []), 'is_string')),
            ];
        }, $areas);
    }

    /**
     * @param  list<array<string,mixed>>  $areas
     * @return array<string,list<string>>
     */
    private function dependencyGraph(array $areas): array
    {
        $graph = [];
        foreach ($areas as $area) {
            $graph[(string) $area['area_id']] = array_values(array_filter((array) ($area['dependencies'] ?? []), 'is_string'));
        }

        return $graph;
    }

    /**
     * @param  list<array<string,mixed>>  $areas
     */
    private function portfolioHealthScore(array $areas): int
    {
        if ($areas === []) {
            return 0;
        }

        return (int) round(array_sum(array_map(static fn (array $a): int => (int) ($a['health_score'] ?? 0), $areas)) / count($areas));
    }

    /**
     * @param  list<array<string,mixed>>  $areas
     * @return array<string,mixed>
     */
    private function lowestHealthArea(array $areas): array
    {
        usort($areas, static fn (array $a, array $b): int => ((int) ($a['health_score'] ?? 0)) <=> ((int) ($b['health_score'] ?? 0)));

        return $areas[0] ?? [];
    }

    /**
     * @param  list<array<string,mixed>>  $areas
     * @return list<array<string,mixed>>
     */
    private function candidateAreas(array $areas): array
    {
        $existing = array_flip(array_map(static fn (array $a): string => (string) ($a['area_id'] ?? ''), $areas));
        $candidates = [];
        foreach (['atlas_dev', 'atlas_forge', 'self_directed_evolution', 'evidence'] as $id) {
            if (! isset($existing[$id])) {
                $candidates[] = ['area_id' => $id, 'reason' => 'core dependency not yet stewarded as a portfolio area'];
            }
        }

        return $candidates;
    }

    private function expectedUnlock(string $targetArea): string
    {
        return match ($targetArea) {
            'atlas_dev' => 'faster safe local implementation',
            'atlas_forge' => 'stronger long-horizon multi-agent execution',
            'self_directed_evolution' => 'more autonomous spec generation',
            'evidence' => 'stronger proof and replay',
            default => 'higher Atlas software company stewardship maturity',
        };
    }

    /**
     * @param  array<string,mixed>  $portfolio
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function observedGaps(array $portfolio, array $input): array
    {
        if (is_array($input['observed_gaps'] ?? null)) {
            return array_values(array_filter(array_map(function (mixed $gap): array {
                if (is_array($gap)) {
                    return $gap;
                }

                return ['gap_id' => (string) $gap, 'summary' => (string) $gap, 'candidate_area' => (string) $gap];
            }, $input['observed_gaps']), static fn (array $gap): bool => (string) ($gap['summary'] ?? '') !== ''));
        }

        return array_map(static fn (array $candidate): array => [
            'gap_id' => 'gap_'.$candidate['area_id'],
            'summary' => 'Portfolio dependency is not yet stewarded: '.$candidate['area_id'],
            'candidate_area' => $candidate['area_id'],
            'evidence_refs' => ['portfolio.candidate_areas'],
        ], array_slice((array) ($portfolio['candidate_areas'] ?? []), 0, 3));
    }

    /**
     * @param  array<string,mixed>  $gap
     * @param  array<string,mixed>  $portfolio
     * @param  array<string,mixed>  $executive
     * @return array<string,mixed>
     */
    private function newAreaProposal(array $gap, array $portfolio, array $executive): array
    {
        $candidate = trim((string) ($gap['candidate_area'] ?? $gap['gap_id'] ?? 'new_area'));
        $candidate = $candidate === '' ? 'new_area' : $candidate;
        $hash = MissionCanonicalHash::sha256([$candidate, $gap['summary'] ?? '', $portfolio['portfolio_id'] ?? '']);

        return [
            'schema_version' => self::SELF_EXPANDING_SCHEMA,
            'proposal_id' => 'new_area_'.substr($hash, 0, 16),
            'candidate_area' => $candidate,
            'gap_detected' => (string) ($gap['summary'] ?? 'Unspecified portfolio gap'),
            'portfolio_id' => (string) ($portfolio['portfolio_id'] ?? self::DEFAULT_PORTFOLIO_ID),
            'owner_docs_suggested' => [
                'docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md',
                'docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md',
            ],
            'initial_health_model' => ['score_floor' => 70, 'required_evidence' => ['owner_docs', 'area_focus_read_model', 'operator_inbox']],
            'initial_roadmap' => ['create proposal doc', 'create read-only area contract', 'wire operator inbox', 'certify no parallel runtime'],
            'risk_policy' => ['proposal_only' => true, 'no_auto_promotion' => true, 'operator_approval_required' => true],
            'domain_runtime_creation_gate' => 'required_if_candidate_becomes_domain_runtime',
            'operator_approval' => 'required',
            'executive_recommendation_ref' => (string) ($executive['recommendation_id'] ?? ''),
        ];
    }

    /**
     * @return list<array<string,string>>
     */
    private function promotionGates(): array
    {
        return [
            ['from' => 'continuous_loop', 'to' => 'area_focus_loop', 'gate' => 'area_id + owner docs + read-only scan + inbox'],
            ['from' => 'area_focus_loop', 'to' => 'area_stewardship', 'gate' => 'health model + roadmap + Dev/Forge routing + evidence'],
            ['from' => 'area_stewardship', 'to' => 'portfolio_stewardship', 'gate' => '2+ areas + dependency graph + rebalance policy'],
            ['from' => 'portfolio_stewardship', 'to' => 'autonomous_executive', 'gate' => 'recommendation schema + risk/regret + operator inbox'],
            ['from' => 'autonomous_executive', 'to' => 'self_expanding', 'gate' => 'repeated gap + proposal + Domain Runtime Creation Gate when applicable'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stackEnvelope(): array
    {
        return [
            'stack' => 'Atlas Software Company Stewardship Stack',
            'capability' => 'stewardship_evolution_read_model',
            'ap' => 'AP-730',
            'continuous_loop_is_ceiling' => false,
            'continuous_loop_role' => '24h governed motor',
            'stack_ceiling' => 'Self-Expanding Software Company',
            'new_os_created' => false,
            'parallel_runtime_created' => false,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function reusedOwners(): array
    {
        return [
            'area_focus_loop' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtlasAreaFocusLoopReadModelService.php',
            'stack_doc' => 'docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md',
            'ladder_doc' => 'docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md',
            'domain_creation_gate' => 'docs/engineering-knowledge-base/atlas-domain-runtime-creation-gate.md',
            'reality_outcome_gates' => 'docs/engineering-knowledge-base/atlas-reality-outcome-gates.md',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'proposal_only' => true,
            'providers_invoked' => false,
            'repo_mutation' => false,
            'branch_created' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'merge_without_operator' => false,
            'deploy_without_operator' => false,
            'secret_access' => false,
            'destructive_change' => false,
            'auto_promotion' => false,
            'new_os_created' => false,
            'parallel_runtime_created' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->stableIdentity($payload));
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stableIdentity(array $payload): array
    {
        unset($payload['report_hash']);

        return $this->withoutGeneratedAt($payload);
    }

    /**
     * Recursively drop every `generated_at` wall-clock stamp so report_hash is a
     * pure function of stable content. This envelope embeds whole nested
     * read-model reports (notably the Area Focus Loop report at
     * `area_focus_loop`), each carrying its own `generated_at`. A top-level-only
     * strip would fold those nested timestamps into report_hash and make it — and
     * every hash derived from it downstream (AP-733 health_hash, AP-734
     * inbox_hash, AP-735 recommendation target_hash and the AP-739 cockpit
     * surface_hash) — drift on every wall-clock second.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withoutGeneratedAt(array $payload): array
    {
        unset($payload['generated_at']);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->withoutGeneratedAt($value);
            }
        }

        return $payload;
    }
}
