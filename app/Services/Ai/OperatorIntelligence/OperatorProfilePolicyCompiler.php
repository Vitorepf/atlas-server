<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Models\OperatorProfileItem;
use App\Models\OperatorProfilePolicyRule;

class OperatorProfilePolicyCompiler
{
    public function compileItem(OperatorProfileItem $item): OperatorProfilePolicyRule
    {
        $value = (array) ($item->value ?? []);
        $effect = $this->effect($item);
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
                'priority' => $this->priority($effect, $item),
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

    private function effect(OperatorProfileItem $item): string
    {
        $value = (array) ($item->value ?? []);
        $effect = is_string($value['effect'] ?? null) ? $value['effect'] : null;
        if ($effect !== null && in_array($effect, OperatorProfilePolicyRule::EFFECTS, true)) {
            return $effect;
        }

        if (str_starts_with($item->taxonomy_item_id, 'COL-')) {
            return 'response_style';
        }
        if (str_contains($item->profile_key, 'boundary') || str_contains(strtolower($item->summary), 'nao mexa')) {
            return 'do_not_do';
        }

        return 'context_hint';
    }

    private function priority(string $effect, OperatorProfileItem $item): int
    {
        $base = match ($effect) {
            'do_not_do' => 95,
            'approval_gate', 'autonomy_limit' => 90,
            'workflow_preference', 'tool_preference', 'handoff_preference' => 70,
            'response_style' => 60,
            default => 50,
        };

        return min(100, $base + (int) round(((float) $item->confidence) * 5));
    }
}
