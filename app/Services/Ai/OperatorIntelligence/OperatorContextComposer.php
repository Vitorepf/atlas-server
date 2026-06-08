<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Models\OperatorProfileItem;
use Illuminate\Support\Carbon;

class OperatorContextComposer
{
    public function __construct(
        private readonly OperatorProfileRegistry $registry,
        private readonly OperatorProfileFeedbackService $feedback,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compose(array $input): array
    {
        $operatorId = (string) ($input['operator_id'] ?? config('atlas_operator_intelligence.default_operator_id', 'default'));
        $max = max(1, min(50, (int) ($input['limit'] ?? config('atlas_operator_intelligence.max_injected_profile_items', 8))));
        $providerExternal = (bool) ($input['provider_external'] ?? false);
        $allowedPrivacy = $providerExternal
            ? (array) config('atlas_operator_intelligence.provider_safe_privacy_classes', ['normal'])
            : (array) config('atlas_operator_intelligence.internal_privacy_classes', ['normal', 'private']);
        $flow = is_string($input['flow'] ?? null) ? $input['flow'] : null;
        $recordUsage = (bool) ($input['record_usage'] ?? true);

        $items = $this->rank($this->registry->activeForOperator($operatorId), $flow);
        $included = [];
        $omitted = [];

        foreach ($items as $item) {
            if (! in_array($item->privacy_class, $allowedPrivacy, true)) {
                $omitted[] = $this->omitted($item, 'privacy_class_not_allowed');
                continue;
            }

            if (count($included) >= $max) {
                $omitted[] = $this->omitted($item, 'context_budget_exhausted');
                continue;
            }

            $included[] = $this->contextItem($item, $flow);
            if ($recordUsage) {
                $item->forceFill(['last_applied_at' => Carbon::now()])->save();
                $this->feedback->record($item, 'context_injected', null, [
                    'flow' => $flow,
                    'provider_external' => $providerExternal,
                    'trace_id' => is_string($input['trace_id'] ?? null) ? $input['trace_id'] : null,
                    'session_id' => is_string($input['session_id'] ?? null) ? $input['session_id'] : null,
                ]);
            }
        }

        return [
            'schema_version' => 'atlas.operator_context.v1',
            'operator_id' => $operatorId,
            'provider_external' => $providerExternal,
            'flow' => $flow,
            'max_items' => $max,
            'items' => $included,
            'rules' => array_values(array_filter(array_map(static fn (array $item): ?array => $item['rule'] ?? null, $included))),
            'omitted' => $omitted,
            'safety' => [
                'raw_private_context_included' => false,
                'allowed_privacy_classes' => $allowedPrivacy,
                'secret_blocked' => true,
            ],
        ];
    }

    /**
     * @param  array<int,OperatorProfileItem>  $items
     * @return array<int,OperatorProfileItem>
     */
    private function rank(array $items, ?string $flow): array
    {
        usort($items, function (OperatorProfileItem $a, OperatorProfileItem $b) use ($flow): int {
            $aFlow = $this->flowScore($a, $flow);
            $bFlow = $this->flowScore($b, $flow);

            return [$bFlow, $b->confidence, $b->updated_at?->getTimestamp() ?? 0]
                <=> [$aFlow, $a->confidence, $a->updated_at?->getTimestamp() ?? 0];
        });

        return $items;
    }

    private function flowScore(OperatorProfileItem $item, ?string $flow): int
    {
        if ($flow === null) {
            return 0;
        }

        foreach ($item->policyRules as $rule) {
            if ($rule->applies_to_flow === $flow) {
                return 2;
            }
        }

        return 0;
    }

    /**
     * @return array<string,mixed>
     */
    private function contextItem(OperatorProfileItem $item, ?string $flow): array
    {
        $rule = $item->policyRules
            ->sortByDesc('priority')
            ->first(fn ($rule): bool => $rule->applies_to_flow === null || $rule->applies_to_flow === $flow);

        return [
            'id' => $item->id,
            'taxonomy_item_id' => $item->taxonomy_item_id,
            'profile_key' => $item->profile_key,
            'summary' => $item->summary,
            'effect' => $rule?->effect,
            'confidence' => $item->confidence,
            'privacy_class' => $item->privacy_class,
            'automation_level' => $item->automation_level,
            'reason' => 'active_operator_profile_match',
            'rule' => $rule ? [
                'rule_key' => $rule->rule_key,
                'effect' => $rule->effect,
                'priority' => $rule->priority,
                'applies_to_flow' => $rule->applies_to_flow,
                'provider_safe' => (bool) data_get($rule->rule, 'provider_safe', false),
            ] : null,
        ];
    }

    /**
     * @return array<string,string|null>
     */
    private function omitted(OperatorProfileItem $item, string $reason): array
    {
        return [
            'id' => $item->id,
            'profile_key' => $item->profile_key,
            'privacy_class' => $item->privacy_class,
            'reason' => $reason,
        ];
    }
}
