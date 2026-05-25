<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;

final class AtlasFrontendDesignDirectionAdvisorService
{
    public const SCHEMA_VERSION = 'atlas.frontend.design_direction_advisor.v1';

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function advise(array $options): array
    {
        $task = trim((string) ($options['task'] ?? ''));
        $surface = trim((string) ($options['surface'] ?? 'programming.frontend')) ?: 'programming.frontend';
        $haystack = Str::ascii(strtolower($task.' '.$surface.' '.implode(' ', array_filter((array) ($options['hints'] ?? []), 'is_string'))));
        $signals = $this->signals($haystack, $options);
        $blockers = $task === '' ? ['task_missing'] : [];
        $directions = $blockers === [] ? $this->directions($signals) : [];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'advisor_type' => 'frontend_design_direction',
            'source' => self::class,
            'surface' => $surface,
            'task_hash' => $task !== '' ? hash('sha256', $task) : null,
            'company_profile_hash' => isset($options['company_profile_hash']) ? hash('sha256', (string) $options['company_profile_hash']) : null,
            'signals' => $signals,
            'directions' => $directions,
            'recommended_direction_id' => $directions[0]['id'] ?? null,
            'selection_contract' => [
                'minimum_direction_count' => 3,
                'selection_requires_reason' => true,
                'selected_direction_must_feed_visual_quality_gate' => true,
                'raw_prompt_or_customer_source_forbidden' => true,
            ],
            'required_next_artifacts' => [
                'selected_direction_id',
                'selection_reason',
                'acceptance_criteria',
                'frontend_asset_pack_or_reason',
                'visual_quality_report_after_build',
            ],
            'blockers' => $blockers,
        ];
        $payload['advisor_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    public function directionIds(): array
    {
        return ['operational_clarity', 'brand_product_depth', 'accessibility_first'];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,bool|string>
     */
    private function signals(string $haystack, array $options): array
    {
        $ambiguous = trim($haystack) === '' || $this->containsAny($haystack, ['bonito', 'moderno', 'melhorar visual', 'make it pop', 'premium']);

        return [
            'ambiguous_brief' => $ambiguous || (bool) ($options['ambiguous'] ?? false),
            'enterprise_or_saas' => $this->containsAny($haystack, ['saas', 'enterprise', 'empresa', 'b2b', 'dashboard']),
            'commerce_or_conversion' => $this->containsAny($haystack, ['ecommerce', 'checkout', 'product page', 'pricing', 'conversion']),
            'mobile_or_app' => $this->containsAny($haystack, ['mobile', 'app', 'onboarding', 'ios', 'android']),
            'brand_or_asset_heavy' => $this->containsAny($haystack, ['brand', 'marca', 'logo', 'hero', 'asset', 'campaign']),
            'risk_sensitive' => $this->containsAny($haystack, ['billing', 'auth', 'security', 'runtime', 'critical']),
        ];
    }

    /**
     * @param  array<string,bool|string>  $signals
     * @return array<int,array<string,mixed>>
     */
    private function directions(array $signals): array
    {
        $baseGates = ['frontend_visual_quality_gate', 'anti_ai_slop_detector', 'asset_pack_or_reason'];

        return [
            [
                'id' => 'operational_clarity',
                'label' => 'Operational Clarity',
                'best_for' => ($signals['enterprise_or_saas'] ?? false) ? 'SaaS, enterprise dashboards and repeated workflows.' : 'Dense product UI where users need speed and confidence.',
                'design_principles' => ['information hierarchy first', 'quiet surfaces', 'scan-friendly density', 'low decoration'],
                'required_gates' => array_values(array_unique([...$baseGates, 'state_transition_check', 'design_system_drift_check'])),
                'risks' => ['may feel too conservative without strong brand assets'],
            ],
            [
                'id' => 'brand_product_depth',
                'label' => 'Brand Product Depth',
                'best_for' => ($signals['brand_or_asset_heavy'] ?? false) ? 'Brand/product moments with real assets and provenance.' : 'Product pages, launches and visual storytelling with approved assets.',
                'design_principles' => ['real product signal', 'asset-led composition', 'distinct brand rhythm', 'conversion clarity'],
                'required_gates' => array_values(array_unique([...$baseGates, 'asset_provenance_check', 'performance_budget_or_reason'])),
                'risks' => ['blocked if assets are placeholders, unlicensed or low resolution'],
            ],
            [
                'id' => 'accessibility_first',
                'label' => 'Accessibility First',
                'best_for' => ($signals['mobile_or_app'] ?? false) ? 'Mobile/app flows, onboarding and forms.' : 'High-risk UI where clarity, contrast and keyboard/state paths matter most.',
                'design_principles' => ['contrast and touch targets', 'predictable navigation', 'explicit states', 'low cognitive load'],
                'required_gates' => array_values(array_unique([...$baseGates, 'a11y_check_or_reason', 'responsive_check', 'keyboard_state_paths'])),
                'risks' => ['visual novelty must not outrun comprehension'],
            ],
        ];
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, Str::ascii(strtolower((string) $needle)))) {
                return true;
            }
        }

        return false;
    }
}
