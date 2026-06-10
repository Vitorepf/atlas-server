<?php

namespace App\Services\Ai\VentureFoundry;

use App\Models\AiVenture;
use App\Models\AiVentureStrategyReview;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use App\Services\Ai\Strategy\StrategyMemoService;
use Illuminate\Support\Str;
use Throwable;

/**
 * The standing strategist of the Venture Foundry.
 *
 * A strategist review evaluates the venture against the growth ladder,
 * persists an auditable review record, emits a strategy memo (strategy
 * domain) with decision + next actions, and only changes the venture stage
 * when explicitly asked to apply — recommendation and promotion are separate
 * acts, so the sector never self-claims progress.
 */
class VentureStrategistService
{
    public function __construct(
        private readonly VentureGrowthLadderService $ladder,
        private readonly StrategyMemoService $memos,
        private readonly VentureTrajectoryService $trajectory,
        private readonly VentureStrategistAnalysisService $analysis,
        private readonly VentureBusinessRuleService $rules,
    ) {}

    /**
     * Run a strategist review for a venture. With $analyze the qualitative
     * provider opinion is added (explicit spend); its failure never blocks
     * the deterministic review.
     *
     * @return array<string,mixed>
     */
    public function review(AiVenture $venture, bool $applyStage = false, bool $analyze = false): array
    {
        $evaluation = $this->ladder->evaluate($venture);
        $trajectory = $this->trajectory->project($venture, (array) $evaluation['metrics']);

        $analysis = null;
        if ($analyze) {
            try {
                $analysis = $this->analysis->analyze($venture, $evaluation, $trajectory, $this->rules->activeRules($venture));
            } catch (Throwable $e) {
                $analysis = [
                    'schema_version' => 'atlas.ai.venture.strategist_analysis.v1',
                    'analysis_status' => VentureStrategistAnalysisService::STATUS_PROVIDER_FAILED,
                    'detail' => $e->getMessage(),
                    'strategic_moves' => [],
                ];
            }
        }

        $recommended = (string) $evaluation['recommended_stage'];
        $current = (string) $venture->stage;

        $nextActions = $evaluation['next_actions'] !== []
            ? $evaluation['next_actions']
            : ['Manter operação, defender a posição e revisar a estratégia no próximo ciclo.'];

        $decision = $recommended === $current
            ? sprintf('Manter venture [%s] no estágio %s.', $venture->venture_id, $current)
            : sprintf('Recomendar movimento da venture [%s] de %s para %s.', $venture->venture_id, $current, $recommended);

        $memoId = null;
        $memoError = null;
        try {
            $memo = $this->memos->create([
                'memo_kind' => StrategyMemoService::KIND_VENTURE,
                'title' => sprintf('Strategist review — %s (%s)', $venture->name, $venture->venture_id),
                'decision' => $decision,
                'rationale' => [
                    'recommended_stage' => $recommended,
                    'current_stage' => $current,
                    'observed_arr_usd' => $evaluation['observed_arr_usd'],
                    'target_arr_usd' => $evaluation['target_arr_usd'],
                    'gaps' => $evaluation['gaps'],
                    'trajectory_status' => $trajectory['status'],
                ],
                'evidence_refs' => [
                    ['kind' => 'venture', 'id' => $venture->id, 'hash' => $venture->venture_hash],
                ],
                'next_actions' => $nextActions,
                'status' => StrategyMemoService::STATUS_DECIDED,
            ]);
            $memoId = $memo->id;
        } catch (Throwable $e) {
            $memoError = $e->getMessage();
        }

        $stageApplied = false;
        if ($applyStage && $recommended !== $current) {
            $venture->stage = $recommended;
            $venture->stage_key = VentureGrowthLadderService::stageKey($recommended);
            $venture->save();
            $stageApplied = true;
        }

        $uuid = (string) Str::uuid();
        $review = AiVentureStrategyReview::query()->create([
            'uuid' => $uuid,
            'venture_id' => $venture->id,
            'review_kind' => 'stage_review',
            'current_stage' => $current,
            'recommended_stage' => $recommended,
            'stage_applied' => $stageApplied,
            'gate_results' => $evaluation['stages'],
            'gaps' => $evaluation['gaps'],
            'next_actions' => $nextActions,
            'playbook' => $evaluation['playbook'],
            'trajectory' => $trajectory,
            'analysis' => $analysis,
            'strategy_memo_id' => $memoId,
            'status' => 'closed',
            'review_hash' => StrategyCanonicalHash::sha256([
                'uuid' => $uuid,
                'venture_id' => $venture->id,
                'current_stage' => $current,
                'recommended_stage' => $recommended,
                'stage_applied' => $stageApplied,
                'gaps' => $evaluation['gaps'],
            ]),
        ]);

        return [
            'review' => $review,
            'evaluation' => $evaluation,
            'trajectory' => $trajectory,
            'analysis' => $analysis,
            'decision' => $decision,
            'stage_applied' => $stageApplied,
            'strategy_memo_id' => $memoId,
            'strategy_memo_error' => $memoError,
            'venture' => $venture->refresh(),
        ];
    }

    /**
     * Weekly cadence: review every active venture whose latest review is
     * older than the configured interval. Never applies stage; analysis is
     * config-gated (cycle_analyze) unless explicitly overridden.
     *
     * @return array<string,mixed>
     */
    public function reviewCycle(?bool $analyze = null): array
    {
        $analyze ??= (bool) config('atlas_venture_foundry.cycle_analyze', false);
        $minIntervalDays = max(0, (int) config('atlas_venture_foundry.cycle_min_interval_days', 6));
        $threshold = \Illuminate\Support\Carbon::now()->subDays($minIntervalDays);

        $reviewed = [];
        $skipped = [];

        $ventures = AiVenture::query()
            ->where('status', VentureRegistryService::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->get();

        foreach ($ventures as $venture) {
            $latest = AiVentureStrategyReview::query()
                ->where('venture_id', $venture->id)
                ->orderByDesc('created_at')
                ->first();

            if ($latest !== null && $latest->created_at !== null && $latest->created_at->greaterThan($threshold)) {
                $skipped[] = ['venture_id' => $venture->venture_id, 'reason' => 'recent_review', 'last_review_at' => $latest->created_at->toIso8601String()];

                continue;
            }

            $packet = $this->review($venture, false, $analyze);
            $reviewed[] = [
                'venture_id' => $venture->venture_id,
                'current_stage' => $packet['venture']->stage,
                'recommended_stage' => $packet['evaluation']['recommended_stage'],
                'gaps' => count($packet['evaluation']['gaps']),
                'trajectory_status' => $packet['trajectory']['status'],
                'analysis_status' => $packet['analysis']['analysis_status'] ?? 'not_requested',
                'review_uuid' => $packet['review']->uuid,
            ];
        }

        return [
            'schema_version' => 'atlas.ai.venture.review_cycle.v1',
            'analyze' => $analyze,
            'active_ventures' => $ventures->count(),
            'reviewed' => $reviewed,
            'skipped' => $skipped,
        ];
    }
}
