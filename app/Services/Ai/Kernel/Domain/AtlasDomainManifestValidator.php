<?php

namespace App\Services\Ai\Kernel\Domain;

class AtlasDomainManifestValidator
{
    public function __construct(
        private readonly AtlasDomainOrchestratorRegistry $orchestrators,
    ) {}

    /**
     * @param  array<string,mixed>  $catalog
     * @return array{ok:bool,errors:array<int,string>,warnings:array<int,string>,domains:int,flows:int}
     */
    public function validateCatalog(array $catalog): array
    {
        $errors = [];
        $warnings = [];

        $domains = $this->indexedList($catalog['domains'] ?? [], 'id', 'domain', $errors);
        $flows = $this->indexedList($catalog['flows'] ?? [], 'id', 'flow', $errors);

        foreach ($domains as $domainId => $domain) {
            $this->validateRequiredString($domain, 'label', "domain {$domainId}", $errors);
            $this->validateRequiredString($domain, 'status', "domain {$domainId}", $errors);
            $this->validateRequiredString($domain, 'default_flow', "domain {$domainId}", $errors);
            $this->validateRequiredString($domain, 'orchestrator', "domain {$domainId}", $errors);
            $this->validateRequiredString($domain, 'runtime_family', "domain {$domainId}", $errors);
            $this->validateAutonomy((string) ($domain['autonomy_default'] ?? ''), "domain {$domainId}.autonomy_default", $errors);
            $this->validateOrchestrator((string) ($domain['orchestrator'] ?? ''), $domainId, null, "domain {$domainId}", $errors);

            $defaultFlow = (string) ($domain['default_flow'] ?? '');
            if ($defaultFlow !== '' && ! isset($flows[$defaultFlow])) {
                $errors[] = "domain {$domainId} default_flow {$defaultFlow} is not declared as an active flow";
            }
        }

        foreach ($flows as $flowId => $flow) {
            $domainId = (string) ($flow['domain_id'] ?? '');

            $this->validateRequiredString($flow, 'domain_id', "flow {$flowId}", $errors);
            $this->validateRequiredString($flow, 'label', "flow {$flowId}", $errors);
            $this->validateRequiredString($flow, 'status', "flow {$flowId}", $errors);
            $this->validateRequiredString($flow, 'orchestrator', "flow {$flowId}", $errors);
            $this->validateRequiredString($flow, 'runtime', "flow {$flowId}", $errors);
            $this->validateAutonomy((string) ($flow['autonomy'] ?? ''), "flow {$flowId}.autonomy", $errors);
            $this->validateOrchestrator((string) ($flow['orchestrator'] ?? ''), $domainId, $flowId, "flow {$flowId}", $errors);

            if ($domainId !== '' && ! isset($domains[$domainId])) {
                $errors[] = "flow {$flowId} references unknown domain {$domainId}";
            }

            if ($domainId !== '' && ! str_starts_with($flowId, $domainId.'.')) {
                $warnings[] = "flow {$flowId} does not use the canonical {$domainId}.<flow> id prefix";
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'domains' => count($domains),
            'flows' => count($flows),
        ];
    }

    /**
     * @param  array<int,string>  $errors
     */
    private function validateOrchestrator(string $orchestratorId, string $domainId, ?string $flowId, string $scope, array &$errors): void
    {
        $orchestratorId = trim($orchestratorId);
        if ($orchestratorId === '') {
            return;
        }

        $definition = $this->orchestrators->get($orchestratorId);
        if ($definition === null) {
            $errors[] = "{$scope} references unknown orchestrator {$orchestratorId}";

            return;
        }

        $class = (string) ($definition['class'] ?? '');
        if ($class === '' || ! class_exists($class)) {
            $errors[] = "{$scope} orchestrator {$orchestratorId} class {$class} does not exist";

            return;
        }

        if (! is_subclass_of($class, AtlasDomainOrchestrator::class)) {
            $errors[] = "{$scope} orchestrator {$orchestratorId} must implement ".AtlasDomainOrchestrator::class;

            return;
        }

        /** @var AtlasDomainOrchestrator $instance */
        $instance = app($class);
        if (! in_array($domainId, $instance->supportedDomains(), true)) {
            $errors[] = "{$scope} orchestrator {$orchestratorId} does not declare support for domain {$domainId}";
        }

        if ($flowId !== null && ! in_array($flowId, $instance->supportedFlows(), true)) {
            $errors[] = "{$scope} orchestrator {$orchestratorId} does not declare support for flow {$flowId}";
        }
    }

    /**
     * @param  mixed  $items
     * @param  array<int,string>  $errors
     * @return array<string,array<string,mixed>>
     */
    private function indexedList(mixed $items, string $key, string $label, array &$errors): array
    {
        if (! is_array($items)) {
            $errors[] = "{$label} list must be an array";

            return [];
        }

        $indexed = [];

        foreach ($items as $offset => $item) {
            if (! is_array($item)) {
                $errors[] = "{$label} at offset {$offset} must be an object";

                continue;
            }

            $id = trim((string) ($item[$key] ?? ''));
            if ($id === '') {
                $errors[] = "{$label} at offset {$offset} is missing {$key}";

                continue;
            }

            if (isset($indexed[$id])) {
                $errors[] = "duplicate {$label} id {$id}";

                continue;
            }

            if (($item['status'] ?? 'active') !== 'active') {
                continue;
            }

            $indexed[$id] = $item;
        }

        return $indexed;
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<int,string>  $errors
     */
    private function validateRequiredString(array $item, string $key, string $scope, array &$errors): void
    {
        if (trim((string) ($item[$key] ?? '')) === '') {
            $errors[] = "{$scope} is missing {$key}";
        }
    }

    /**
     * @param  array<int,string>  $errors
     */
    private function validateAutonomy(string $value, string $scope, array &$errors): void
    {
        if (! in_array($value, ['none', 'low', 'medium', 'high', 'critical'], true)) {
            $errors[] = "{$scope} must be one of none, low, medium, high, critical";
        }
    }
}
