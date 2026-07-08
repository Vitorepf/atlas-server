<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\Support\WriteSetOverlap;

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
        $knownExistingPaths = array_values(array_map('strval', (array) ($packet['known_existing_paths'] ?? [])));
        $sourcePacketId = trim((string) ($packet['source_packet_id'] ?? ''));
        $taskPacketId = trim((string) ($packet['task_packet_id'] ?? ''));

        $evidenceSources = [];
        $refusalReasons = [];
        $confidence = 0.0;

        $recoveredAllowed = $this->recoverAllowedFilesFromSlug(
            $existingAllowed,
            $taskPacketId,
            $scopeIn,
            $knownExistingPaths,
            $evidenceSources,
        );

        if ($recoveredAllowed !== []) {
            $confidence += 0.75;
        } else {
            $recoveredAllowed = $this->recoverAllowedFiles(
                $existingAllowed,
                $scopeIn,
                $knownExistingPaths,
                $objective,
                $evidenceSources,
                $refusalReasons,
                $confidence,
            );
        }

        if ($recoveredAllowed !== [] && $sourcePacketId !== '') {
            // A source_packet_id alone never fabricates a field — it only corroborates a recovery
            // that already happened from a concrete path signal.
            $evidenceSources[] = 'source_packet_id_corroboration';
        }

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

        $missingFields = [];
        if ($recoveredAllowed === []) {
            $missingFields[] = 'allowed_files';
        }
        if ($recoveredAcceptance === []) {
            $missingFields[] = 'acceptance_criteria';
        }
        if ($recoveredEvidence === []) {
            $missingFields[] = 'required_evidence';
        }

        $recoveries = $this->buildRecoveries(
            $packet,
            $existingAllowed,
            $recoveredAllowed,
            $existingAcceptance,
            $recoveredAcceptance,
            $refusalReasons,
            $evidenceSources,
            $confidence,
        );

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
            'missing_fields' => $missingFields,
            'recoveries' => $recoveries,
        ];
    }

    /**
     * One entry per blocking-issue category this miner can diagnose: missing_implementation_file,
     * test_only_scope, forbidden_target, missing_runnable_proof, dependency_inversion, and
     * contradictory_acceptance. Each entry is itself safe-by-construction — confidence and
     * safe_to_respec always reflect how concrete the underlying signal was, never an optimistic
     * guess.
     *
     * @param  array<string,mixed>  $packet
     * @param  list<string>  $existingAllowed
     * @param  list<string>  $recoveredAllowed
     * @param  list<string>  $existingAcceptance
     * @param  list<string>  $recoveredAcceptance
     * @param  list<string>  $refusalReasons
     * @param  list<string>  $evidenceSources
     * @return list<array{recovered_field:string, confidence:float, source_evidence:list<string>, safe_to_respec:bool}>
     */
    private function buildRecoveries(
        array $packet,
        array $existingAllowed,
        array $recoveredAllowed,
        array $existingAcceptance,
        array $recoveredAcceptance,
        array $refusalReasons,
        array $evidenceSources,
        float $confidence,
    ): array {
        $recoveries = [];

        if ($existingAllowed !== [] && $this->isTestOnlyScope($existingAllowed)) {
            $recoveries[] = $this->recoveryEntry('test_only_scope', 0.0, ['existing_allowed_files_test_only'], false);
        } elseif ($existingAllowed === []) {
            if (in_array('target_forbidden_or_property_gated_without_evidence', $refusalReasons, true)) {
                $recoveries[] = $this->recoveryEntry('forbidden_target', 0.0, [], false);
            } elseif ($recoveredAllowed !== []) {
                $recoveries[] = $this->recoveryEntry('missing_implementation_file', $confidence, $evidenceSources, $confidence >= 0.5);
            } elseif ($refusalReasons !== []) {
                $recoveries[] = $this->recoveryEntry('missing_implementation_file', 0.0, [], false);
            }
        }

        $acceptanceToCheck = $existingAcceptance !== [] ? $existingAcceptance : $recoveredAcceptance;
        $hasRunnableProof = false;
        foreach ($acceptanceToCheck as $c) {
            if (str_contains($c, 'php artisan')) {
                $hasRunnableProof = true;

                break;
            }
        }
        if (! $hasRunnableProof) {
            $testPath = null;
            foreach ($recoveredAllowed as $f) {
                if (str_contains($f, 'Test.php') || str_contains($f, '/tests/')) {
                    $testPath = $f;

                    break;
                }
            }
            if ($testPath !== null) {
                $recoveries[] = $this->recoveryEntry('missing_runnable_proof', 0.7, ['test_path_inferred_acceptance'], true);
            } elseif ($acceptanceToCheck !== []) {
                $recoveries[] = $this->recoveryEntry('missing_runnable_proof', 0.0, [], false);
            }
        }

        $dependencies = array_values(array_map('strval', (array) ($packet['dependencies'] ?? [])));
        $selfReferential = array_values(array_intersect($dependencies, array_merge($existingAllowed, $recoveredAllowed)));
        if ($selfReferential !== []) {
            $recoveries[] = $this->recoveryEntry('dependency_inversion', 0.6, ['dependency_matches_own_scope'], true);
        }

        if ($this->hasContradictoryAcceptance($acceptanceToCheck)) {
            $recoveries[] = $this->recoveryEntry('contradictory_acceptance', 0.0, ['acceptance_criteria_self_contradictory'], false);
        }

        return $recoveries;
    }

    /** @param  list<string>  $files */
    private function isTestOnlyScope(array $files): bool
    {
        $hasTest = false;
        $hasImpl = false;
        foreach ($files as $f) {
            if (str_contains($f, 'Test.php') || str_contains($f, '/tests/')) {
                $hasTest = true;
            } else {
                $hasImpl = true;
            }
        }

        return $hasTest && ! $hasImpl;
    }

    /** @param  list<string>  $criteria */
    private function hasContradictoryAcceptance(array $criteria): bool
    {
        foreach ($criteria as $a) {
            foreach ($criteria as $b) {
                if ($a === $b) {
                    continue;
                }
                if ($this->areContradictory($a, $b)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Detects "X must Y" vs "X must not Y" / "X cannot Y" / "X never Y" pairs — same remainder
     * text, opposite polarity. A deliberately narrow heuristic: it never guesses contradiction
     * from loosely related wording.
     */
    private function areContradictory(string $a, string $b): bool
    {
        $normalize = static function (string $s): string {
            $s = strtolower(trim($s));
            $s = preg_replace('/\bmust not\b|\bcannot\b|\bcan not\b|\bnever\b/', '¬', $s) ?? $s;
            $s = preg_replace('/\bmust\b/', '', $s) ?? $s;
            $s = preg_replace('/\s+/', ' ', $s) ?? $s;

            return trim($s);
        };

        $an = $normalize($a);
        $bn = $normalize($b);

        if (! str_contains($an, '¬') && str_contains($bn, '¬')) {
            return $this->stripNegator($bn) === $an;
        }
        if (! str_contains($bn, '¬') && str_contains($an, '¬')) {
            return $this->stripNegator($an) === $bn;
        }

        return false;
    }

    private function stripNegator(string $s): string
    {
        $s = str_replace('¬', '', $s);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return trim($s);
    }

    /**
     * @param  list<string>  $sourceEvidence
     * @return array{recovered_field:string, confidence:float, source_evidence:list<string>, safe_to_respec:bool}
     */
    private function recoveryEntry(string $field, float $confidence, array $sourceEvidence, bool $safeToRespec): array
    {
        return [
            'recovered_field' => $field,
            'confidence' => max(0.0, min(1.0, round($confidence, 2))),
            'source_evidence' => array_values(array_unique($sourceEvidence)),
            'safe_to_respec' => $safeToRespec,
        ];
    }

    /**
     * @param  list<string>  $existingAllowed
     * @param  list<string>  $scopeIn
     * @param  list<string>  $knownExistingPaths
     * @param  list<string>  $evidenceSources
     * @param  list<string>  $refusalReasons
     * @return list<string>
     */
    private function recoverAllowedFiles(
        array $existingAllowed,
        array $scopeIn,
        array $knownExistingPaths,
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

        $knownPathCandidates = [];
        foreach ($knownExistingPaths as $p) {
            if (str_ends_with($p, '.php')) {
                $knownPathCandidates[] = $p;
            }
        }
        $candidates = array_merge($candidates, $knownPathCandidates);

        $objectivePaths = [];
        if (preg_match_all('#[A-Za-z0-9_\-/]+\.php#', $objective, $matches) !== false) {
            $objectivePaths = $matches[0];
        }
        $candidates = array_values(array_unique(array_merge($candidates, $objectivePaths)));
        sort($candidates, SORT_STRING);

        if ($usedScopeIn) {
            $evidenceSources[] = 'scope_in';
        }
        if ($knownPathCandidates !== []) {
            $evidenceSources[] = 'known_existing_code_or_test_paths';
        }
        if ($objectivePaths !== []) {
            $evidenceSources[] = 'objective_path_mention';
        }

        // A class-like target (e.g. AtlasFooBarValidator) mentioned in the objective, corroborated
        // by a known/scope_in test path whose basename matches that class name, is a safe recovery
        // signal even though the objective text itself carries no literal .php path.
        if (preg_match('/\bAtlas[A-Za-z0-9]+\b/', $objective, $classMatch) === 1) {
            $className = $classMatch[0];
            foreach (array_merge($knownPathCandidates, $candidates) as $p) {
                if (basename($p) === $className.'Test.php' && ! in_array($p, $candidates, true)) {
                    $candidates[] = $p;
                }
            }
            if (in_array($className.'Test.php', array_map('basename', $candidates), true)) {
                $evidenceSources[] = 'class_target_matched_test_path';
            }
        }
        $candidates = array_values(array_unique($candidates));
        sort($candidates, SORT_STRING);

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
     * Recovers allowed_files from a safe `codex-meta-<slug>-<...>` task_packet_id pattern when the
     * slug names an EXISTING implementation+test pair already present in known_existing_paths or
     * scope_in — never invents a path. Only fires when exactly one implementation path and at least
     * one test path corroborate the slug; any ambiguity (0 or 2+ implementation matches) refuses
     * silently here and falls through to the generic recovery path instead.
     *
     * @param  list<string>  $existingAllowed
     * @param  list<string>  $scopeIn
     * @param  list<string>  $knownExistingPaths
     * @param  list<string>  $evidenceSources
     * @return list<string>
     */
    private function recoverAllowedFilesFromSlug(
        array $existingAllowed,
        string $taskPacketId,
        array $scopeIn,
        array $knownExistingPaths,
        array &$evidenceSources,
    ): array {
        if ($existingAllowed !== [] || $taskPacketId === '') {
            return [];
        }

        if (preg_match('/^codex-meta-([a-z0-9-]+?)(?:-\d{8,}.*)?$/', $taskPacketId, $m) !== 1) {
            return [];
        }
        $slug = $m[1];
        $slugTokens = array_values(array_filter(explode('-', $slug)));
        if ($slugTokens === []) {
            return [];
        }
        $className = implode('', array_map(static fn (string $t): string => ucfirst($t), $slugTokens));

        $allPaths = array_values(array_unique(array_merge($scopeIn, $knownExistingPaths)));
        $allPaths = array_values(array_filter($allPaths, static fn (string $p): bool => str_ends_with($p, '.php')));

        $implMatches = array_values(array_filter(
            $allPaths,
            static fn (string $p): bool => basename($p) === $className.'.php',
        ));
        $testMatches = array_values(array_filter(
            $allPaths,
            static fn (string $p): bool => basename($p) === $className.'Test.php',
        ));

        if (count($implMatches) !== 1 || $testMatches === []) {
            return [];
        }

        $evidenceSources[] = 'codex_meta_slug_target_path_corroboration';

        $recovered = array_values(array_unique(array_merge($implMatches, $testMatches)));
        sort($recovered, SORT_STRING);

        return $recovered;
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

        if (WriteSetOverlap::collidingPaths($recoveredAllowed, $forbiddenHints) !== []) {
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
