<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure gate: detects template-farm repetition across a batch of packets by comparing
 * normalised objective stems and acceptance fragments.
 *
 * Class-name tokens (CamelCase ≥5 chars) are stripped before comparison so that
 * "Implement AtlasFooBar" and "Implement AtlasBazQux" with the same surrounding
 * verb+context collapse to the same stem.
 *
 * similarity_score = max(repeated-stem-packet-ratio, repeated-fragment-packet-ratio)
 * blocking = score >= BLOCKING_THRESHOLD (0.7)
 *
 * A coherent macro-batch where each packet has a distinct proof path but shares a
 * common research thesis will produce low repeated-fragment coverage and pass.
 */
final class AtlasTaskFabricTemplateFarmSimilarityGate
{
    public const SCHEMA = 'atlas.task_fabric.template_farm_similarity_gate.v1';

    public const BLOCKING_THRESHOLD = 0.7;

    public const STEM_WORD_COUNT = 8;

    private const CLASS_NAME_PATTERN = '/\b[A-Z][A-Za-z]{4,}\b/';

    private const FRAGMENT_MAX_LEN = 60;

    private const MIN_WORD_LEN = 2;

    private const PROOF_PATH_PATTERN = '/--filter=\s*[A-Za-z0-9_]+|\bexits?\s*0\b|\bphp artisan test\b/i';

    private const ACCEPTANCE_VERB_PATTERN = '/\b(?:must|shall|will)\s+([a-z]+)\b/i';

    /**
     * @param  list<array<string,mixed>>  $packets  Each: objective, acceptance_criteria, allowed_files
     * @return array{schema_version:string, similarity_score:float, repeated_stems:list<string>, repeated_acceptance_fragments:list<string>, repeated_allowed_files_shapes:list<string>, repeated_proof_paths:list<string>, repeated_acceptance_verbs:list<string>, blocking:bool, packet_count:int}
     */
    public function assess(array $packets): array
    {
        $total = count($packets);

        if ($total <= 1) {
            return [
                'schema_version' => self::SCHEMA,
                'similarity_score' => 0.0,
                'repeated_stems' => [],
                'repeated_acceptance_fragments' => [],
                'repeated_allowed_files_shapes' => [],
                'repeated_proof_paths' => [],
                'repeated_acceptance_verbs' => [],
                'blocking' => false,
                'packet_count' => $total,
            ];
        }

        $stems = array_map(fn (array $p): string => $this->extractStem((string) ($p['objective'] ?? '')), $packets);

        $stemCounts = array_count_values($stems);
        $repeatedStems = array_values(array_keys(array_filter($stemCounts, static fn (int $c): bool => $c >= 2)));
        sort($repeatedStems);
        $packetsWithRepeatedStem = (int) array_sum(array_map(
            static fn (string $s): int => ($stemCounts[$s] ?? 0) >= 2 ? 1 : 0,
            $stems,
        ));

        $packetFragments = array_map(function (array $p): array {
            $criteria = is_array($p['acceptance_criteria'] ?? null) ? $p['acceptance_criteria'] : [];
            $frags = array_filter(
                array_unique(array_map(fn (mixed $c): string => $this->extractFragment((string) $c), $criteria)),
                static fn (string $f): bool => strlen($f) >= 5,
            );

            return array_values($frags);
        }, $packets);

        $fragmentOccurrences = [];
        foreach ($packetFragments as $frags) {
            foreach (array_unique($frags) as $frag) {
                $fragmentOccurrences[$frag] = ($fragmentOccurrences[$frag] ?? 0) + 1;
            }
        }

        $repeatedFragments = array_values(array_keys(array_filter($fragmentOccurrences, static fn (int $c): bool => $c >= 2)));
        sort($repeatedFragments);

        $packetsWithRepeatedFrag = 0;
        foreach ($packetFragments as $frags) {
            foreach ($frags as $frag) {
                if (($fragmentOccurrences[$frag] ?? 0) >= 2) {
                    $packetsWithRepeatedFrag++;
                    break;
                }
            }
        }

        $shapes = array_map(fn (array $p): ?string => $this->extractAllowedFilesShape((array) ($p['allowed_files'] ?? [])), $packets);
        [$repeatedShapes, $packetsWithRepeatedShape] = $this->repeatedSignalRatio($shapes);

        $proofPaths = array_map(fn (array $p): array => $this->extractProofPaths(is_array($p['acceptance_criteria'] ?? null) ? $p['acceptance_criteria'] : []), $packets);
        [$repeatedProofPaths, $packetsWithRepeatedProofPath] = $this->repeatedMultiSignalRatio($proofPaths);

        $verbs = array_map(fn (array $p): array => $this->extractAcceptanceVerbs(is_array($p['acceptance_criteria'] ?? null) ? $p['acceptance_criteria'] : []), $packets);
        [$repeatedVerbs, $packetsWithRepeatedVerb] = $this->repeatedMultiSignalRatio($verbs);

        $score = round(max(
            $packetsWithRepeatedStem / $total,
            $packetsWithRepeatedFrag / $total,
            $packetsWithRepeatedShape / $total,
            $packetsWithRepeatedProofPath / $total,
            $packetsWithRepeatedVerb / $total,
        ), 3);

        return [
            'schema_version' => self::SCHEMA,
            'similarity_score' => $score,
            'repeated_stems' => $repeatedStems,
            'repeated_acceptance_fragments' => $repeatedFragments,
            'repeated_allowed_files_shapes' => $repeatedShapes,
            'repeated_proof_paths' => $repeatedProofPaths,
            'repeated_acceptance_verbs' => $repeatedVerbs,
            'blocking' => $score >= self::BLOCKING_THRESHOLD,
            'packet_count' => $total,
        ];
    }

