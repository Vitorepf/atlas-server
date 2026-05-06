<?php

namespace App\Services\Ai\Kernel\Decision;

class DynamicComputeMarketReportService
{
    public function __construct(
        private readonly DynamicComputeMarketAdvisor $advisor,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function report(array $input): array
    {
        $selectedProvider = $this->requiredScalar($input, 'provider');
        $selectedModel = $this->optionalScalar($input, 'model');
        $domain = $this->optionalScalar($input, 'domain');
        $flow = $this->optionalScalar($input, 'flow');
        $taskType = $this->optionalScalar($input, 'task_type');
        $specialistProfile = $this->optionalScalar($input, 'specialist_profile');

        $policy = array_filter([
            'domain' => $domain,
            'flow' => $flow,
            'profile_id' => $flow,
            'profile_context' => array_filter([
                'domain' => $domain,
                'flow' => $flow,
            ], fn (?string $value): bool => $value !== null),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
        $taskProfile = array_filter([
            'task_type' => $taskType,
        ], fn (?string $value): bool => $value !== null);
        $market = $this->advisor->advise(
            selectedProvider: $selectedProvider,
            selectedModel: $selectedModel,
            policy: $policy,
            taskProfile: $taskProfile,
            specialistProfile: $specialistProfile,
        );

        return [
            'schema_version' => 'atlas.dynamic_compute_market_report.v1',
            'status' => (bool) data_get($market, 'ap99.available', false) ? 'ok' : 'ledger_unavailable',
            'mode' => 'report_only',
            'authority' => 'read_only_no_routing_change',
            'input' => [
                'provider' => $selectedProvider,
                'model' => $selectedModel,
                'domain' => $domain,
                'flow' => $flow,
                'task_type' => $taskType,
                'specialist_profile' => $specialistProfile,
            ],
            'dynamic_compute_market' => $market,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function requiredScalar(array $input, string $key): string
    {
        $value = $this->optionalScalar($input, $key);

        if ($value === null) {
            throw new \InvalidArgumentException("{$key} is required.");
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function optionalScalar(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
