<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\VerificationPlan;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

final class MiniProgrammingSpec implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.mini_programming_spec.v1';

    private const HASH_FIELD = 'mini_spec_hash';

    /**
     * @param  list<string>  $nonGoals
     * @param  list<array{kind:string,ref:string,reason:string}>  $canonicalContext
     * @param  list<array{description:string,observable_by:string}>  $expectedBehavior
     * @param  list<array{text:string,confidence:string}>  $assumptions
     * @param  list<string>  $expectedFiles
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $forbiddenFiles
     * @param  list<array{id:string,description:string,verification:string,verification_ref:?string}>  $acceptanceCriteria
     * @param  list<string>  $completionCriteria
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $compactSddHash,
        public readonly string $goal,
        public readonly array $nonGoals,
        public readonly array $canonicalContext,
        public readonly array $expectedBehavior,
        public readonly array $assumptions,
        public readonly array $expectedFiles,
        public readonly array $allowedFiles,
        public readonly array $forbiddenFiles,
        public readonly array $acceptanceCriteria,
        public readonly VerificationPlan $verificationPlan,
        public readonly string $rollbackOrContainment,
        public readonly array $completionCriteria,
        public readonly string $miniSpecHash,
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
            'assumptions' => array_values($this->assumptions),
            'canonical_context' => array_values($this->canonicalContext),
            'compact_sdd_hash' => $this->compactSddHash,
            'completion_criteria' => array_values($this->completionCriteria),
            'expected_behavior' => array_values($this->expectedBehavior),
            'expected_files' => array_values($this->expectedFiles),
            'forbidden_files' => array_values($this->forbiddenFiles),
            'goal' => $this->goal,
            'mini_spec_hash' => $this->miniSpecHash,
            'non_goals' => array_values($this->nonGoals),
            'provider_safe' => true,
            'rollback_or_containment' => $this->rollbackOrContainment,
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'verification_plan' => $this->verificationPlan->toCanonicalArray(),
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
        return CanonicalHasher::hashWithout($this->toCanonicalArray(), self::HASH_FIELD);
    }

    public function isProviderSafe(): bool
    {
        return true;
    }

    public function hasBlockingAssumption(): bool
    {
        foreach ($this->assumptions as $assumption) {
            $confidence = is_array($assumption) ? ($assumption['confidence'] ?? null) : null;
            if ($confidence === 'blocking') {
                return true;
            }
        }

        return false;
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            runId: (string) $payload['run_id'],
            compactSddHash: (string) $payload['compact_sdd_hash'],
            goal: (string) $payload['goal'],
            nonGoals: array_values((array) ($payload['non_goals'] ?? [])),
            canonicalContext: array_values((array) ($payload['canonical_context'] ?? [])),
            expectedBehavior: array_values((array) ($payload['expected_behavior'] ?? [])),
            assumptions: array_values((array) ($payload['assumptions'] ?? [])),
            expectedFiles: array_values((array) ($payload['expected_files'] ?? [])),
            allowedFiles: array_values((array) ($payload['allowed_files'] ?? [])),
            forbiddenFiles: array_values((array) ($payload['forbidden_files'] ?? [])),
            acceptanceCriteria: array_values((array) ($payload['acceptance_criteria'] ?? [])),
            verificationPlan: VerificationPlan::fromArray((array) $payload['verification_plan']),
            rollbackOrContainment: (string) $payload['rollback_or_containment'],
            completionCriteria: array_values((array) ($payload['completion_criteria'] ?? [])),
            miniSpecHash: (string) ($payload['mini_spec_hash'] ?? ''),
        );
    }
}