    /**
     * Generic "one signal value per packet" repetition ratio (used by the allowed_files shape
     * signal). Null/empty values never count toward repetition.
     *
     * @param  list<?string>  $values
     * @return array{0:list<string>,1:int}
     */
    private function repeatedSignalRatio(array $values): array
    {
        $counts = array_count_values(array_filter($values, static fn (?string $v): bool => $v !== null && $v !== ''));
        $repeated = array_values(array_keys(array_filter($counts, static fn (int $c): bool => $c >= 2)));
        sort($repeated);

        $packetsWithRepeated = 0;
        foreach ($values as $v) {
            if ($v !== null && $v !== '' && ($counts[$v] ?? 0) >= 2) {
                $packetsWithRepeated++;
            }
        }

        return [$repeated, $packetsWithRepeated];
    }

    /**
     * Generic "one packet may contribute multiple signal values" repetition ratio (used by the
     * proof-path and acceptance-verb signals, since a packet's acceptance_criteria is a list).
     *
     * @param  list<list<string>>  $perPacketValues
     * @return array{0:list<string>,1:int}
     */
    private function repeatedMultiSignalRatio(array $perPacketValues): array
    {
        $occurrences = [];
        foreach ($perPacketValues as $values) {
            foreach (array_unique($values) as $v) {
                $occurrences[$v] = ($occurrences[$v] ?? 0) + 1;
            }
        }

        $repeated = array_values(array_keys(array_filter($occurrences, static fn (int $c): bool => $c >= 2)));
        sort($repeated);

        $packetsWithRepeated = 0;
        foreach ($perPacketValues as $values) {
            foreach (array_unique($values) as $v) {
                if (($occurrences[$v] ?? 0) >= 2) {
                    $packetsWithRepeated++;
                    break;
                }
            }
        }

        return [$repeated, $packetsWithRepeated];
    }

    /**
     * Structural shape of a packet's allowed_files: sorted (directory, extension) pairs with the
     * specific basename stripped. Empty input yields null (excluded from repetition — a missing
     * allowed_files list is not itself a farm signal).
     *
     * @param  list<mixed>  $allowedFiles
     */
    private function extractAllowedFilesShape(array $allowedFiles): ?string
    {
        if ($allowedFiles === []) {
            return null;
        }

        $pairs = [];
        foreach ($allowedFiles as $file) {
            $file = (string) $file;
            if ($file === '') {
                continue;
            }
            $dir = dirname($file);
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            $pairs[] = $dir.':'.$ext;
        }

        if ($pairs === []) {
            return null;
        }

        sort($pairs);

        return implode('|', $pairs);
    }

    /**
     * Acceptance criteria that look like a runnable proof-gate statement (CLI filter, "exits 0",
     * "php artisan test"), normalised with the specific class/filter token redacted so repeated
     * BOILERPLATE structure (not the legitimate target name) is what triggers the signal.
     *
     * @param  list<mixed>  $acceptanceCriteria
     * @return list<string>
     */
    private function extractProofPaths(array $acceptanceCriteria): array
    {
        $paths = [];
        foreach ($acceptanceCriteria as $criterion) {
            $criterion = (string) $criterion;
            if (preg_match(self::PROOF_PATH_PATTERN, $criterion) !== 1) {
                continue;
            }
            $paths[] = $this->extractProofPathFragment($criterion);
        }

        return $paths;
    }

    /**
     * The verb immediately following a modal ("must"/"shall"/"will") in each acceptance
     * criterion — repeated boilerplate verbs across many packets (independent of target names)
     * is a disguised-farm signal distinct from the full-fragment comparison.
     *
     * @param  list<mixed>  $acceptanceCriteria
     * @return list<string>
     */
    private function extractAcceptanceVerbs(array $acceptanceCriteria): array
    {
        $verbs = [];
        foreach ($acceptanceCriteria as $criterion) {
            $criterion = (string) $criterion;
            if (preg_match(self::ACCEPTANCE_VERB_PATTERN, $criterion, $m) === 1) {
                $verbs[] = strtolower($m[1]);
            }
        }

        return $verbs;
    }

    private function extractStem(string $objective): string
    {
        $cleaned = preg_replace(self::CLASS_NAME_PATTERN, '', $objective) ?? $objective;
        $cleaned = strtolower(trim((string) preg_replace('/\s+/', ' ', $cleaned)));
        $words = array_values(array_filter(explode(' ', $cleaned), static fn (string $w): bool => strlen($w) > self::MIN_WORD_LEN));

        return implode(' ', array_slice($words, 0, self::STEM_WORD_COUNT));
    }

    /**
     * Same normalisation as extractFragment(), plus the --filter=<target> value itself redacted
     * (the target token routinely mixes digits with the class name — e.g. AtlasVariant0Test —
     * which the generic CLASS_NAME_PATTERN does not fully strip).
     */
    private function extractProofPathFragment(string $criterion): string
    {
        $withoutFilterValue = preg_replace('/--filter=\S+/', '--filter=', $criterion) ?? $criterion;

        return $this->extractFragment($withoutFilterValue);
    }

    private function extractFragment(string $criterion): string
    {
        $cleaned = preg_replace(self::CLASS_NAME_PATTERN, '', $criterion) ?? $criterion;
        $cleaned = strtolower(trim((string) preg_replace('/\s+/', ' ', $cleaned)));

        return substr($cleaned, 0, self::FRAGMENT_MAX_LEN);
    }
}
