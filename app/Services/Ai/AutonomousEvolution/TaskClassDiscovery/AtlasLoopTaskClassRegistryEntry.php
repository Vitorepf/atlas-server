<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\TaskClassDiscovery;

/**
 * Immutable entry in the AtlasLoopTaskClassRegistry. Append-only — supersede() creates a new entry
 * with version+1, never mutates a prior one.
 */
final readonly class AtlasLoopTaskClassRegistryEntry
{
    /**
     * @param  array<string,mixed>  $shapeRules
     * @param  list<string>  $expectedAcceptanceCriteriaTemplate
     * @param  list<string>  $defaultRequiredEvidenceIds
     * @param  list<string>  $defaultAllowedFilesGlobs
     */
    public function __construct(
        public string $classId,
        public array $shapeRules,
        public array $expectedAcceptanceCriteriaTemplate,
        public array $defaultRequiredEvidenceIds,
        public array $defaultAllowedFilesGlobs,
        public string $approvedByOperatorToken,
        public int $approvedAtUnix,
        public string $sourceClusterFingerprint,
        public int $version,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'approved_at_unix' => $this->approvedAtUnix,
            'approved_by_operator_token' => $this->approvedByOperatorToken,
            'class_id' => $this->classId,
            'default_allowed_files_globs' => $this->defaultAllowedFilesGlobs,
            'default_required_evidence_ids' => $this->defaultRequiredEvidenceIds,
            'expected_acceptance_criteria_template' => $this->expectedAcceptanceCriteriaTemplate,
            'shape_rules' => $this->shapeRules,
            'source_cluster_fingerprint' => $this->sourceClusterFingerprint,
            'version' => $this->version,
        ];
    }
}
