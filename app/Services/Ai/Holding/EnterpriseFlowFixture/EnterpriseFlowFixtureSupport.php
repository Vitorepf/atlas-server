<?php

namespace App\Services\Ai\Holding\EnterpriseFlowFixture;

/**
 * Pure leaf helpers extracted from EnterpriseFlowFixtureActionRuntimeService (GOD-DEBULK split).
 * Stateless static functions over the company packet array; no instance state.
 */
class EnterpriseFlowFixtureSupport
{
    /**
     * @param  string|array{0:string,1:string}  $definition  string = bool-bound record key
     */
    public static function recordPredicate(string|array $definition): \Closure
    {
        if (is_string($definition)) {
            return static fn (array $record): bool => (bool) ($record[$definition] ?? false);
        }

        [$type, $key] = $definition;

        return match ($type) {
            'bound' => static fn (array $record): bool => (bool) ($record[$key] ?? false),
            'hash' => static fn (array $record): bool => (string) ($record[$key] ?? '') !== '',
            'count' => static fn (array $record): bool => (int) ($record[$key] ?? 0) > 0,
            'internal' => static fn (array $record): bool => (bool) ($record[$key] ?? true) === false,
        };
    }

    /**
     * @param  array<string,mixed>  $company
     * @return array<string,mixed>|null
     */
    public static function actionContractFromCompany(array $company, string $action): ?array
    {
        foreach ((array) data_get($company, 'enterprise_flow_action_runtime_stack.runtime_action_catalog', []) as $contract) {
            if (($contract['action'] ?? null) === $action) {
                return (array) $contract;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $company
     * @return array<string,mixed>
     */
    public static function findByFlow(array $company, string $path, string $flowId): array
    {
        foreach ((array) data_get($company, $path, []) as $item) {
            if (($item['flow_id'] ?? null) === $flowId) {
                return (array) $item;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $company
     * @param  list<string>  $connectorIds
     * @return list<array<string,mixed>>
     */
    public static function connectorRowsByIds(array $company, string $path, array $connectorIds): array
    {
        $wanted = array_values(array_unique(array_filter(array_map('strval', $connectorIds))));

        return array_values(array_filter(
            array_map(
                static fn (array $row): array => $row,
                (array) data_get($company, $path, []),
            ),
            static fn (array $row): bool => in_array((string) ($row['connector_id'] ?? ''), $wanted, true),
        ));
    }

    /**
     * @param  array<string,mixed>  $company
     * @param  list<mixed>  $connectorIds
     * @return list<array<string,mixed>>
     */
    public static function verticalConnectorWorkbenches(array $company, array $connectorIds): array
    {
        $wanted = array_values(array_map('strval', $connectorIds));

        return array_values(array_filter(
            array_map(
                static fn (array $workbench): array => $workbench,
                (array) data_get($company, 'enterprise_vertical_solution_suite_stack.connector_solution_workbenches', []),
            ),
            static fn (array $workbench): bool => in_array((string) ($workbench['connector_id'] ?? ''), $wanted, true),
        ));
    }

    /**
     * @param  array<string,mixed>  $company
     * @return array<string,mixed>
     */
    public static function artifactFactoryForWorkProduct(array $company, string $workProduct): array
    {
        foreach ((array) data_get($company, 'enterprise_vertical_solution_suite_stack.artifact_factory_catalog', []) as $factory) {
            if (($factory['work_product'] ?? null) === $workProduct) {
                return (array) $factory;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $company
     * @return array<string,mixed>
     */
    public static function businessArtifactContractForWorkProduct(array $company, string $workProduct): array
    {
        foreach ((array) data_get($company, 'enterprise_domain_business_execution_mesh_stack.business_artifact_delivery_contracts', []) as $contract) {
            if (($contract['work_product'] ?? null) === $workProduct) {
                return (array) $contract;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $company
     * @return array<string,mixed>
     */
    public static function qualityContractForWorkProduct(array $company, string $workProduct): array
    {
        $aliases = [
            'architecture_decision_record' => ['architecture', 'decision', 'adr'],
            'patch_plan' => ['patch', 'plan'],
            'repair_packet' => ['repair', 'triage', 'failure'],
            'release_certification' => ['release', 'certification'],
            'security_review_report' => ['security', 'review'],
        ];

        foreach ((array) data_get($company, 'deliverable_quality_contracts', []) as $contract) {
            $contractWorkProduct = (string) ($contract['work_product'] ?? '');
            if ($contractWorkProduct === $workProduct
                || ($contractWorkProduct !== '' && str_contains($workProduct, $contractWorkProduct))
                || ($workProduct !== '' && str_contains($contractWorkProduct, $workProduct))) {
                return (array) $contract;
            }
        }

        foreach ((array) data_get($company, 'deliverable_quality_contracts', []) as $contract) {
            $contractWorkProduct = (string) ($contract['work_product'] ?? '');
            foreach ($aliases[$contractWorkProduct] ?? [] as $alias) {
                if (str_contains($workProduct, $alias)) {
                    return (array) $contract;
                }
            }
        }

        foreach ((array) data_get($company, 'deliverable_quality_contracts', []) as $contract) {
            if (count((array) ($contract['required_sections'] ?? [])) >= 7
                && count((array) ($contract['acceptance_criteria'] ?? [])) >= 5
                && count((array) ($contract['rejection_criteria'] ?? [])) >= 4) {
                return (array) $contract;
            }
        }

        return [];
    }
}
