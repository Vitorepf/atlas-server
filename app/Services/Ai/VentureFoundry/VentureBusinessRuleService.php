<?php

namespace App\Services\Ai\VentureFoundry;

use App\Models\AiVenture;
use App\Models\AiVentureBusinessRule;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use Illuminate\Support\Str;

/**
 * Versioned business-rule canon per venture.
 *
 * Each rule has a stable rule_id; re-declaring the same rule_id creates a new
 * version and marks the previous one superseded, so the active rule set is
 * always auditable and history is never lost.
 */
class VentureBusinessRuleService
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_RETIRED = 'retired';

    public const ALLOWED_CATEGORIES = [
        'product',
        'pricing',
        'customer',
        'operations',
        'finance',
        'legal',
        'brand',
        'people',
        'risk',
    ];

    /**
     * Declare (or re-version) a business rule for a venture.
     *
     * @param  array<string,mixed>  $args
     */
    public function declare(AiVenture $venture, array $args): AiVentureBusinessRule
    {
        $statement = trim((string) ($args['statement'] ?? ''));
        if ($statement === '') {
            throw VentureFoundryException::missingField('business_rule', 'statement');
        }

        $category = (string) ($args['category'] ?? '');
        if (! in_array($category, self::ALLOWED_CATEGORIES, true)) {
            throw VentureFoundryException::invalidValue('business_rule', 'category', 'must be one of ['.implode(',', self::ALLOWED_CATEGORIES).']');
        }

        $ruleId = (string) ($args['rule_id'] ?? Str::slug(Str::limit($statement, 60, '')));
        if ($ruleId === '') {
            throw VentureFoundryException::missingField('business_rule', 'rule_id');
        }

        $previous = AiVentureBusinessRule::query()
            ->where('venture_id', $venture->id)
            ->where('rule_id', $ruleId)
            ->where('status', self::STATUS_ACTIVE)
            ->orderByDesc('version')
            ->first();

        $version = $previous !== null ? ((int) $previous->version) + 1 : 1;

        $hashInput = [
            'venture_id' => $venture->id,
            'rule_id' => $ruleId,
            'category' => $category,
            'statement' => $statement,
            'rationale' => $args['rationale'] ?? null,
            'version' => $version,
        ];

        $rule = AiVentureBusinessRule::query()->create([
            'uuid' => (string) Str::uuid(),
            'venture_id' => $venture->id,
            'rule_id' => $ruleId,
            'category' => $category,
            'statement' => $statement,
            'rationale' => $args['rationale'] ?? null,
            'version' => $version,
            'status' => self::STATUS_ACTIVE,
            'rule_hash' => StrategyCanonicalHash::sha256($hashInput),
        ]);

        if ($previous !== null) {
            $previous->status = self::STATUS_SUPERSEDED;
            $previous->superseded_by = $rule->id;
            $previous->save();
        }

        return $rule;
    }

    public function retire(AiVentureBusinessRule $rule): AiVentureBusinessRule
    {
        if ($rule->status !== self::STATUS_ACTIVE) {
            throw VentureFoundryException::invalidTransition('business_rule', (string) $rule->status, self::STATUS_RETIRED);
        }

        $rule->status = self::STATUS_RETIRED;
        $rule->save();

        return $rule;
    }

    /**
     * @return array<int,AiVentureBusinessRule>
     */
    public function activeRules(AiVenture $venture): array
    {
        return AiVentureBusinessRule::query()
            ->where('venture_id', $venture->id)
            ->where('status', self::STATUS_ACTIVE)
            ->orderBy('category')
            ->orderBy('rule_id')
            ->get()
            ->all();
    }
}
