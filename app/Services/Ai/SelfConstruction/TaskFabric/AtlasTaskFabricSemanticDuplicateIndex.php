<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure index: detects semantically duplicate task intent across queued specs and
 * candidate packets without relying on providers or file paths.
 *
 * Similarity = intent_jaccard × 0.5 + acceptance_verb_jaccard × 0.3 + tag_jaccard × 0.2
 * Duplicate threshold: >= 0.60
 *
 * Complement guard (prevents false-blocking): tasks are complementary when
 *   - their unlock_chains are non-empty and differ, OR
 *   - one uses "must not" acceptance polarity and the other does not, OR
 *   - their evidence_floor values differ.
 */
final class AtlasTaskFabricSemanticDuplicateIndex
{
    public const SCHEMA = 'atlas.task_fabric.semantic_duplicate_index.v1';

    public const DUPLICATE_THRESHOLD = 0.60;

    private const CLASS_PATTERN = '/\b[A-Z][A-Za-z]{4,}\b/';

    private const STOP_WORDS = ['and', 'the', 'for', 'that', 'this', 'with', 'from', 'into', 'when', 'then', 'each', 'are', 'its', 'per', 'via', 'not', 'its', 'has', 'can', 'will'];

    private const INTENT_WEIGHT = 0.5;

    private const ACCEPTANCE_WEIGHT = 0.3;

    private const TAG_WEIGHT = 0.2;

    /**
     * @param  array<string,mixed>  $input  queued_specs + candidate_packets
     * @return array<string,mixed>
     */
    public function check(array $input): array
    {
        $queuedSpecs = is_array($input['queued_specs'] ?? null) ? $input['queued_specs'] : [];
        $candidates = is_array($input['candidate_packets'] ?? null) ? $input['candidate_packets'] : [];

        $queuedFp = array_map([$this, 'fingerprint'], $queuedSpecs);
        $candidateFp = array_map([$this, 'fingerprint'], $candidates);

        $duplicateFlags = [];
        $flaggedIndices = [];

        foreach ($candidates as $i => $candidate) {
            $cf = $candidateFp[$i];

            // Check against queued specs
            foreach ($queuedSpecs as $j => $queued) {
                $score = $this->similarity($cf, $queuedFp[$j]);
                $isComplement = $this->isComplement($candidate, $queued);
                $capabilityCollision = ! $isComplement && $this->hasCapabilityAndFileCollision($candidate, $queued);
                if (($score >= self::DUPLICATE_THRESHOLD || $capabilityCollision) && ! $isComplement) {
                    $duplicateFlags[] = [
                        'candidate_index' => $i,
                        'matched_against' => 'queued',
                        'matched_index' => $j,
                        'similarity_score' => round($score, 3),
                        'reason' => $capabilityCollision && $score < self::DUPLICATE_THRESHOLD
                            ? 'same capability intent and overlapping allowed_files as queued task'
                            : 'objective intent and acceptance shape overlap with queued task',
                    ];
                    $flaggedIndices[$i] = true;
                    break;
                }
            }

            if (isset($flaggedIndices[$i])) {
                continue;
            }

            // Check against earlier candidates in same batch
            for ($j = 0; $j < $i; $j++) {
                $score = $this->similarity($cf, $candidateFp[$j]);
                $isComplement = $this->isComplement($candidate, $candidates[$j]);
                $capabilityCollision = ! $isComplement && $this->hasCapabilityAndFileCollision($candidate, $candidates[$j]);
                if (($score >= self::DUPLICATE_THRESHOLD || $capabilityCollision) && ! $isComplement) {
                    $duplicateFlags[] = [
                        'candidate_index' => $i,
                        'matched_against' => 'batch',
                        'matched_index' => $j,
                        'similarity_score' => round($score, 3),
                        'reason' => $capabilityCollision && $score < self::DUPLICATE_THRESHOLD
                            ? 'same capability intent and overlapping allowed_files as sibling candidate'
                            : 'objective intent and acceptance shape overlap with sibling candidate',
                    ];
                    $flaggedIndices[$i] = true;
                    break;
                }
            }
        }

        $cleanCandidates = array_values(array_filter(
            array_keys($candidates),
            static fn (int $i): bool => ! isset($flaggedIndices[$i]),
        ));

        return [
            'schema_version' => self::SCHEMA,
            'duplicate_flags' => $duplicateFlags,
            'clean_candidates' => $cleanCandidates,
            'flagged_count' => count($flaggedIndices),
            'clean_count' => count($cleanCandidates),
        ];
    }

