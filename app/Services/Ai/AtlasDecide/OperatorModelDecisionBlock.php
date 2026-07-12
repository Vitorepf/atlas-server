<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

final class OperatorModelDecisionBlock
{
    public const SCHEMA_VERSION = 'atlas.decide.operator_model.v1';

    /**
     * @param  list<array<string,mixed>>  $routes
     * @param  list<array<string,mixed>>  $rules
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function apply(array $routes, array $rules, array $context = []): array
    {
        [$policies, $omitted] = self::providerSafePolicies($rules, ($context['provider_external'] ?? false) === true);
        $eligible = array_values($routes);
        $excluded = [];

        foreach ($policies as $policy) {
            if (($policy['effect'] ?? '') !== 'do_not_do') {
                continue;
            }
            $forbiddenTool = (string) data_get($policy, 'rule.value.tool', '');
            if ($forbiddenTool === '') {
                continue;
            }
            $eligible = array_values(array_filter($eligible, function (array $route) use ($forbiddenTool, &$excluded): bool {
                if ((string) ($route['tool'] ?? '') === $forbiddenTool) {
                    $excluded[] = (string) ($route['id'] ?? '');

                    return false;
                }

                return true;
            }));
        }

        $recommended = $eligible[0] ?? [];
        $basis = 'score_order';
        foreach ($policies as $policy) {
            if (($policy['effect'] ?? '') !== 'tool_preference') {
                continue;
            }
            $preferredTool = (string) data_get($policy, 'rule.value.tool', '');
            foreach ($eligible as $route) {
                if ((string) ($route['tool'] ?? '') === $preferredTool) {
                    $recommended = $route;
                    $basis = 'tool_preference';
                    break 2;
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'policies' => $policies,
            'omitted' => $omitted,
            'eligible_routes' => $eligible,
            'excluded_route_ids' => array_values(array_filter($excluded)),
            'recommended_route_id' => (string) ($recommended['id'] ?? ''),
            'basis' => $basis,
            'source' => [
                'derived_from_compiled_policy_rules' => true,
                'caller_supplied_policy_allowed' => false,
                'overrides_area18_floors' => false,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rules
     * @return array{0:list<array<string,mixed>>,1:list<string>}
     */
    private static function providerSafePolicies(array $rules, bool $providerExternal): array
    {
        $policies = [];
        $omitted = [];
        foreach ($rules as $rule) {
            $privacyClass = (string) data_get($rule, 'rule.privacy_class', 'normal');
            $providerSafe = (bool) data_get($rule, 'rule.provider_safe', $privacyClass === 'normal');
            if ($providerExternal && (! $providerSafe || $privacyClass !== 'normal')) {
                $omitted[] = 'private_policy_omitted';
                continue;
            }
            $policies[] = $rule;
        }

        return [$policies, array_values(array_unique($omitted))];
    }
}
