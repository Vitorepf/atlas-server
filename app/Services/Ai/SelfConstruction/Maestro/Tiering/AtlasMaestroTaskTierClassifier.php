<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Tiering;

/**
 * Deterministic, provider-free FACT classifier that infers tier ∈ {easy, hard, hardest} from a single
 * task packet (objective + allowed_files + acceptance_criteria).
 *
 * INVARIANTS:
 *   - PURE: no DB, HTTP, file I/O, env-dependent branch reachable from classify().
 *   - FIXED-BAND rules: tier is decided by clear if/then over named FACTS — no scalar score.
 *   - Each band cites its triggering rule in `fact_basis[]`.
 *   - Output schema = 'atlas.maestro.tier_classification.v1'.
 *   - Identical input ⇒ byte-identical JSON.
 */
final class AtlasMaestroTaskTierClassifier
{
    public const SCHEMA = 'atlas.maestro.tier_classification.v1';

    public const TIER_EASY = 'easy';

    public const TIER_HARD = 'hard';

    public const TIER_HARDEST = 'hardest';

    /**
     * Path substrings that ALWAYS escalate to `hardest`, regardless of file count.
     */
    public const HARDEST_PATH_MARKERS = [
        'AutonomousEvolution/Constitution',
        'Frozen',
        'Pipeline',
        'Provider',
    ];

    /**
     * @param  array{packet_id?:string, objective?:string, allowed_files?:list<string>, acceptance_criteria?:list<string>}  $packet
     * @return array{schema:string, packet_id:string, tier:string, fact_basis:list<string>, content_hash:string, signals:array<string,int|string|bool>}
     */
    public function classify(array $packet): array
    {
        $packetId = (string) ($packet['packet_id'] ?? '');
        $objective = (string) ($packet['objective'] ?? '');
        $allowedFiles = array_values(array_filter(array_map('strval', (array) ($packet['allowed_files'] ?? []))));
        $acceptance = array_values(array_filter(array_map('strval', (array) ($packet['acceptance_criteria'] ?? []))));

        $objectiveLineCount = $this->lineCount($objective);
        $objectiveLength = strlen($objective);
        $fileCount = count($allowedFiles);
        $maxDepth = $this->maxPathDepth($allowedFiles);
        $acceptanceCount = count($acceptance);

        $factBasis = [];
        $tier = self::TIER_EASY;

        // Rule 1 (HIGHEST PRIORITY): cross-cutting marker in any allowed_file ⇒ hardest.
        $hardestMarker = $this->firstHardestMarker($allowedFiles);
        if ($hardestMarker !== null) {
            $tier = self::TIER_HARDEST;
            $factBasis[] = 'allowed_files includes cross-cutting marker: '.$hardestMarker;
        } else {
            // Rule 2: large blast radius ⇒ hard.
            if ($fileCount >= 5 || $acceptanceCount >= 6 || $objectiveLength >= 1200) {
                $tier = self::TIER_HARD;
                if ($fileCount >= 5) {
                    $factBasis[] = 'allowed_files count >= 5';
                }
                if ($acceptanceCount >= 6) {
                    $factBasis[] = 'acceptance_criteria count >= 6';
                }
                if ($objectiveLength >= 1200) {
                    $factBasis[] = 'objective length >= 1200 chars';
                }
            } elseif ($fileCount <= 1 && $objectiveLength < 400) {
                // Rule 3: small surface ⇒ easy.
                $factBasis[] = 'allowed_files <= 1 AND objective < 400 chars';
            } else {
                $tier = self::TIER_HARD;
                $factBasis[] = 'medium surface (no easy / hardest rule matched)';
            }
        }

        $signals = [
            'objective_length' => $objectiveLength,
            'objective_line_count' => $objectiveLineCount,
            'allowed_files_count' => $fileCount,
            'allowed_files_max_depth' => $maxDepth,
            'acceptance_criteria_count' => $acceptanceCount,
            'hardest_marker_hit' => $hardestMarker ?? '',
        ];

        $payload = [
            'schema' => self::SCHEMA,
            'packet_id' => $packetId,
            'tier' => $tier,
            'fact_basis' => $factBasis,
            'signals' => $signals,
        ];

        // Hash of the INPUT (so identical packet ⇒ identical content_hash regardless of run time).
        $canonicalInput = [
            'packet_id' => $packetId,
            'objective' => $objective,
            'allowed_files' => $allowedFiles,
            'acceptance_criteria' => $acceptance,
        ];
        $payload['content_hash'] = hash('sha256', (string) json_encode($canonicalInput, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $payload;
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private function firstHardestMarker(array $allowedFiles): ?string
    {
        foreach ($allowedFiles as $path) {
            foreach (self::HARDEST_PATH_MARKERS as $marker) {
                if (str_contains($path, $marker)) {
                    return $marker;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private function maxPathDepth(array $allowedFiles): int
    {
        $max = 0;
        foreach ($allowedFiles as $path) {
            $depth = substr_count($path, '/');
            $max = max($max, $depth);
        }

        return $max;
    }

    private function lineCount(string $s): int
    {
        if ($s === '') {
            return 0;
        }

        return substr_count($s, "\n") + 1;
    }
}
