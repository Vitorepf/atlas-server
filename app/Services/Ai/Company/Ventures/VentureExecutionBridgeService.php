<?php

namespace App\Services\Ai\Company\Ventures;

use App\Models\AiVenture;
use App\Models\AiVentureStrategyReview;
use App\Services\Ai\Mission\MissionFactoryService;
use Illuminate\Support\Str;
use Throwable;

/**
 * Execution bridge: the strategist stops at recommendations; this bridge turns
 * the latest review's gaps into DRAFT missions the operator can activate.
 *
 * Same governance pattern as OperatorInitiativeBridge:
 *  - autonomy_level = AUTONOMY_SUGGEST and status = draft (lifecycle only
 *    allows draft -> planned via an explicit operator transition, so a bridged
 *    mission can structurally never auto-execute);
 *  - proactive_origin records the review/venture/gate provenance;
 *  - idempotent per review: bridged mission ids are stored on the review and a
 *    second bridge call returns the existing ids instead of duplicating;
 *  - capped per review (config bridge_max_missions_per_review).
 */
class VentureExecutionBridgeService
{
    public function __construct(
        private readonly MissionFactoryService $missions,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function bridge(AiVenture $venture, AiVentureStrategyReview $review): array
    {
        if ($review->venture_id !== $venture->id) {
            throw VentureFoundryException::invalidValue('execution_bridge', 'review', 'review does not belong to the venture');
        }

        $existing = (array) ($review->bridged_mission_ids ?? []);
        if ($existing !== []) {
            return [
                'schema_version' => 'atlas.ai.venture.execution_bridge.v1',
                'bridge_status' => 'already_bridged',
                'review_uuid' => $review->uuid,
                'missions' => $existing,
                'created' => 0,
            ];
        }

        $gaps = array_values(array_filter((array) $review->gaps, 'is_array'));
        if ($gaps === []) {
            return [
                'schema_version' => 'atlas.ai.venture.execution_bridge.v1',
                'bridge_status' => 'no_gaps',
                'review_uuid' => $review->uuid,
                'missions' => [],
                'created' => 0,
            ];
        }

        $cap = max(1, (int) config('atlas_venture_foundry.bridge_max_missions_per_review', 3));
        $created = [];
        $errors = [];

        foreach (array_slice($gaps, 0, $cap) as $gap) {
            $gate = (string) ($gap['gate'] ?? 'gap');
            $detail = (string) ($gap['detail'] ?? '');
            $stage = (string) ($gap['stage'] ?? '');

            try {
                $mission = $this->missions->create(
                    sprintf(
                        'Venture [%s] (%s): fechar o gate [%s] para avançar ao estágio %s. %s Preparado como RASCUNHO pelo estrategista do Venture Foundry — nada executa sem sua aprovação.',
                        $venture->venture_id,
                        $venture->name,
                        $gate,
                        $stage,
                        $detail !== '' ? 'Critério: '.$detail : '',
                    ),
                    [
                        'mission_type' => MissionFactoryService::TYPE_TASK,
                        'autonomy_level' => MissionFactoryService::AUTONOMY_SUGGEST,
                        'risk_level' => MissionFactoryService::RISK_LOW,
                        'actor_type' => 'venture_execution_bridge',
                        'title' => Str::limit(sprintf('Venture %s: %s', $venture->venture_id, $gate), 110),
                        'context_summary' => Str::limit(sprintf('[%s] %s', $stage, $detail), 480),
                        'primary_domain' => 'strategy',
                        'criteria' => [$detail !== '' ? $detail : sprintf('Gate [%s] verde na próxima strategist-review.', $gate)],
                    ],
                );

                $mission->forceFill([
                    'proactive_origin' => [
                        'schema_version' => 'atlas.ai.venture.execution_bridge.v1',
                        'review_id' => (string) $review->id,
                        'review_uuid' => (string) $review->uuid,
                        'venture_id' => (string) $venture->venture_id,
                        'stage' => $stage,
                        'gate' => $gate,
                    ],
                    'proactive_status' => 'proposed',
                ])->save();

                $created[] = [
                    'mission_id' => (string) $mission->id,
                    'mission_uuid' => (string) $mission->uuid,
                    'gate' => $gate,
                    'status' => (string) $mission->status,
                    'autonomy_level' => (string) $mission->autonomy_level,
                ];
            } catch (Throwable $e) {
                $errors[] = ['gate' => $gate, 'detail' => $e->getMessage()];
            }
        }

        if ($created !== []) {
            $review->bridged_mission_ids = array_map(fn (array $row) => $row['mission_id'], $created);
            $review->save();
        }

        return [
            'schema_version' => 'atlas.ai.venture.execution_bridge.v1',
            'bridge_status' => $created !== [] ? 'bridged' : 'failed',
            'review_uuid' => $review->uuid,
            'missions' => $created,
            'created' => count($created),
            'skipped_over_cap' => max(0, count($gaps) - $cap),
            'errors' => $errors,
        ];
    }

    /**
     * Bridge the most recent review of a venture.
     *
     * @return array<string,mixed>
     */
    public function bridgeLatest(AiVenture $venture): array
    {
        $review = AiVentureStrategyReview::query()
            ->where('venture_id', $venture->id)
            ->orderByDesc('created_at')
            ->first();

        if ($review === null) {
            throw VentureFoundryException::notFound('strategy_review', 'latest for venture '.$venture->venture_id);
        }

        return $this->bridge($venture, $review);
    }
}
