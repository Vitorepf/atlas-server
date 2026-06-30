<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Pure miner. Recovers missing draft fields from blocked packet content when it is SAFE to do
 * so — never invents fields when the source content is ambiguous, points at a forbidden/property-
 * gated target without evidence it's allowed, or the objective lacks a concrete class/path/test
 * clue to infer from.
 *
 * Existing fields the caller already supplied are NEVER overwritten — only missing fields are
 * filled, and only from concrete signals already present in the packet (scope_in, objective text,
 * existing allowed_files), never guessed from thin air.
 *
 * Input shape: {objective?:string, allowed_files?:list<string>, acceptance_criteria?:list<string>,
 *               required_evidence?:list<string>, scope_in?:list<string>,
 *               metadata?:{forbidden_or_property_gated?:list<string>, governance_evidence_present?:bool}}
 *
 * Pure — no I/O, no provider calls.
 */
final class AtlasTaskBlockedPacketFieldRecoveryMiner
{
    public const SCHEMA = 'atlas.self_construction.task_quality.blocked_packet_field_recovery_miner.v1';

    /**
     * @param  array<string,mixed>  $packet
     * @return array{schema:string, recovered_fields:array{allowed_files:list<string>,acceptance_criteria:list<string>,required_evidence:list<string>}, confidence:float, evidence_sources:list<string>, refusal_reasons:list<string>}
     */
    public function recover(array $packet): array
    {
        $objective = trim((string) ($packet['objective'] ?? ''));
        $existingAllowed = array_values(array_map('strval', (array) ($packet['allowed_files'] ?? [])));
        $existingAcceptance = array_values(array_map('strval', (array) ($packet['acceptance_criteria'] ?? [])));
        $existingEvidence = array_values(array_map('strval', (array) ($packet['required_evidence'] ?? [])));
        $scopeIn = array_values(array_map('strval', (array) ($packet['scope_in'] ?? [])));

        $evidenceSources = [];
        $refusalReasons = [];
        $confidence = 0.0;

        $recoveredAllowed = $this->recoverAllowedFiles(
            $existingAllowed,
            $scopeIn,
            $objective,
            $evidenceSources,
            $refusalReasons,
            $confidence,
        );

        $recoveredAllowed = $this->applyForbiddenGuard(
            $packet,
            $existingAllowed,
            $recoveredAllowed,
            $refusalReasons,
        );

        $recoveredAcceptance = $this->recoverAcceptanceCriteria(
            $existingAcceptance,
            $recoveredAllowed,
            $evidenceSources,
            $confidence,
        );

        $recoveredEvidence = $this->recoverRequiredEvidence(
            $existingEvidence,
            $objective,
            $refusalReasons,
            $evidenceSources,
            $confidence,
        );

        $confidence = max(0.0, min(1.0, round($confidence, 2)));

        $refusalReasons = array_values(array_unique($refusalReasons));
        sort($refusalReasons, SORT_STRING);
        $evidenceSources = array_values(array_unique($evidenceSources));
        sort($evidenceSources, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'recovered_fields' => [
                'allowed_files' => $recoveredAllowed,
                'acceptance_criteria' => $recoveredAcceptance,
                'required_evidence' => $recoveredEvidence,
            ],
            'confidence' => $confidence,
            'evidence_sources' => $evidenceSources,
            'refusal_reasons' => $refusalReasons,
        ];
    }

