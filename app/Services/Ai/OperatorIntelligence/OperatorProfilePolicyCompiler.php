<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Models\OperatorProfileItem;
use App\Models\OperatorProfilePolicyRule;
use App\Services\Ai\OperatorIntelligence\Support\OperatorProfilePolicyCompilerSupport;

class OperatorProfilePolicyCompiler
{
    public function compileItem(OperatorProfileItem $item): OperatorProfilePolicyRule
    {
        $value = (array) ($item->value ?? []);
        $effect = OperatorProfilePolicyCompilerSupport::effect(
            $value,
            (string) $item->taxonomy_item_id,
            (string) $item->profile_key,
            (string) $item->summary,
        );
        $ruleKey = $item->profile_key.'.'.$effect;

        return OperatorProfilePolicyRule::query()->updateOrCreate(
            [
                'operator_profile_item_id' => $item->id,
                'rule_key' => $ruleKey,
            ],
            [
                'rule' => [
                    'schema_version' => 'atlas.operator_profile_policy_rule.v1',
                    'profile_item_id' => $item->id,
                    'taxonomy_item_id' => $item->taxonomy_item_id,
                    'summary' => $item->summary,
                    'value' => $value,
                    'privacy_class' => $item->privacy_class,
                    'automation_level' => $item->automation_level,
                    'provider_safe' => $item->privacy_class === 'normal',
                    'reversible' => in_array($item->automation_level, ['observe', 'suggest', 'auto_apply_reversible'], true),
                ],
                'applies_to_flow' => is_string($value['applies_to_flow'] ?? null) ? $value['applies_to_flow'] : null,
                'priority' => OperatorProfilePolicyCompilerSupport::priority($effect, (float) $item->confidence),
                'effect' => $effect,
                'reverse_handle' => 'operator_profile_item:'.$item->id,
            ],
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function compileOperator(string $operatorId): array
    {
        return OperatorProfileItem::query()
            ->where('operator_id', $operatorId)
            ->active()
            ->get()
            ->map(fn (OperatorProfileItem $item): array => $this->compileItem($item)->toArray())
            ->all();
    }
}
