<?php

namespace App\Services\Ai\VentureFoundry;

use App\Models\AiVenture;
use App\Models\AiVentureIdea;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use Illuminate\Support\Str;

/**
 * Durable registry of companies managed by the Venture Foundry.
 *
 * A venture is the long-lived entity that survives across strategy runs:
 * it starts from a promoted idea, accumulates business rules, metric
 * observations and strategy reviews, and walks the growth ladder toward the
 * declared target ARR (default 100M USD).
 */
class VentureRegistryService
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_CLOSED = 'closed';

    private const ALLOWED_TRANSITIONS = [
        self::STATUS_ACTIVE => [self::STATUS_PAUSED, self::STATUS_CLOSED],
        self::STATUS_PAUSED => [self::STATUS_ACTIVE, self::STATUS_CLOSED],
        self::STATUS_CLOSED => [],
    ];

    /**
     * Promote an idea into a managed venture. The idea must not be promoted
     * twice; the venture starts at stage S0 (ideation).
     *
     * @param  array<string,mixed>  $args
     */
    public function promoteIdea(AiVentureIdea $idea, array $args = []): AiVenture
    {
        if ($idea->status === VentureIdeationService::STATUS_PROMOTED) {
            throw VentureFoundryException::invalidValue('venture', 'idea', "idea [{$idea->idea_id}] already promoted");
        }
        if ($idea->status === VentureIdeationService::STATUS_REJECTED) {
            throw VentureFoundryException::invalidValue('venture', 'idea', "idea [{$idea->idea_id}] was rejected");
        }

        $name = trim((string) ($args['name'] ?? $idea->title));
        if ($name === '') {
            throw VentureFoundryException::missingField('venture', 'name');
        }

        $thesis = trim((string) ($args['thesis'] ?? ''));
        if ($thesis === '') {
            $thesis = sprintf(
                'Resolver [%s] para [%s] atacando a dor [%s].',
                $idea->problem,
                $idea->icp,
                $idea->pain,
            );
        }

        $ventureId = (string) ($args['venture_id'] ?? Str::slug($name));
        if (AiVenture::query()->where('venture_id', $ventureId)->exists()) {
            throw VentureFoundryException::invalidValue('venture', 'venture_id', "[{$ventureId}] already registered");
        }

        $targetArr = isset($args['target_arr_usd']) ? (float) $args['target_arr_usd'] : 100_000_000.0;
        if ($targetArr <= 0) {
            throw VentureFoundryException::invalidValue('venture', 'target_arr_usd', 'must be > 0');
        }

        $hashInput = [
            'venture_id' => $ventureId,
            'name' => $name,
            'thesis' => $thesis,
            'idea_id' => $idea->id,
            'target_arr_usd' => $targetArr,
        ];

        $venture = AiVenture::query()->create([
            'uuid' => (string) Str::uuid(),
            'venture_id' => $ventureId,
            'name' => $name,
            'thesis' => $thesis,
            'status' => self::STATUS_ACTIVE,
            'stage' => VentureGrowthLadderService::STAGE_IDEATION,
            'stage_key' => VentureGrowthLadderService::stageKey(VentureGrowthLadderService::STAGE_IDEATION),
            'idea_id' => $idea->id,
            'opportunity_id' => $idea->opportunity_id,
            'north_star' => $args['north_star'] ?? null,
            'founding_inputs' => [
                'idea_id' => $idea->idea_id,
                'idea_score' => $idea->score,
                'source' => $idea->source,
            ],
            'target_arr_usd' => $targetArr,
            'venture_hash' => StrategyCanonicalHash::sha256($hashInput),
        ]);

        $idea->status = VentureIdeationService::STATUS_PROMOTED;
        $idea->promoted_venture_id = $venture->id;
        $idea->save();

        return $venture;
    }

    public function transition(AiVenture $venture, string $toStatus): AiVenture
    {
        $allowed = self::ALLOWED_TRANSITIONS[$venture->status] ?? [];
        if (! in_array($toStatus, $allowed, true)) {
            throw VentureFoundryException::invalidTransition('venture', (string) $venture->status, $toStatus);
        }

        $venture->status = $toStatus;
        $venture->save();

        return $venture;
    }

    /**
     * Attach strategy artifacts (opportunity, blueprint, north star) to a
     * venture. References must exist in the strategy tables.
     *
     * @param  array<string,mixed>  $args
     */
    public function attachArtifacts(AiVenture $venture, array $args): AiVenture
    {
        if (array_key_exists('opportunity_id', $args) && $args['opportunity_id'] !== null) {
            $opportunityId = (string) $args['opportunity_id'];
            if (! \App\Models\AiOpportunity::query()->whereKey($opportunityId)->exists()) {
                throw VentureFoundryException::notFound('opportunity', $opportunityId);
            }
            $venture->opportunity_id = $opportunityId;
        }

        if (array_key_exists('venture_blueprint_id', $args) && $args['venture_blueprint_id'] !== null) {
            $blueprintId = (string) $args['venture_blueprint_id'];
            if (! \App\Models\AiVentureBlueprint::query()->whereKey($blueprintId)->exists()) {
                throw VentureFoundryException::notFound('venture_blueprint', $blueprintId);
            }
            $venture->venture_blueprint_id = $blueprintId;
        }

        if (array_key_exists('north_star', $args) && $args['north_star'] !== null) {
            $northStar = (array) $args['north_star'];
            if (! isset($northStar['metric']) || trim((string) $northStar['metric']) === '') {
                throw VentureFoundryException::missingField('venture', 'north_star.metric');
            }
            $venture->north_star = $northStar;
        }

        $venture->save();

        return $venture;
    }

    /**
     * Resolve a venture by uuid, venture_id slug or primary id.
     */
    public function resolve(string $reference): AiVenture
    {
        $venture = AiVenture::query()
            ->where(function ($query) use ($reference): void {
                $query->where('uuid', $reference)->orWhere('venture_id', $reference);
                if (Str::isUuid($reference)) {
                    $query->orWhere('id', $reference);
                }
            })
            ->first();

        if ($venture === null) {
            throw VentureFoundryException::notFound('venture', $reference);
        }

        return $venture;
    }
}
