<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Governance;

use App\Services\Ai\SelfConstruction\ArchitectureCouncil\AtlasArchitectureCouncilContractCritic;
use App\Services\Ai\SelfConstruction\ArchitectureCouncil\AtlasArchitectureCouncilImplementationSliceDesigner;
use App\Services\Ai\SelfConstruction\ArchitectureCouncil\AtlasArchitectureCouncilInvariantExtractor;
use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilAmbitionBudgetPolicy;
use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilDecisionLedger;
use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilLeverageRanker;
use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilRoadmapCandidateFilter;
use Throwable;

/**
 * Authoring-side governance chain — composes the previously-ORPHANED Strategy + Architecture
 * councils into ONE pass that runs (and RECORDS) on real candidates.
 *
 * Modes (env ATLAS_AUTHORING_GOVERNANCE_MODE):
 *   - off     no-op pass-through
 *   - observe (default) runs every step, records to ledger, never gates the live replenisher
 *   - enforce reserved for future arming; today still observe-equivalent at the chain level
 *
 * FAIL-OPEN absolute: any council error → pass-through envelope with `error` set.
 */
final class AtlasTaskAuthoringGovernanceChain
{
    public const SCHEMA = 'atlas.task_serving.authoring_governance.v1';

    public const MODE_OFF = 'off';

    public const MODE_OBSERVE = 'observe';

    public const MODE_ENFORCE = 'enforce';

    /** @var callable():string */
    private $clock;

    public function __construct(
        private readonly ?AtlasStrategyCouncilRoadmapCandidateFilter $filter = null,
        private readonly ?AtlasStrategyCouncilLeverageRanker $ranker = null,
        private readonly ?AtlasStrategyCouncilAmbitionBudgetPolicy $budget = null,
        private readonly ?AtlasArchitectureCouncilImplementationSliceDesigner $designer = null,
        private readonly ?AtlasArchitectureCouncilContractCritic $critic = null,
        private readonly ?AtlasArchitectureCouncilInvariantExtractor $invariantExtractor = null,
        private readonly ?AtlasStrategyCouncilDecisionLedger $ledger = null,
        ?callable $clock = null,
        private readonly ?string $modeOverride = null,
    ) {
        $this->clock = $clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z');
    }

