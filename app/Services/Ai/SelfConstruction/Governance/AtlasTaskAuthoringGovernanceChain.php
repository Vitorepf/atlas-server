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
            return $this->envelope($mode, [], null, [], 'skipped', '');
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

            return $this->envelope($mode, $ranked, $top, $contract, $recorded, '');
        } catch (Throwable $e) {
            return $this->envelope($mode, [], null, [], 'failed_open', $e->getMessage());
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
     * @return array<string,mixed>
     */
    private function envelope(string $mode, array $ranked, ?array $top, array $contract, string $recorded, string $error): array
    {
        return [
            'contract' => $contract,
            'error' => $error,
            'mode' => $mode,
            'ranked' => $ranked,
            'recorded' => $recorded,
            'schema' => self::SCHEMA,
            'top' => $top,
        ];
    }
}
