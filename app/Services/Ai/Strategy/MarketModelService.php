<?php

namespace App\Services\Ai\Strategy;

use App\Models\AiMarketModel;
use Illuminate\Support\Str;

class MarketModelService
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @param  array<string,mixed>  $args
     */
    public function create(array $args): AiMarketModel
    {
        $title = (string) ($args['title'] ?? '');
        if ($title === '') {
            throw StrategyDomainException::missingField('market_model', 'title');
        }
        $modelId = (string) ($args['model_id'] ?? Str::slug($title));

        $assumptions = (array) ($args['assumptions'] ?? []);
        $sources = (array) ($args['sources'] ?? []);
        if ($assumptions === []) {
            throw StrategyDomainException::missingField('market_model', 'assumptions');
        }
        if ($sources === []) {
            throw StrategyDomainException::missingField('market_model', 'sources');
        }

        $tam = $this->nullableFloat($args['tam'] ?? null);
        $sam = $this->nullableFloat($args['sam'] ?? null);
        $som = $this->nullableFloat($args['som'] ?? null);
        if ($tam !== null && $sam !== null && $sam > $tam) {
            throw StrategyDomainException::invalidValue('market_model', 'sam', 'SAM cannot exceed TAM.');
        }
        if ($sam !== null && $som !== null && $som > $sam) {
            throw StrategyDomainException::invalidValue('market_model', 'som', 'SOM cannot exceed SAM.');
        }

        $confidence = $args['confidence'] ?? null;
        if ($confidence !== null) {
            $confidence = (float) $confidence;
            if ($confidence < 0.0 || $confidence > 1.0) {
                throw StrategyDomainException::invalidValue('market_model', 'confidence', 'must be between 0.0 and 1.0');
            }
        }

        $hashInput = [
            'model_id' => $modelId,
            'title' => $title,
            'tam' => $tam,
            'sam' => $sam,
            'som' => $som,
            'currency' => (string) ($args['currency'] ?? 'USD'),
            'assumptions' => $assumptions,
            'sources' => $sources,
            'confidence' => $confidence,
            'opportunity_id' => $args['opportunity_id'] ?? null,
        ];

        return AiMarketModel::query()->create([
            'uuid' => (string) Str::uuid(),
            'opportunity_id' => $args['opportunity_id'] ?? null,
            'strategy_run_id' => $args['strategy_run_id'] ?? null,
            'model_id' => $modelId,
            'title' => $title,
            'tam' => $tam,
            'sam' => $sam,
            'som' => $som,
            'currency' => (string) ($args['currency'] ?? 'USD'),
            'assumptions' => $assumptions,
            'sources' => $sources,
            'confidence' => $confidence,
            'status' => (string) ($args['status'] ?? self::STATUS_DRAFT),
            'model_hash' => StrategyCanonicalHash::sha256($hashInput),
        ]);
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