    public function mode(): string
    {
        $raw = strtolower(trim((string) ($this->modeOverride ?? env('ATLAS_AUTHORING_GOVERNANCE_MODE', self::MODE_OBSERVE))));

        return in_array($raw, [self::MODE_OFF, self::MODE_OBSERVE, self::MODE_ENFORCE], true) ? $raw : self::MODE_OBSERVE;
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    public function govern(array $candidates): array
    {
        $mode = $this->mode();
        if ($mode === self::MODE_OFF) {
            return $this->envelope($mode, [], null, [], 'skipped', '', $this->emptyArena($candidates, 'skipped'));
        }

        try {
            $filtered = ($this->filter ?? new AtlasStrategyCouncilRoadmapCandidateFilter())->filter($candidates);
            $ranked = ($this->ranker ?? new AtlasStrategyCouncilLeverageRanker())->rank($filtered);
            $top = $this->extractTop($ranked);

            $budgetVerdict = [];
            if ($top !== null) {
                $budgetVerdict = ($this->budget ?? new AtlasStrategyCouncilAmbitionBudgetPolicy())->decide([
                    'leverage_rank' => (string) ($top['leverage_rank'] ?? ''),
                ]);
            }

            $contract = [];
            $contract = $this->buildContract($top, $contract);

            $recorded = 'skipped';
            if ($top !== null && $this->ledger !== null) {
                $recorded = $this->record($ranked, $top, $budgetVerdict);
            }

            $arena = $this->buildArena($candidates, $top, $ranked);

            return $this->envelope($mode, $ranked, $top, $contract, $recorded, '', $arena);
        } catch (Throwable $e) {
            return $this->envelope($mode, [], null, [], 'failed_open', $e->getMessage(), $this->emptyArena($candidates, 'failed_open'));
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $ranked
     * @return array<string,mixed>|null
     */
    private function extractTop(array $ranked): ?array
    {
        $accepted = is_array($ranked['accepted'] ?? null) ? array_values($ranked['accepted']) : array_values($ranked);
        foreach ($accepted as $row) {
            if (is_array($row)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|null  $top
     * @return array<string,mixed>
     */
    private function buildContract(?array $top, array $base): array
    {
        if ($top === null) {
            return $base;
        }
        $designer = $this->designer ?? new AtlasArchitectureCouncilImplementationSliceDesigner();
        $critic = $this->critic ?? new AtlasArchitectureCouncilContractCritic();
        $extractor = $this->invariantExtractor ?? new AtlasArchitectureCouncilInvariantExtractor();

        $sliceFacts = [
            'critique' => ['accepted' => true],
            'invariants' => is_array($top['invariants'] ?? null) ? $top['invariants'] : ['placeholder_invariant'],
            'boundary_map' => ['forbidden_edges' => []],
            'capability_gap' => is_array($top['capability_gap'] ?? null) ? $top['capability_gap'] : [],
        ];

        $design = $this->safeStep(static fn (): array => $designer->design($sliceFacts));
        $contract = ['design' => $design];

        $critique = $this->safeStep(static fn (): array => $critic->critique($contract));
        $contract['critique'] = $critique;

        $invariants = $this->safeStep(static fn (): array => $extractor->extract($contract));
        $contract['invariants'] = $invariants;

        return $contract;
    }

    /**
     * @param  array<int,array<string,mixed>>  $ranked
     * @param  array<string,mixed>  $top
     * @param  array<string,mixed>  $budgetVerdict
     */
    private function record(array $ranked, array $top, array $budgetVerdict): string
    {
        if ($this->ledger === null) {
            return 'skipped';
        }
        try {
            $accepted = is_array($ranked['accepted'] ?? null) ? array_values($ranked['accepted']) : array_values($ranked);
            $rejected = is_array($ranked['rejected'] ?? null) ? array_values($ranked['rejected']) : [];
            $selected = (string) ($top['candidate_id'] ?? hash('sha256', (string) json_encode($top)));
            $rejectedIds = [];
            foreach ($rejected as $r) {
                if (is_array($r) && isset($r['candidate_id'])) {
                    $rejectedIds[] = (string) $r['candidate_id'];
                }
            }
            foreach ($accepted as $r) {
                if (is_array($r) && isset($r['candidate_id']) && (string) $r['candidate_id'] !== $selected) {
                    $rejectedIds[] = (string) $r['candidate_id'];
                }
            }
            $ambition = (string) ($budgetVerdict['ambition_level'] ?? AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_STANDARD);
            $verdict = $this->ledger->append([
                'decision_id' => 'authoring:'.($this->clock)().':'.$selected,
                'selected_candidate_id' => $selected,
                'rejected_candidate_ids' => array_values(array_unique($rejectedIds)),
                'reason_vectors' => ['authoring_chain'],
                'ambition_level' => $ambition,
                'evidence_refs' => ['observer:authoring_governance_chain'],
                'decided_at' => ($this->clock)(),
            ]);

            return (string) ($verdict['status'] ?? 'recorded');
        } catch (Throwable) {
            return 'record_failed_open';
        }
    }

    /**
     * @param  callable():array<string,mixed>  $step
     * @return array<string,mixed>
     */
    private function safeStep(callable $step): array
    {
        try {
            return $step();
        } catch (Throwable $e) {
            return ['failed_open' => true, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $ranked
     * @param  array<string,mixed>|null  $top
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $arena
     * @return array<string,mixed>
     */
    private function envelope(string $mode, array $ranked, ?array $top, array $contract, string $recorded, string $error, array $arena = []): array
    {
        return [
            'arena' => $arena,
            'contract' => $contract,
            'error' => $error,
            'mode' => $mode,
            'ranked' => $ranked,
            'recorded' => $recorded,
            'schema' => self::SCHEMA,
            'top' => $top,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @param  array<string,mixed>|null  $top
     * @param  array<int,array<string,mixed>>  $ranked
     * @return array<string,mixed>
     */
    private function buildArena(array $candidates, ?array $top, array $ranked): array
    {
        $selectedId = $top !== null
            ? (string) ($top['candidate_id'] ?? hash('sha256', (string) json_encode($top)))
            : null;

        $accepted = is_array($ranked['accepted'] ?? null) ? array_values($ranked['accepted']) : array_values($ranked);
        $rejected = is_array($ranked['rejected'] ?? null) ? array_values($ranked['rejected']) : [];

        $rejectedIds = [];
        foreach ($rejected as $r) {
            $rid = is_array($r) ? (string) ($r['candidate_id'] ?? '') : '';
            if ($rid !== '') {
                $rejectedIds[] = $rid;
            }
        }
        foreach ($accepted as $r) {
            $rid = is_array($r) ? (string) ($r['candidate_id'] ?? '') : '';
            if ($rid !== '' && $rid !== $selectedId) {
                $rejectedIds[] = $rid;
            }
        }

        return [
            'candidates_considered' => count($candidates),
            'diversity_score' => $this->computeDiversityScore($candidates),
            'rejected_candidate_ids' => array_values(array_unique($rejectedIds)),
            'selected_candidate_id' => $selectedId,
            'selection_reason' => $selectedId !== null ? 'top_of_leverage_rank_accepted_set' : 'no_candidates_accepted',
            'template_farm_warning' => $this->detectTemplateFarm($candidates),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    private function emptyArena(array $candidates, string $reason): array
    {
        return [
            'candidates_considered' => count($candidates),
            'diversity_score' => 0.0,
            'rejected_candidate_ids' => [],
            'selected_candidate_id' => null,
            'selection_reason' => $reason,
            'template_farm_warning' => false,
        ];
    }

    /** @param  array<int,array<string,mixed>>  $candidates */
    private function computeDiversityScore(array $candidates): float
    {
        $n = count($candidates);
        if ($n <= 1) {
            return 1.0;
        }
        $scopes = array_map(static fn (array $c): string => (string) ($c['owner_scope'] ?? ''), $candidates);
        return round(count(array_unique($scopes)) / $n, 2);
    }

    /** @param  array<int,array<string,mixed>>  $candidates */
    private function detectTemplateFarm(array $candidates): bool
    {
        if (count($candidates) <= 1) {
            return false;
        }
        $scopes = array_map(static fn (array $c): string => (string) ($c['owner_scope'] ?? ''), $candidates);
        if (count(array_unique($scopes)) === 1 && $scopes[0] !== '') {
            return true;
        }
        $titles = array_map(static fn (array $c): string => (string) preg_replace('/[0-9\s]+/', '', strtolower($c['title'] ?? '')), $candidates);
        if (count(array_unique($titles)) === 1 && $titles[0] !== '') {
            return true;
        }
        $templates = array_map(static fn (array $c): string => (string) ($c['template'] ?? ''), $candidates);
        if ($templates[0] !== '' && count(array_unique($templates)) === 1) {
            return true;
        }

        return false;
    }
}
