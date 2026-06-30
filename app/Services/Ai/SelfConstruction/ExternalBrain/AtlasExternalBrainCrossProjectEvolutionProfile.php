<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Portable evolution profile for the Atlas external-brain architecture.
 *
 * A profile captures EVERYTHING the brain needs to run 24/7 for a project
 * without copy-paste prompts: identity, autonomy ceiling, evidence contract,
 * task lanes, source-of-truth docs, forbidden/property-gated targets, and
 * delivery ledger policy.
 *
 * Safety contract:
 *   autonomy_level is capped at AUTONOMY_READONLY_PLANNING until BOTH
 *   source_of_truth_docs AND allowed_targets are explicitly provided.
 *
 * Two factory entrypoints:
 *   - {@see self::forAtlas()}: canonical Atlas-server profile (full autonomy).
 *   - {@see self::forProject()}: any other project; safe defaults, opt-in upgrade.
 */
final class AtlasExternalBrainCrossProjectEvolutionProfile
{
    public const SCHEMA = 'atlas.external_brain.cross_project_evolution_profile.v1';

    public const AUTONOMY_READONLY_PLANNING = 'readonly_planning';

    public const AUTONOMY_SUPERVISED = 'supervised';

    public const AUTONOMY_FULL_AUTONOMOUS = 'full_autonomous';

    private function __construct(private readonly array $profile) {}

    public static function forAtlas(): self
    {
        return new self([
            'project_id' => 'atlas-server',
            'project_name' => 'Atlas Server',
            'is_canonical_atlas' => true,
            'autonomy_level' => self::AUTONOMY_FULL_AUTONOMOUS,
            'required_evidence_fields' => ['tests_or_gates_result', 'implementation_notes'],
            'task_lanes' => ['feature', 'bug-fix', 'refactor', 'test', 'doc'],
            'source_of_truth_docs' => ['docs/engineering-knowledge-base/'],
            'allowed_targets' => ['app/', 'tests/'],
            'forbidden_targets' => ['.env', '.env.bak', 'storage/'],
            'property_gated_targets' => ['config/app.php', 'config/database.php'],
            'ledger_policy' => [
                'min_evidence_refs' => 1,
                'forbidden_fields' => ['raw_prompt', 'provider_trace', 'conversation_text', 'secret'],
            ],
            'proof_gates' => ['phpunit_green', 'atlas_cert_gate', 'merge_governor_approved'],
            'maturity_risk' => ['level' => 'low', 'factors' => ['proven_test_suite', 'cert_gate_enforced']],
            'lane_boundaries' => [
                'feature'  => ['allowed_scope_prefixes' => ['app/', 'tests/'], 'proof_gate' => 'phpunit_green'],
                'bug-fix'  => ['allowed_scope_prefixes' => ['app/', 'tests/'], 'proof_gate' => 'phpunit_green'],
                'refactor' => ['allowed_scope_prefixes' => ['app/', 'tests/'], 'proof_gate' => 'phpunit_green'],
                'test'     => ['allowed_scope_prefixes' => ['tests/'], 'proof_gate' => 'phpunit_green'],
                'doc'      => ['allowed_scope_prefixes' => ['docs/'], 'proof_gate' => 'doc_review_pass'],
            ],
        ]);
    }

