<?php

namespace App\Services\Ai\SelfImprovement\Runtime;

use App\Models\AtlasLedgerEvent;

/**
 * Filter whitelisting / normalization family extracted VERBATIM from AtlasSelfImprovementRuntime
 * (GOD-DEBULK partial split). Scanner-pinned families remain on the facade;
 * the facade keeps same-signature delegators for every method here.
 */
class FilterNormalizationSection
{
    /**
     * Keep only whitelisted scalar filters, trimmed. Int keys map to themselves;
     * string keys rename source => target (last write wins, preserving order).
     *
     * @param  array<string,string|null>  $filters
     * @param  array<int|string,string>  $keys
     * @return array<string,string>
     */
    public function normalizedWhitelistFilters(array $filters, array $keys): array
    {
        $normalized = [];
        foreach ($keys as $source => $target) {
            $value = $filters[is_int($source) ? $target : $source] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $normalized[$target] = trim((string) $value);
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    public function normalizedDimensionFilters(array $filters): array
    {
        return $this->normalizedWhitelistFilters($filters, ['domain', 'flow', 'surface_id', 'provider', 'model', 'runtime', 'tool_id']);
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    public function normalizedRepairFilters(array $filters): array
    {
        return $this->normalizedWhitelistFilters($filters, ['status', 'strategy', 'failure_domain', 'emitter_stage']);
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    public function normalizedKernelPipelineFilters(array $filters): array
    {
        return $this->normalizedWhitelistFilters($filters, ['status', 'surface_id', 'flow', 'input_mode', 'emitter_stage']);
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    public function normalizedArchitectureValidationFilters(array $filters): array
    {
        return $this->normalizedWhitelistFilters($filters, ['status', 'domain', 'surface_id', 'provider', 'flow']);
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    public function normalizedProviderPerformanceFilters(array $filters): array
    {
        return $this->normalizedWhitelistFilters($filters, ['provider' => 'provider_cli', 'provider_cli', 'domain', 'flow', 'task_type', 'specialist_profile', 'risk', 'selection_mode']);
    }

    public function knownProviderDimension(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || $value === 'unknown' ? null : $value;
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    public function normalizedInboxActionFilters(array $filters): array
    {
        return $this->normalizedWhitelistFilters($filters, ['action', 'actor_type', 'inbox_item_category', 'inbox_item_severity', 'recommended_action', 'source_type']);
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    public function normalizedDecisionReceiptFilters(array $filters): array
    {
        return $this->normalizedWhitelistFilters($filters, ['domain', 'flow', 'provider', 'model', 'risk']);
    }

    /**
     * @param  array<string,string|null>  $filters
     */
    public function matchesDecisionReceiptFilters(AtlasLedgerEvent $event, array $filters): bool
    {
        foreach ($this->normalizedDecisionReceiptFilters($filters) as $key => $value) {
            $actual = match ($key) {
                'provider' => data_get($event->payload, 'provider_selection.primary', data_get($event->payload, 'provider_selection.provider')),
                'model' => data_get($event->payload, 'provider_selection.model'),
                default => data_get($event->payload, $key),
            };

            if (! is_scalar($actual) || trim((string) $actual) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    public function normalizedProviderReleaseFilters(array $filters): array
    {
        return $this->normalizedWhitelistFilters($filters, ['provider', 'release_type', 'domain', 'recommended_action']);
    }

    public function normalizeFlow(string $flow): string
    {
        $flow = trim($flow);
        if ($flow === '') {
            return 'self_improvement.nightly_review';
        }

        if (! str_starts_with($flow, 'self_improvement.')) {
            return 'self_improvement.'.$flow;
        }

        return $flow;
    }
}
