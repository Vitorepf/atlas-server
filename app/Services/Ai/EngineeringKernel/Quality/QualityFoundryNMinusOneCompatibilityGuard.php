<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Quality;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;

/** Fail-closed compatibility gate for an authorized N -> N-1 rollback drill. */
final class QualityFoundryNMinusOneCompatibilityGuard
{
    private const ADDITIVE_OPERATIONS = ['add_table', 'add_column', 'add_index', 'alter_nullable_forward'];

    /** @param array<string,mixed> $current @param array<string,mixed> $previous @param array<string,mixed> $probe @return array<string,mixed> */
    public function evaluate(array $current, array $previous, array $probe): array
    {
        $blockers = [];
        $currentSchema = trim((string) ($current['schema_version'] ?? ''));
        $previousSchema = trim((string) ($previous['schema_version'] ?? ''));
        if ($currentSchema === '' || $previousSchema === '') {
            $blockers[] = 'artifact_schema_required';
        }
        if (! in_array($previousSchema, (array) ($current['backward_compatible_with'] ?? []), true)) {
            $blockers[] = 'n_minus_one_schema_not_declared';
        }
        foreach ([['artifact' => $current, 'label' => 'current'], ['artifact' => $previous, 'label' => 'previous']] as $entry) {
            if (! $this->hashMatches($entry['artifact'])) {
                $blockers[] = 'artifact_hash_mismatch';
            }
        }

        foreach ((array) ($current['migrations'] ?? []) as $migration) {
            $operation = strtolower(trim((string) ($migration['operation'] ?? '')));
            if (! in_array($operation, self::ADDITIVE_OPERATIONS, true) || ($migration['destructive'] ?? true) !== false) {
                $blockers[] = 'migration_not_forward_only_additive:'.($operation !== '' ? $operation : 'unknown');
            }
        }

        if (! in_array($probe['environment'] ?? null, ['fixture', 'staging'], true)) {
            $blockers[] = 'rollback_fixture_or_staging_required';
        }
        if (($probe['rollback_probe'] ?? null) !== 'passed') {
            $blockers[] = 'rollback_probe_required';
        }
        if (preg_match('/^[a-f0-9]{64}$/', (string) ($probe['receipt_hash'] ?? '')) !== 1) {
            $blockers[] = 'rollback_receipt_required';
        }

        $blockers = array_values(array_unique($blockers));
        $result = [
            'schema' => 'atlas.quality_foundry.n_minus_one_compatibility.v1',
            'status' => $blockers === [] ? 'compatible' : 'blocked',
            'rollback_allowed' => $blockers === [],
            'current_schema' => $currentSchema,
            'previous_schema' => $previousSchema,
            'environment' => $probe['environment'] ?? null,
            'blockers' => $blockers,
        ];
        $result['compatibility_hash'] = CanonicalKernelPayload::hash($result);

        return $result;
    }

    /** @param array<string,mixed> $artifact */
    private function hashMatches(array $artifact): bool
    {
        $expected = (string) ($artifact['artifact_hash'] ?? '');
        unset($artifact['artifact_hash']);

        return preg_match('/^[a-f0-9]{64}$/', $expected) === 1
            && hash_equals($expected, hash('sha256', json_encode($artifact, JSON_THROW_ON_ERROR)));
    }
}
