<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

final class PromptSections implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.prompt_sections.v1';

    /**
     * @param  list<string>  $operatingRules
     * @param  list<string>  $contextRefs  refs (hash + path), not raw text
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $forbiddenFiles
     * @param  list<string>  $expectedTests
     * @param  list<string>  $acceptanceCriteria
     * @param  list<string>  $stopConditions
     * @param  list<string>  $escalationConditions
     * @param  list<string>  $outputContract
     * @param  list<string>  $nonGoals  scope-bounding clauses derived from MiniSpec.non_goals;
     *                                  mandatory non-empty for any write-capable LightTaskContract.
     * @param  list<string>  $knownFailureModes  area-scoped, provider-safe, deduped failure
     *                                           capsule entries (M5 compounding memory). Empty by
     *                                           default so a foreign/empty area yields a
     *                                           byte-identical baseline projection.
     * @param  list<string>  $definitionOfDone  E2 Definition of Done bullet lines sourced from
     *                                          MiniProgrammingSpec.expectedBehavior[] +
     *                                          completionCriteria[]. Empty by default so an
     *                                          empty source (or atlas_dev.elevations.e2.mode=off)
     *                                          yields a byte-identical baseline projection
     *                                          (conditional-empty pattern, mirrors
     *                                          $knownFailureModes).
     */
    public function __construct(
        public readonly string $objective,
        public readonly array $operatingRules,
        public readonly string $miniSpecRef,
        public readonly string $taskContractRef,
        public readonly array $contextRefs,
        public readonly string $codeDiscoveryRef,
        public readonly array $allowedFiles,
        public readonly array $forbiddenFiles,
        public readonly array $expectedTests,
        public readonly array $acceptanceCriteria,
        public readonly array $stopConditions,
        public readonly array $escalationConditions,
        public readonly array $outputContract,
        public readonly bool $providerSafe = true,
        public readonly array $nonGoals = [],
        public readonly array $knownFailureModes = [],
        public readonly array $definitionOfDone = [],
    ) {}

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'acceptance_criteria' => array_values($this->acceptanceCriteria),
            'allowed_files' => array_values($this->allowedFiles),
            'code_discovery_ref' => $this->codeDiscoveryRef,
            'context_refs' => array_values($this->contextRefs),
            'definition_of_done' => array_values($this->definitionOfDone),
            'escalation_conditions' => array_values($this->escalationConditions),
            'expected_tests' => array_values($this->expectedTests),
            'forbidden_files' => array_values($this->forbiddenFiles),
            'known_failure_modes' => array_values($this->knownFailureModes),
            'mini_spec_ref' => $this->miniSpecRef,
            'non_goals' => array_values($this->nonGoals),
            'objective' => $this->objective,
            'operating_rules' => array_values($this->operatingRules),
            'output_contract' => array_values($this->outputContract),
            'stop_conditions' => array_values($this->stopConditions),
            'task_contract_ref' => $this->taskContractRef,
        ]);
    }

    public function toProviderSafeArray(): array
    {
        return $this->toCanonicalArray();
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toCanonicalArray());
    }

    public function hash(): string
    {
        return CanonicalHasher::hash($this->toCanonicalArray());
    }

    public function isProviderSafe(): bool
    {
        return $this->providerSafe;
    }

    public function hasFileRuleConflict(): bool
    {
        $intersection = array_intersect($this->allowedFiles, $this->forbiddenFiles);

        return $intersection !== [];
    }

    public function hasAllRequiredSections(): bool
    {
        return $this->objective !== ''
            && $this->miniSpecRef !== ''
            && $this->taskContractRef !== ''
            && $this->codeDiscoveryRef !== ''
            && $this->outputContract !== [];
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            objective: AtlasDevSchemaArray::string($payload, 'objective'),
            operatingRules: AtlasDevSchemaArray::stringList($payload, 'operating_rules'),
            miniSpecRef: AtlasDevSchemaArray::string($payload, 'mini_spec_ref'),
            taskContractRef: AtlasDevSchemaArray::string($payload, 'task_contract_ref'),
            contextRefs: AtlasDevSchemaArray::stringList($payload, 'context_refs'),
            codeDiscoveryRef: AtlasDevSchemaArray::string($payload, 'code_discovery_ref'),
            allowedFiles: AtlasDevSchemaArray::stringList($payload, 'allowed_files'),
            forbiddenFiles: AtlasDevSchemaArray::stringList($payload, 'forbidden_files'),
            expectedTests: AtlasDevSchemaArray::stringList($payload, 'expected_tests'),
            acceptanceCriteria: AtlasDevSchemaArray::stringList($payload, 'acceptance_criteria'),
            stopConditions: AtlasDevSchemaArray::stringList($payload, 'stop_conditions'),
            escalationConditions: AtlasDevSchemaArray::stringList($payload, 'escalation_conditions'),
            outputContract: AtlasDevSchemaArray::stringList($payload, 'output_contract'),
            providerSafe: array_key_exists('provider_safe', $payload)
                ? AtlasDevSchemaArray::bool($payload, 'provider_safe')
                : true,
            nonGoals: array_key_exists('non_goals', $payload)
                ? AtlasDevSchemaArray::stringList($payload, 'non_goals')
                : [],
            knownFailureModes: array_key_exists('known_failure_modes', $payload)
                ? AtlasDevSchemaArray::stringList($payload, 'known_failure_modes')
                : [],
        );
    }
}