    private function fingerprint(array $packet): array
    {
        return [
            'intent_words' => $this->extractIntentWords((string) ($packet['objective'] ?? '')),
            'acceptance_verbs' => $this->extractAcceptanceVerbs(
                is_array($packet['acceptance_criteria'] ?? null) ? $packet['acceptance_criteria'] : [],
            ),
            'capability_tags' => array_map('strtolower', is_array($packet['capability_tags'] ?? null) ? $packet['capability_tags'] : []),
        ];
    }

    private function extractIntentWords(string $objective): array
    {
        $cleaned = preg_replace(self::CLASS_PATTERN, '', $objective) ?? $objective;
        $cleaned = strtolower(trim((string) preg_replace('/\s+/', ' ', $cleaned)));
        $words = array_filter(
            explode(' ', $cleaned),
            static fn (string $w): bool => strlen($w) > 2 && ! in_array($w, self::STOP_WORDS, true),
        );

        return array_values(array_unique(array_values($words)));
    }

    private function extractAcceptanceVerbs(array $criteria): array
    {
        $verbs = [];
        foreach ($criteria as $c) {
            if (preg_match_all('/must\s+(not\s+)?(\w+)/i', (string) $c, $m)) {
                foreach ($m[2] as $k => $verb) {
                    $verbs[] = strtolower(($m[1][$k] !== '' ? 'not_' : '').$verb);
                }
            }
        }

        return array_values(array_unique($verbs));
    }

    private function jaccard(array $a, array $b): float
    {
        if ($a === [] && $b === []) {
            return 0.0;
        }
        $inter = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union === 0 ? 0.0 : $inter / $union;
    }

    private function similarity(array $a, array $b): float
    {
        return $this->jaccard($a['intent_words'], $b['intent_words']) * self::INTENT_WEIGHT
            + $this->jaccard($a['acceptance_verbs'], $b['acceptance_verbs']) * self::ACCEPTANCE_WEIGHT
            + $this->jaccard($a['capability_tags'], $b['capability_tags']) * self::TAG_WEIGHT;
    }

    /**
     * True when two packets share the same SPECIFIC capability_intent (not a broad subsystem
     * label from capability_tags) AND their allowed_files overlap — a near-duplicate even when
     * the objective-text similarity score doesn't cross the jaccard threshold.
     */
    private function hasCapabilityAndFileCollision(array $a, array $b): bool
    {
        $aIntent = strtolower(trim((string) ($a['capability_intent'] ?? '')));
        $bIntent = strtolower(trim((string) ($b['capability_intent'] ?? '')));
        if ($aIntent === '' || $bIntent === '' || $aIntent !== $bIntent) {
            return false;
        }

        $aFiles = array_map('strval', is_array($a['allowed_files'] ?? null) ? $a['allowed_files'] : []);
        $bFiles = array_map('strval', is_array($b['allowed_files'] ?? null) ? $b['allowed_files'] : []);

        return array_intersect($aFiles, $bFiles) !== [];
    }

    private function isComplement(array $a, array $b): bool
    {
        $aUnlocks = array_map('strtolower', is_array($a['unlock_chain'] ?? null) ? $a['unlock_chain'] : []);
        $bUnlocks = array_map('strtolower', is_array($b['unlock_chain'] ?? null) ? $b['unlock_chain'] : []);
        if ($aUnlocks !== [] && $bUnlocks !== [] && $aUnlocks !== $bUnlocks) {
            return true;
        }

        $aText = strtolower(implode(' ', is_array($a['acceptance_criteria'] ?? null) ? $a['acceptance_criteria'] : []));
        $bText = strtolower(implode(' ', is_array($b['acceptance_criteria'] ?? null) ? $b['acceptance_criteria'] : []));
        if (str_contains($aText, 'must not') !== str_contains($bText, 'must not')) {
            return true;
        }

        $aFloor = (string) ($a['evidence_floor'] ?? '');
        $bFloor = (string) ($b['evidence_floor'] ?? '');
        if ($aFloor !== '' && $bFloor !== '' && $aFloor !== $bFloor) {
            return true;
        }

        return false;
    }
}