    /**
     * @param  list<string>  $existingAllowed
     * @param  list<string>  $scopeIn
     * @param  list<string>  $evidenceSources
     * @param  list<string>  $refusalReasons
     * @return list<string>
     */
    private function recoverAllowedFiles(
        array $existingAllowed,
        array $scopeIn,
        string $objective,
        array &$evidenceSources,
        array &$refusalReasons,
        float &$confidence,
    ): array {
        if ($existingAllowed !== []) {
            return $existingAllowed;
        }

        $candidates = [];
        foreach ($scopeIn as $s) {
            if (str_ends_with($s, '.php')) {
                $candidates[] = $s;
            }
        }
        $usedScopeIn = $candidates !== [];

        $objectivePaths = [];
        if (preg_match_all('#[A-Za-z0-9_\-/]+\.php#', $objective, $matches) !== false) {
            $objectivePaths = $matches[0];
        }
        $candidates = array_values(array_unique(array_merge($candidates, $objectivePaths)));
        sort($candidates, SORT_STRING);

        if ($usedScopeIn) {
            $evidenceSources[] = 'scope_in';
        }
        if ($objectivePaths !== []) {
            $evidenceSources[] = 'objective_path_mention';
        }

        if ($candidates === []) {
            $refusalReasons[] = preg_match('/\bAtlas[A-Za-z0-9]+\b/', $objective) === 1
                ? 'class_name_mentioned_without_concrete_path'
                : 'objective_lacks_concrete_class_path_or_test_clue';

            return [];
        }

        $implCandidates = array_values(array_filter(
            $candidates,
            static fn (string $c): bool => ! str_contains($c, 'Test.php') && ! str_contains($c, '/tests/'),
        ));
        if (count($implCandidates) > 1) {
            $refusalReasons[] = 'ambiguous_implementation_path_candidates';

            return [];
        }

        $confidence += 0.4;

        return $candidates;
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  list<string>  $existingAllowed
     * @param  list<string>  $recoveredAllowed
     * @param  list<string>  $refusalReasons
     * @return list<string>
     */
    private function applyForbiddenGuard(array $packet, array $existingAllowed, array $recoveredAllowed, array &$refusalReasons): array
    {
        if ($existingAllowed !== []) {
            return $recoveredAllowed;
        }

        $metadata = is_array($packet['metadata'] ?? null) ? $packet['metadata'] : [];
        $forbiddenHints = array_values(array_map('strval', (array) ($metadata['forbidden_or_property_gated'] ?? [])));
        $hasGovernanceEvidence = (bool) ($metadata['governance_evidence_present'] ?? false);

        if ($forbiddenHints === [] || $hasGovernanceEvidence) {
            return $recoveredAllowed;
        }

        if (array_values(array_intersect($recoveredAllowed, $forbiddenHints)) !== []) {
            $refusalReasons[] = 'target_forbidden_or_property_gated_without_evidence';

            return [];
        }

        return $recoveredAllowed;
    }

    /**
     * @param  list<string>  $existingAcceptance
     * @param  list<string>  $recoveredAllowed
     * @param  list<string>  $evidenceSources
     * @return list<string>
     */
    private function recoverAcceptanceCriteria(
        array $existingAcceptance,
        array $recoveredAllowed,
        array &$evidenceSources,
        float &$confidence,
    ): array {
        if ($existingAcceptance !== []) {
            return $existingAcceptance;
        }

        $testPath = null;
        foreach ($recoveredAllowed as $f) {
            if (str_contains($f, 'Test.php') || str_contains($f, '/tests/')) {
                $testPath = $f;
                break;
            }
        }

        if ($testPath === null) {
            return [];
        }

        $evidenceSources[] = 'test_path_inferred_acceptance';
        $confidence += 0.3;

        return [sprintf('/opt/homebrew/bin/php artisan test %s', $testPath)];
    }

    /**
     * @param  list<string>  $existingEvidence
     * @param  list<string>  $refusalReasons
     * @param  list<string>  $evidenceSources
     * @return list<string>
     */
    private function recoverRequiredEvidence(
        array $existingEvidence,
        string $objective,
        array &$refusalReasons,
        array &$evidenceSources,
        float &$confidence,
    ): array {
        if ($existingEvidence !== []) {
            return $existingEvidence;
        }

        $objectiveConcrete = mb_strlen($objective) >= 30
            && (preg_match('/\bAtlas[A-Za-z0-9]+\b/', $objective) === 1 || preg_match('#\.php\b#', $objective) === 1);

        if (! $objectiveConcrete) {
            $refusalReasons[] = 'objective_not_concrete_enough_for_evidence_defaults';

            return [];
        }

        $evidenceSources[] = 'objective_concreteness_default_evidence';
        $confidence += 0.2;

        return ['tests_or_gates_result', 'implementation_notes'];
    }
}