    /**
     * Build a profile for any non-Atlas project.
     *
     * @param  array<string,mixed>  $config
     */
    public static function forProject(string $projectId, array $config = []): self
    {
        $sourceOfTruthDocs = array_values(array_filter((array) ($config['source_of_truth_docs'] ?? []), 'is_string'));
        $allowedTargets = array_values(array_filter((array) ($config['allowed_targets'] ?? []), 'is_string'));

        // Autonomy gate: both source-of-truth docs AND an explicit allowed-target policy
        // are required before elevating past readonly_planning.
        $requestedLevel = (string) ($config['autonomy_level'] ?? self::AUTONOMY_READONLY_PLANNING);
        $resolvedLevel = ($sourceOfTruthDocs !== [] && $allowedTargets !== [])
            ? $requestedLevel
            : self::AUTONOMY_READONLY_PLANNING;

        $defaultLedger = [
            'min_evidence_refs' => 1,
            'forbidden_fields' => ['raw_prompt', 'provider_trace', 'conversation_text', 'secret'],
        ];

        $taskLanes = array_values(array_filter(
            (array) ($config['task_lanes'] ?? ['feature']),
            'is_string',
        ));

        $proofGates = array_values(array_filter((array) ($config['proof_gates'] ?? ['tests_green']), 'is_string'));
        $maturityRisk = is_array($config['maturity_risk'] ?? null)
            ? $config['maturity_risk']
            : ['level' => 'high', 'factors' => ['external_codebase_requires_manual_review']];

        // Build default lane_boundaries from task_lanes + allowed_targets when caller doesn't provide them.
        $laneBoundaries = is_array($config['lane_boundaries'] ?? null) ? $config['lane_boundaries'] : [];
        if ($laneBoundaries === []) {
            $defaultGate = $proofGates[0] ?? 'tests_green';
            foreach ($taskLanes as $lane) {
                $laneBoundaries[$lane] = ['allowed_scope_prefixes' => $allowedTargets, 'proof_gate' => $defaultGate];
            }
        }

        return new self([
            'project_id' => $projectId,
            'project_name' => (string) ($config['project_name'] ?? $projectId),
            'is_canonical_atlas' => false,
            'autonomy_level' => $resolvedLevel,
            'required_evidence_fields' => array_values(array_filter(
                (array) ($config['required_evidence_fields'] ?? ['tests_or_gates_result']),
                'is_string',
            )),
            'task_lanes' => $taskLanes,
            'source_of_truth_docs' => $sourceOfTruthDocs,
            'allowed_targets' => $allowedTargets,
            'forbidden_targets' => array_values(array_filter((array) ($config['forbidden_targets'] ?? []), 'is_string')),
            'property_gated_targets' => array_values(array_filter((array) ($config['property_gated_targets'] ?? []), 'is_string')),
            'ledger_policy' => is_array($config['ledger_policy'] ?? null) ? $config['ledger_policy'] : $defaultLedger,
            'proof_gates' => $proofGates,
            'maturity_risk' => $maturityRisk,
            'lane_boundaries' => $laneBoundaries,
        ]);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_merge(['schema' => self::SCHEMA], $this->profile);
    }

    public function projectId(): string
    {
        return $this->profile['project_id'];
    }

    public function autonomyLevel(): string
    {
        return $this->profile['autonomy_level'];
    }

    public function isReadonlyPlanning(): bool
    {
        return $this->profile['autonomy_level'] === self::AUTONOMY_READONLY_PLANNING;
    }

    public function isCanonicalAtlas(): bool
    {
        return (bool) ($this->profile['is_canonical_atlas'] ?? false);
    }

    /** @return list<string> */
    public function requiredEvidenceFields(): array
    {
        return $this->profile['required_evidence_fields'];
    }

    /** @return list<string> */
    public function taskLanes(): array
    {
        return $this->profile['task_lanes'];
    }

    /** @return list<string> */
    public function sourceOfTruthDocs(): array
    {
        return $this->profile['source_of_truth_docs'];
    }

    /** @return list<string> */
    public function allowedTargets(): array
    {
        return $this->profile['allowed_targets'];
    }

    /** @return list<string> */
    public function forbiddenTargets(): array
    {
        return $this->profile['forbidden_targets'];
    }

    /** @return list<string> */
    public function propertyGatedTargets(): array
    {
        return $this->profile['property_gated_targets'];
    }

    /** @return array<string,mixed> */
    public function ledgerPolicy(): array
    {
        return $this->profile['ledger_policy'];
    }

    /** @return list<string> */
    public function proofGates(): array
    {
        return $this->profile['proof_gates'];
    }

    /** @return array{level:string, factors:list<string>} */
    public function maturityRisk(): array
    {
        return $this->profile['maturity_risk'];
    }

    /** @return array<string, array{allowed_scope_prefixes:list<string>, proof_gate:string}> */
    public function laneBoundaries(): array
    {
        return $this->profile['lane_boundaries'];
    }

    /**
     * Returns true when this profile is genuinely distinct from $other on
     * at least one of: project_id, allowed_targets, or proof_gates.
     * Use this to prevent two profiles from silently collapsing into the same lane.
     */
    public function isDistinctProjectFrom(self $other): bool
    {
        return $this->profile['project_id'] !== $other->profile['project_id']
            || $this->profile['allowed_targets'] !== $other->profile['allowed_targets']
            || $this->profile['proof_gates'] !== $other->profile['proof_gates'];
    }
}
