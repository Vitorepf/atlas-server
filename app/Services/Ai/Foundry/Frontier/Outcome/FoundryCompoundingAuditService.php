<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Outcome;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\AppendOnlyJsonlStore;

/**
 * Foundry AP-E · Compounding Audit (Finding 27) — read-only proof that each
 * cycle actually increased NET capability.
 *
 * Finding 27: nothing proves a cycle raised net capability. Proven count,
 * revert rate and capability growth across cycles were never computed from the
 * append-only ledgers, so a 1/10-proven run looked identical to a 10/10 run at
 * the latest-roadmap level. This service closes that gap with a deterministic
 * FOLD over the two ledgers the materializer already writes:
 *
 *   - evolution_outcomes.jsonl — one evolution_outcome.v1 line per closed
 *     cycle, action='consolidate' (proven green) or action='reverted'
 *     (refuted_by_reality). The audit COUNTS these exactly as the materializer
 *     recorded them; it NEVER re-derives 'proven' (preserves I5: proven comes
 *     only from a real-green measure recorded by the materializer).
 *   - roadmap.jsonl — versioned append-only roadmap.v1 lines. proven_capabilities_now
 *     is the count of capabilities in state='proven' on the LATEST line;
 *     proven_delta_vs_prior_version is that count minus the proven count on the
 *     immediately-prior roadmap version. A run that proved 1 of 10 shows
 *     proven_delta_vs_prior_version=+1, never +10.
 *
 * STRICTLY a read-model: ZERO writes, ZERO canonization, ZERO provider, ZERO
 * revert. It only READS the ledgers the materializer authored. An absent ledger
 * folds to an empty/zeroed deterministic audit (real-or-blocked: no data => no
 * invented capability). The audit hash is MissionCanonicalHash (sha256) over the
 * canonical counts so two folds of the same ledgers are byte-identical.
 *
 * Composes (does NOT duplicate): the materializer's evolution_outcomes.jsonl +
 * roadmap.jsonl layout, FoundrySchemas for the schema string, MissionCanonicalHash
 * for the deterministic audit hash.
 */
final class FoundryCompoundingAuditService
{
    public const SCHEMA = 'atlas.foundry.compounding_audit.v1';

    private ?string $storageDirOverride = null;

    /**
     * Scoped JSONL read seam (mirrors
     * FoundryEvolutionOutcomeMaterializerService::setOutcomesStorageDirForTesting).
     */
    public function setStorageDirForTesting(?string $dir): void
    {
        $this->storageDirOverride = $dir;
    }

    /**
     * Fold the append-only ledgers for an area into a compounding_audit.v1.
     *
     * @return array<string,mixed>
     */
    public function audit(string $areaId): array
    {
        $outcomes = AppendOnlyJsonlStore::read($this->storagePath($areaId, 'evolution_outcomes.jsonl'));
        $roadmapLines = AppendOnlyJsonlStore::read($this->storagePath($areaId, 'roadmap.jsonl'));

        $cycles = count($outcomes);
        $consolidatedCount = 0;
        $revertedCount = 0;
        $refutedByRealityCount = 0;

        foreach ($outcomes as $outcome) {
            $action = is_string($outcome['action'] ?? null) ? $outcome['action'] : '';
            if ($action === FoundryEvolutionOutcomeMaterializerService::ACTION_CONSOLIDATE) {
                $consolidatedCount++;
            } elseif ($action === FoundryEvolutionOutcomeMaterializerService::ACTION_REVERTED) {
                $revertedCount++;
            }
            if (($outcome['refuted_by_reality'] ?? false) === true) {
                $refutedByRealityCount++;
            }
        }

        // proven counts are READ off the roadmap exactly as the materializer
        // transitioned them (state='proven'); never re-derived from 'improved'.
        [$latestRoadmap, $priorRoadmap] = $this->latestTwoByVersion($roadmapLines);
        $provenNow = $this->provenCount($latestRoadmap);
        $provenPrior = $this->provenCount($priorRoadmap);
        $provenDelta = $provenNow - $provenPrior;

        $revertRate = $cycles > 0
            ? round($revertedCount / $cycles, 6)
            : 0.0;

        $audit = [
            'schema_version' => self::SCHEMA,
            'area_id' => $areaId,
            'cycles' => $cycles,
            'consolidated_count' => $consolidatedCount,
            'reverted_count' => $revertedCount,
            'refuted_by_reality_count' => $refutedByRealityCount,
            'proven_capabilities_now' => $provenNow,
            'proven_delta_vs_prior_version' => $provenDelta,
            'revert_rate' => $revertRate,
        ];

        $audit['audit_hash'] = MissionCanonicalHash::sha256([
            'area_id' => $areaId,
            'cycles' => $cycles,
            'consolidated_count' => $consolidatedCount,
            'reverted_count' => $revertedCount,
            'refuted_by_reality_count' => $refutedByRealityCount,
            'proven_capabilities_now' => $provenNow,
            'proven_delta_vs_prior_version' => $provenDelta,
            'revert_rate' => $revertRate,
        ]);

        return $audit;
    }

    /**
     * Count capabilities in state='proven' on a roadmap line (or 0 if absent).
     *
     * @param  array<string,mixed>|null  $roadmap
     */
    private function provenCount(?array $roadmap): int
    {
        if ($roadmap === null) {
            return 0;
        }
        $capabilities = is_array($roadmap['capabilities'] ?? null) ? $roadmap['capabilities'] : [];
        $count = 0;
        foreach ($capabilities as $capability) {
            if (! is_array($capability)) {
                continue;
            }
            if ((string) ($capability['state'] ?? '') === FoundryEvolutionOutcomeMaterializerService::STATE_PROVEN) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Return [latest, prior] roadmap lines ordered by the append-only 'version'
     * field. Returns nulls when fewer lines exist. Deterministic on ties:
     * later JSONL position wins, mirroring append-only ordering.
     *
     * @param  list<array<string,mixed>>  $lines
     * @return array{0:?array<string,mixed>,1:?array<string,mixed>}
     */
    private function latestTwoByVersion(array $lines): array
    {
        if ($lines === []) {
            return [null, null];
        }

        $indexed = [];
        foreach ($lines as $position => $line) {
            $indexed[] = ['version' => (int) ($line['version'] ?? 0), 'position' => $position, 'line' => $line];
        }

        usort($indexed, static function (array $a, array $b): int {
            return [$a['version'], $a['position']] <=> [$b['version'], $b['position']];
        });

        $latest = $indexed[count($indexed) - 1]['line'];
        $prior = count($indexed) >= 2 ? $indexed[count($indexed) - 2]['line'] : null;

        return [$latest, $prior];
    }

    private function storagePath(string $areaId, string $file): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($areaId)) ?: 'unknown_area';

        $base = $this->storageDirOverride
            ?? (function_exists('storage_path') ? storage_path('atlas/foundry') : sys_get_temp_dir().'/atlas/foundry');

        return $base.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.$file;
    }

}
