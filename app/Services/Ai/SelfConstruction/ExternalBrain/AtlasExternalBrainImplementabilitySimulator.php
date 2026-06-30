<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure preflight gate — classifies a candidate task packet before it enters the queue.
 *
 * INPUT:
 *   candidate: { task_packet_id, allowed_files, acceptance_criteria?, objective?, unblocked_by? }
 *   context:   { implemented_files?, active_allowed_files?, pending_task_ids?, property_gates?,
 *                missing_prod_files? }
 *
 * VERDICTS (checked in priority order):
 *   contradictory               — acceptance criteria negate each other, or allowed_files contains
 *                                 only test files while the impl files are known to be missing.
 *   duplicate_existing_capability — all impl files in allowed_files already exist.
 *   property_gated_missing_evidence — task is blocked by an unmet property gate.
 *   collision                   — allowed_files overlap with another active lease.
 *   defer_for_dependency        — unblocked_by contains a still-pending task.
 *   enqueueable                 — all checks pass.
 *
 * INVARIANT: the simulator never modifies allowed_files. It never produces a suggested_allowed_files
 * that strips impl files while leaving only tests for an unimplemented feature.
 */
final class AtlasExternalBrainImplementabilitySimulator
{
    public const SCHEMA = 'atlas.external_brain.implementability_simulator.v1';

    public const VERDICT_ENQUEUEABLE = 'enqueueable';

    public const VERDICT_DEFER = 'defer_for_dependency';

    public const VERDICT_DUPLICATE = 'duplicate_existing_capability';

    public const VERDICT_COLLISION = 'collision';

    public const VERDICT_PROPERTY_GATED = 'property_gated_missing_evidence';

    public const VERDICT_CONTRADICTORY = 'contradictory';

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $context
     * @return array{schema:string, verdict:string, reasons:list<string>, candidate_id:string}
     */
    public function simulate(array $candidate, array $context = []): array
    {
        $id = (string) ($candidate['task_packet_id'] ?? '');
        $allowedFiles = is_array($candidate['allowed_files'] ?? null) ? array_values($candidate['allowed_files']) : [];
        $criteria = is_array($candidate['acceptance_criteria'] ?? null) ? $candidate['acceptance_criteria'] : [];
        $unblocked_by = is_array($candidate['unblocked_by'] ?? null) ? $candidate['unblocked_by'] : [];

        $implementedFiles = is_array($context['implemented_files'] ?? null) ? $context['implemented_files'] : [];
        $activeAllowedFiles = is_array($context['active_allowed_files'] ?? null) ? $context['active_allowed_files'] : [];
        $pendingTaskIds = is_array($context['pending_task_ids'] ?? null) ? $context['pending_task_ids'] : [];
        $propertyGates = is_array($context['property_gates'] ?? null) ? $context['property_gates'] : [];
        $missingProdFiles = is_array($context['missing_prod_files'] ?? null) ? $context['missing_prod_files'] : [];

        // 1. Contradictory — criteria negate each other OR test-only with missing impl.
        $contradictions = $this->detectContradictions($criteria, $allowedFiles, $missingProdFiles);
        if ($contradictions !== []) {
            return $this->result($id, self::VERDICT_CONTRADICTORY, $contradictions);
        }

        // 2. Duplicate — all impl files already exist.
        $implFiles = array_values(array_filter($allowedFiles, fn (string $f): bool => ! $this->isTestFile($f)));
        if ($implFiles !== [] && array_diff($implFiles, $implementedFiles) === []) {
            return $this->result($id, self::VERDICT_DUPLICATE, ['all_impl_files_already_exist']);
        }

        // 3. Property gate missing evidence.
        if (array_key_exists($id, $propertyGates) && $propertyGates[$id] === false) {
            return $this->result($id, self::VERDICT_PROPERTY_GATED, ['property_gate_not_satisfied:'.$id]);
        }

        // 4. Collision with active leases.
        $collisions = array_values(array_intersect($allowedFiles, $activeAllowedFiles));
        if ($collisions !== []) {
            return $this->result(
                $id,
                self::VERDICT_COLLISION,
                array_map(fn (string $f): string => 'file_held:'.$f, $collisions)
            );
        }

        // 5. Dependency not yet complete.
        $blockers = array_values(array_intersect($unblocked_by, $pendingTaskIds));
        if ($blockers !== []) {
            return $this->result(
                $id,
                self::VERDICT_DEFER,
                array_map(fn (string $t): string => 'pending:'.$t, $blockers)
            );
        }

        return $this->result($id, self::VERDICT_ENQUEUEABLE, []);
    }

    /** @return array{schema:string, verdict:string, reasons:list<string>, candidate_id:string} */
    private function result(string $id, string $verdict, array $reasons): array
    {
        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'reasons' => array_values($reasons),
            'candidate_id' => $id,
        ];
    }

    /**
     * @param  list<string>  $criteria
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $missingProdFiles
     * @return list<string>
     */
    private function detectContradictions(array $criteria, array $allowedFiles, array $missingProdFiles): array
    {
        $reasons = [];

        // Test-only with missing impl: all allowed_files are tests and prod files are missing.
        $testFiles = array_filter($allowedFiles, fn (string $f): bool => $this->isTestFile($f));
        $implFiles = array_filter($allowedFiles, fn (string $f): bool => ! $this->isTestFile($f));
        if ($testFiles !== [] && $implFiles === [] && $missingProdFiles !== []) {
            $reasons[] = 'test_without_impl:missing_prod_files:'.implode(',', $missingProdFiles);
        }

        // Criteria negation: "must X" paired with "must not X".
        $positives = [];
        $negatives = [];
        foreach ($criteria as $criterion) {
            $lower = strtolower((string) $criterion);
            if (preg_match('/must not\s+(.+)/', $lower, $m) === 1) {
                $negatives[] = trim($m[1]);
            } elseif (preg_match('/must\s+(.+)/', $lower, $m) === 1) {
                $positives[] = trim($m[1]);
            }
        }
        foreach ($positives as $pos) {
            foreach ($negatives as $neg) {
                if (str_contains($neg, $pos) || str_contains($pos, $neg)) {
                    $reasons[] = 'contradictory_criteria:must:'.$pos.':must_not:'.$neg;
                }
            }
        }

        return $reasons;
    }

    private function isTestFile(string $path): bool
    {
        return str_ends_with($path, 'Test.php');
    }
}
