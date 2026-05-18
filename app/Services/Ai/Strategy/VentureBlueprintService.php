<?php

namespace App\Services\Ai\Strategy;

use App\Models\AiVentureBlueprint;
use Illuminate\Support\Str;

class VentureBlueprintService
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_REJECTED = 'rejected';

    /**
     * Persist a venture blueprint. Enforces required: product, GTM,
     * unit economics, hiring/ops.
     *
     * @param  array<string,mixed>  $args
     */
    public function create(array $args): AiVentureBlueprint
    {
        $title = (string) ($args['title'] ?? '');
        if ($title === '') {
            throw StrategyDomainException::missingField('venture_blueprint', 'title');
        }
        $opportunityId = (string) ($args['opportunity_id'] ?? '');
        if ($opportunityId === '') {
            throw StrategyDomainException::missingField('venture_blueprint', 'opportunity_id');
        }

        $blueprintId = (string) ($args['blueprint_id'] ?? Str::slug($title));

        $product = (array) ($args['product'] ?? []);
        $gtm = (array) ($args['gtm'] ?? []);
        $unitEconomics = (array) ($args['unit_economics'] ?? []);
        $hiring = (array) ($args['hiring_plan'] ?? []);
        $ops = (array) ($args['operations'] ?? []);

        foreach ([
            'product' => $product,
            'gtm' => $gtm,
            'unit_economics' => $unitEconomics,
            'hiring_plan' => $hiring,
            'operations' => $ops,
        ] as $field => $value) {
            if ($value === []) {
                throw StrategyDomainException::missingField('venture_blueprint', $field);
            }
        }

        $hashInput = [
            'blueprint_id' => $blueprintId,
            'opportunity_id' => $opportunityId,
            'title' => $title,
            'product' => $product,
            'gtm' => $gtm,
            'unit_economics' => $unitEconomics,
            'hiring_plan' => $hiring,
            'operations' => $ops,
            'milestones' => $args['milestones'] ?? null,
            'risks' => $args['risks'] ?? null,
        ];

        return AiVentureBlueprint::query()->create([
            'uuid' => (string) Str::uuid(),
            'opportunity_id' => $opportunityId,
            'strategy_run_id' => $args['strategy_run_id'] ?? null,
            'blueprint_id' => $blueprintId,
            'title' => $title,
            'product' => $product,
            'gtm' => $gtm,
            'unit_economics' => $unitEconomics,
            'hiring_plan' => $hiring,
            'operations' => $ops,
            'milestones' => $args['milestones'] ?? null,
            'risks' => $args['risks'] ?? null,
            'status' => (string) ($args['status'] ?? self::STATUS_DRAFT),
            'blueprint_hash' => StrategyCanonicalHash::sha256($hashInput),
        ]);
    }
}
