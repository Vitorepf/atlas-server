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
     * allowed_files count at or above this value escalates to `hardest` (broad-scope signal).
     */
    public const BROAD_SCOPE_HARDEST_THRESHOLD = 10;

    /**
     * give_back_count at or above this value marks a task as poison-prone → `hardest`.
     */
    public const POISON_PRONE_THRESHOLD = 3;

    /**
     * Objective substrings (case-insensitive) that always escalate to `hardest`.
     */
    public const FINAL_CERT_KEYWORDS = [
        'final certification',
        'final certif',
        'final approval',
    ];

    /**
     * @param  array{packet_id?:string, objective?:string, allowed_files?:list<string>, acceptance_criteria?:list<string>, give_back_count?:int, required_evidence?:list<string>, provided_evidence?:list<string>}  $packet
     * @return array{schema:string, packet_id:string, tier:string, fact_basis:list<string>, content_hash:string, signals:array<string,int|string|bool>}
     */
    public function classify(array $packet): array
    {
        $packetId = (string) ($packet['packet_id'] ?? '');
        $objective = (string) ($packet['objective'] ?? '');
        $allowedFiles = array_values(array_filter(array_map('strval', (array) ($packet['allowed_files'] ?? []))));
        $acceptance = array_values(array_filter(array_map('strval', (array) ($packet['acceptance_criteria'] ?? []))));
        $giveBackCount = (int) ($packet['give_back_count'] ?? 0);
        $requiredEvidence = array_values(array_filter(array_map('strval', (array) ($packet['required_evidence'] ?? []))));
        $providedEvidence = array_values(array_filter(array_map('strval', (array) ($packet['provided_evidence'] ?? []))));

        $objectiveLineCount = $this->lineCount($objective);
        $objectiveLength = strlen($objective);
        $fileCount = count($allowedFiles);
        $maxDepth = $this->maxPathDepth($allowedFiles);
        $acceptanceCount = count($acceptance);

        // Evidence-backed signals.
        $missingEvidenceCount = 0;
        if ($requiredEvidence !== []) {
            foreach ($requiredEvidence as $req) {
                if (! in_array($req, $providedEvidence, true)) {
                    $missingEvidenceCount++;
                }
            }
        }

        $hasFinalCertKeyword = false;
        $lowerObjective = strtolower($objective);
        foreach (self::FINAL_CERT_KEYWORDS as $kw) {
            if (str_contains($lowerObjective, $kw)) {
                $hasFinalCertKeyword = true;
                break;
            }
        }

        $hardestMarker = $this->firstHardestMarker($allowedFiles);
        $broadScope = $fileCount >= self::BROAD_SCOPE_HARDEST_THRESHOLD;
        $poisonProne = $giveBackCount >= self::POISON_PRONE_THRESHOLD;

        $factBasis = [];
        $tier = self::TIER_EASY;

        // Rule 1 (HIGHEST PRIORITY): hardest escalators.
        if ($hardestMarker !== null || $hasFinalCertKeyword || $broadScope || $poisonProne) {
            $tier = self::TIER_HARDEST;
            if ($hardestMarker !== null) {
                $factBasis[] = 'allowed_files includes cross-cutting marker: '.$hardestMarker;
            }
            if ($hasFinalCertKeyword) {
                $factBasis[] = 'objective contains final-certification keyword';
            }
            if ($broadScope) {
                $factBasis[] = 'broad_scope: allowed_files_count >= '.self::BROAD_SCOPE_HARDEST_THRESHOLD.': '.$fileCount;
            }
            if ($poisonProne) {
                $factBasis[] = 'poison_prone: give_back_count >= '.self::POISON_PRONE_THRESHOLD.': '.$giveBackCount;
            }
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

            // missing_evidence escalates easy → hard.
            if ($missingEvidenceCount > 0) {
                if ($tier === self::TIER_EASY) {
                    $tier = self::TIER_HARD;
                }
                $factBasis[] = 'missing_evidence: '.$missingEvidenceCount.' of '.count($requiredEvidence).' required evidence refs absent';
            }
        }

        $signals = [
            'objective_length' => $objectiveLength,
            'objective_line_count' => $objectiveLineCount,
            'allowed_files_count' => $fileCount,
            'allowed_files_max_depth' => $maxDepth,
            'acceptance_criteria_count' => $acceptanceCount,
            'hardest_marker_hit' => $hardestMarker ?? '',
            'give_back_count' => $giveBackCount,
            'missing_evidence_count' => $missingEvidenceCount,
            'required_evidence_count' => count($requiredEvidence),
            'final_cert_keyword_hit' => $hasFinalCertKeyword,
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
