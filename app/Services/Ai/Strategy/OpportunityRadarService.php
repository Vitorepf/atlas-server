<?php

namespace App\Services\Ai\Strategy;

use App\Models\AiOpportunity;
use Illuminate\Support\Str;

class OpportunityRadarService
{
    public const URGENCY_LOW = 'low';

    public const URGENCY_MEDIUM = 'medium';

    public const URGENCY_HIGH = 'high';

    public const URGENCY_CRITICAL = 'critical';

    public const ALLOWED_URGENCY = [
        self::URGENCY_LOW,
        self::URGENCY_MEDIUM,
        self::URGENCY_HIGH,
        self::URGENCY_CRITICAL,
    ];

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * Persist an opportunity with all required strategy fields. Enforces:
     * problem, ICP, pain, urgency, market, competitors, risks.
     *
     * @param  array<string,mixed>  $args
     */
    public function create(array $args): AiOpportunity
    {
        $title = (string) ($args['title'] ?? '');
        if ($title === '') {
            throw StrategyDomainException::missingField('opportunity', 'title');
        }

        $required = ['problem', 'icp', 'pain'];
        foreach ($required as $field) {
            $value = $args[$field] ?? '';
            if (! is_string($value) || trim($value) === '') {
                throw StrategyDomainException::missingField('opportunity', $field);
            }
        }

        $urgency = (string) ($args['urgency'] ?? self::URGENCY_MEDIUM);
        if (! in_array($urgency, self::ALLOWED_URGENCY, true)) {
            throw StrategyDomainException::invalidValue('opportunity', 'urgency', 'must be one of ['.implode(',', self::ALLOWED_URGENCY).']');
        }

        $market = (array) ($args['market'] ?? []);
        $competitors = (array) ($args['competitors'] ?? []);
        $risks = (array) ($args['risks'] ?? []);
        if ($market === []) {
            throw StrategyDomainException::missingField('opportunity', 'market');
        }
        if ($competitors === []) {
            throw StrategyDomainException::missingField('opportunity', 'competitors');
        }
        if ($risks === []) {
            throw StrategyDomainException::missingField('opportunity', 'risks');
        }

        $confidence = $args['confidence'] ?? null;
        if ($confidence !== null) {
            $confidence = (float) $confidence;
            if ($confidence < 0.0 || $confidence > 1.0) {
                throw StrategyDomainException::invalidValue('opportunity', 'confidence', 'must be between 0.0 and 1.0');
            }
        }

        $opportunityId = (string) ($args['opportunity_id'] ?? Str::slug($title));

        $hashInput = [
            'opportunity_id' => $opportunityId,
            'title' => $title,
            'problem' => $args['problem'],
            'icp' => $args['icp'],
            'pain' => $args['pain'],
            'urgency' => $urgency,
            'market' => $market,
            'competitors' => $competitors,
            'risks' => $risks,
            'confidence' => $confidence,
            'mission_id' => $args['mission_id'] ?? null,
            'strategy_run_id' => $args['strategy_run_id'] ?? null,
        ];

        return AiOpportunity::query()->create([
            'uuid' => (string) Str::uuid(),
            'strategy_run_id' => $args['strategy_run_id'] ?? null,
            'mission_id' => $args['mission_id'] ?? null,
            'opportunity_id' => $opportunityId,
            'title' => $title,
            'problem' => (string) $args['problem'],
            'icp' => (string) $args['icp'],
            'pain' => (string) $args['pain'],
            'urgency' => $urgency,
            'market' => $market,
            'competitors' => $competitors,
            'risks' => $risks,
            'confidence' => $confidence,
            'status' => (string) ($args['status'] ?? self::STATUS_PROPOSED),
            'opportunity_hash' => StrategyCanonicalHash::sha256($hashInput),
        ]);
    }
}
