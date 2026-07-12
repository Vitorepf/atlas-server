<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Models\AiLearningCandidate;
use App\Models\AiRagFeedbackEvent;
use App\Models\AiRunOutcome;
use App\Models\AtlasMemoryEntryUsage;
use App\Services\Ai\Context\AtlasContextFeedbackSignalPolicy;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * MAXJ-07 — Co-recall composition detector.
 *
 * Pairs of memory entries co-delivered in the same recall audit that also join
 * to a passing *measured* RAG feedback event become composition candidates when
 * co_case_count ≥ floor (default 8). Frontier may author the claim later
 * (ASI-09); this service never auto-promotes — enqueue lands held in the
 * same ASI-02 queue with promotion_allowed=false.
 */
final class AtlasCoRecallCompositionDetector
{
    public const SCHEMA_VERSION = 'atlas.ai.co_recall_composition.v1';

    public const DEFAULT_FLOOR = 8;

    public const HARD_FLOOR_MIN = 3;

    public function __construct(
        private readonly AtlasContextFeedbackSignalPolicy $signalPolicy = new AtlasContextFeedbackSignalPolicy,
    ) {}

    /**
     * @param  list<array{session_key:string,memory_a:string,memory_b:string,outcome_ref?:string}>  $passingCases
     * @return array<string,mixed>
     */
    public function detectFromCases(array $passingCases, ?int $floor = null): array
    {
        $effectiveFloor = max(self::HARD_FLOOR_MIN, (int) ($floor ?? self::DEFAULT_FLOOR));
        $groups = [];

        foreach ($passingCases as $case) {
            $a = trim((string) ($case['memory_a'] ?? ''));
            $b = trim((string) ($case['memory_b'] ?? ''));
            $session = trim((string) ($case['session_key'] ?? ''));
            if ($a === '' || $b === '' || $session === '' || $a === $b) {
                continue;
            }
            if ($a > $b) {
                [$a, $b] = [$b, $a];
            }
            $key = $a.'|'.$b;
            $groups[$key] ??= [
                'memory_a_id' => $a,
                'memory_b_id' => $b,
                'sessions' => [],
                'outcome_refs' => [],
            ];
            $groups[$key]['sessions'][$session] = true;
            $ref = trim((string) ($case['outcome_ref'] ?? ''));
            if ($ref !== '') {
                $groups[$key]['outcome_refs'][$ref] = true;
            }
        }

        $proposals = [];
        foreach ($groups as $group) {
            $coCaseCount = count($group['sessions']);
            if ($coCaseCount < $effectiveFloor) {
                continue;
            }
            $outcomeRefs = array_keys($group['outcome_refs']);
            sort($outcomeRefs);
            $proposalHash = hash('sha256', implode('|', [
                self::SCHEMA_VERSION,
                $group['memory_a_id'],
                $group['memory_b_id'],
                (string) $coCaseCount,
            ]));
            $proposals[] = [
                'memory_a_id' => $group['memory_a_id'],
                'memory_b_id' => $group['memory_b_id'],
                'co_case_count' => $coCaseCount,
                'outcome_refs' => $outcomeRefs,
                'proposal_hash' => $proposalHash,
                'claim' => sprintf(
                    'Composed insight from co-recalled memories %s + %s (co_case_count=%d).',
                    $group['memory_a_id'],
                    $group['memory_b_id'],
                    $coCaseCount,
                ),
            ];
        }

        usort($proposals, static fn (array $x, array $y): int => strcmp((string) $x['proposal_hash'], (string) $y['proposal_hash']));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $proposals !== [] ? 'ok' : 'insufficient_signal',
            'generated_at' => Carbon::now()->toIso8601String(),
            'floor' => $effectiveFloor,
            'default_floor' => self::DEFAULT_FLOOR,
            'hard_floor_min' => self::HARD_FLOOR_MIN,
            'totals' => [
                'passing_cases' => count($passingCases),
                'pairs' => count($groups),
                'proposals' => count($proposals),
            ],
            'proposals' => $proposals,
            'claim_policy' => [
                'read_only' => true,
                'memory_written' => false,
                'auto_promotion_allowed' => false,
                'shadow_first' => true,
                'admission_door' => 'ASI-02',
            ],
        ];
    }

    /**
     * Live scan — fail-open when tables/signal missing.
     *
     * @return array<string,mixed>
     */
    public function detect(?int $floor = null): array
    {
        $effectiveFloor = max(self::HARD_FLOOR_MIN, (int) ($floor ?? (int) config('atlas.ai.co_recall_composition.floor', self::DEFAULT_FLOOR)));

        if (! DatabaseTableAvailability::all(['atlas_memory_entry_usages', 'ai_rag_feedback_events'])) {
            return $this->emptyReport($effectiveFloor, 'table_missing');
        }

        $passingFlowIds = [];
        /** @var list<AiRagFeedbackEvent> $events */
        $events = AiRagFeedbackEvent::query()->orderBy('created_at')->limit(2000)->get()->all();
        foreach ($events as $event) {
            if (! $this->signalPolicy->isMeasuredAggregateEligible($event)) {
                continue;
            }
            $outcome = strtolower(trim((string) ($event->outcome_status ?? '')));
            if ($outcome !== '' && ! in_array($outcome, ['pass', 'passed', 'success', 'ok', 'green'], true)) {
                continue;
            }
            $flowId = trim((string) ($event->flow_id ?? ''));
            if ($flowId !== '') {
                $passingFlowIds[$flowId] = (string) $event->id;
            }
        }

        if ($passingFlowIds === []) {
            return $this->emptyReport($effectiveFloor, 'insufficient_signal');
        }

        $usages = AtlasMemoryEntryUsage::query()
            ->whereIn('source_type', ['memory_recall', 'recalled_pre_filter'])
            ->whereNotNull('source_id')
            ->orderBy('source_id')
            ->orderBy('memory_entry_id')
            ->limit(5000)
            ->get(['memory_entry_id', 'source_id', 'session_id', 'trace_id']);

        $bySession = [];
        foreach ($usages as $usage) {
            $session = trim((string) ($usage->source_id ?? ''));
            if ($session === '') {
                continue;
            }
            // Prefer linking via session/trace contained in flow_id equality when present.
            $flowHint = trim((string) ($usage->session_id ?? $usage->trace_id ?? ''));
            $matchedFlow = null;
            if ($flowHint !== '' && isset($passingFlowIds[$flowHint])) {
                $matchedFlow = $flowHint;
            } elseif (isset($passingFlowIds[$session])) {
                $matchedFlow = $session;
            }
            if ($matchedFlow === null) {
                continue;
            }
            $bySession[$session]['flow'] = $matchedFlow;
            $bySession[$session]['memories'][(string) $usage->memory_entry_id] = true;
            $bySession[$session]['outcome_ref'] = 'rag_feedback:'.$passingFlowIds[$matchedFlow];
        }

        $cases = [];
        foreach ($bySession as $session => $bucket) {
            $ids = array_keys($bucket['memories'] ?? []);
            sort($ids);
            $n = count($ids);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $cases[] = [
                        'session_key' => (string) $session,
                        'memory_a' => $ids[$i],
                        'memory_b' => $ids[$j],
                        'outcome_ref' => (string) ($bucket['outcome_ref'] ?? ''),
                    ];
                }
            }
        }

        return $this->detectFromCases($cases, $effectiveFloor);
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @return array<string,mixed>
     */
    public function enqueueHeld(array $proposal): array
    {
        if (! (bool) config('atlas.ai.co_recall_composition.enqueue_enabled', false)) {
            return ['created' => false, 'reason' => 'enqueue_disabled'];
        }
        if (! DatabaseTableAvailability::has('ai_learning_candidates')) {
            return ['created' => false, 'reason' => 'table_missing'];
        }

        $candidateHash = 'maxj07:'.(string) ($proposal['proposal_hash'] ?? '');
        if ($candidateHash === 'maxj07:') {
            return ['created' => false, 'reason' => 'missing_proposal_hash'];
        }

        $runOutcomeId = null;
        if (DatabaseTableAvailability::has('ai_run_outcomes')) {
            $runOutcomeId = AiRunOutcome::query()->orderByDesc('created_at')->value('id');
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'memory_a_id' => $proposal['memory_a_id'] ?? null,
            'memory_b_id' => $proposal['memory_b_id'] ?? null,
            'co_case_count' => $proposal['co_case_count'] ?? null,
            'proposal_hash' => $proposal['proposal_hash'] ?? null,
            'source' => [
                'slice' => 'MAXJ-07',
                'author_engine' => 'co_recall_composition_detector',
                'frontier_promotes' => false,
                'admission_door' => 'ASI-02',
            ],
        ];

        $row = AiLearningCandidate::query()->firstOrCreate(
            ['candidate_hash' => $candidateHash],
            [
                'schema_version' => AtlasLearningDistiller::SCHEMA_VERSION,
                'run_outcome_id' => $runOutcomeId ?? (string) Str::uuid(),
                'status' => 'held_for_evidence',
                'decision' => 'hold',
                'memory_type' => 'compounding_memory',
                'scope' => 'global',
                'claim' => (string) ($proposal['claim'] ?? 'composed co-recall insight'),
                'confidence' => 40,
                'promotion_allowed' => false,
                'evidence_refs' => array_values((array) ($proposal['outcome_refs'] ?? [])),
                'payload' => $payload,
                'receipt_hash' => hash('sha256', 'maxj07-'.$candidateHash),
                'decided_at' => Carbon::now(),
            ],
        );

        return [
            'created' => $row->wasRecentlyCreated,
            'candidate_id' => (string) $row->id,
            'candidate_hash' => $candidateHash,
            'promotion_allowed' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyReport(int $floor, string $status): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'floor' => $floor,
            'default_floor' => self::DEFAULT_FLOOR,
            'hard_floor_min' => self::HARD_FLOOR_MIN,
            'totals' => [
                'passing_cases' => 0,
                'pairs' => 0,
                'proposals' => 0,
            ],
            'proposals' => [],
            'claim_policy' => [
                'read_only' => true,
                'memory_written' => false,
                'auto_promotion_allowed' => false,
                'shadow_first' => true,
                'admission_door' => 'ASI-02',
            ],
        ];
    }
}
