<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Read-only contract for cross-mode failure injection fixtures.
 *
 * A fixture represents the observable receipt after injecting one failure at a
 * shared boundary. This class does not execute effects or manufacture a
 * verdict; it refuses a fixture unless every mode reports the same safe
 * terminal semantics.
 */
final class QualityFoundryCrossModeFailureMatrix
{
    /** @var list<string> */
    public const BOUNDARIES = [
        'ledger', 'governor', 'provider', 'verifier',
        'canary', 'revert', 'outcome', 'process_kill',
    ];

    /** @var list<string> */
    private const MODES = ['dev', 'forge', 'autonomos'];

    /**
     * @param array<string,array<string,array<string,mixed>>> $fixtures
     * @return array<string,mixed>
     */
    public function evaluate(array $fixtures): array
    {
        $failures = [];
        foreach (self::BOUNDARIES as $boundary) {
            $rows = $fixtures[$boundary] ?? [];
            $missing = array_values(array_diff(self::MODES, array_keys($rows)));
            if ($missing !== []) {
                $failures[$boundary][] = 'modes_missing:'.implode(',', $missing);
                continue;
            }

            $projections = [];
            foreach (self::MODES as $mode) {
                $row = $rows[$mode];
                if (($row['claim_eligible'] ?? true) !== false) {
                    $failures[$boundary][] = 'claim_eligible:'.$mode;
                }
                if ((int) ($row['unauthorized_effects'] ?? 1) !== 0) {
                    $failures[$boundary][] = 'unauthorized_effects:'.$mode;
                }
                if ($boundary === 'process_kill'
                    && (int) ($row['provider_invocations_added'] ?? 1) !== 0) {
                    $failures[$boundary][] = 'provider_reinvoked:'.$mode;
                }
                $projections[$mode] = array_intersect_key($row, array_flip([
                    'applicability', 'disposition', 'verdict', 'authorization',
                    'canary', 'status', 'claim_eligible', 'replay_status',
                    'provider_invocations_added', 'unauthorized_effects',
                ]));
            }
            if (count(array_unique(array_map(
                static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                $projections,
            ))) > 1) {
                $failures[$boundary][] = 'terminal_semantics_drift';
            }
        }

        $unexpected = array_values(array_diff(array_keys($fixtures), self::BOUNDARIES));
        if ($unexpected !== []) {
            $failures['_schema'][] = 'unexpected_boundaries:'.implode(',', $unexpected);
        }

        $payload = [
            'schema_version' => 'atlas.quality_foundry.cross_mode_failure_matrix.v1',
            'boundaries' => self::BOUNDARIES,
            'modes' => self::MODES,
            'fixture_count' => count(array_intersect_key($fixtures, array_flip(self::BOUNDARIES))),
            'failures' => $failures,
            'accepted' => $failures === [] && count($fixtures) === count(self::BOUNDARIES),
        ];
        $payload['matrix_hash'] = CanonicalKernelPayload::hash($payload);

        return $payload;
    }
}
