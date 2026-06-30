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

    /**
     * @param  list<array<string,mixed>>  $packets  Each: objective, acceptance_criteria, allowed_files
     * @return array{schema_version:string, similarity_score:float, repeated_stems:list<string>, repeated_acceptance_fragments:list<string>, blocking:bool, packet_count:int}
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

        $score = round(max($packetsWithRepeatedStem / $total, $packetsWithRepeatedFrag / $total), 3);

        return [
            'schema_version' => self::SCHEMA,
            'similarity_score' => $score,
            'repeated_stems' => $repeatedStems,
            'repeated_acceptance_fragments' => $repeatedFragments,
            'blocking' => $score >= self::BLOCKING_THRESHOLD,
            'packet_count' => $total,
        ];
    }

    private function extractStem(string $objective): string
    {
        $cleaned = preg_replace(self::CLASS_NAME_PATTERN, '', $objective) ?? $objective;
        $cleaned = strtolower(trim((string) preg_replace('/\s+/', ' ', $cleaned)));
        $words = array_values(array_filter(explode(' ', $cleaned), static fn (string $w): bool => strlen($w) > self::MIN_WORD_LEN));

        return implode(' ', array_slice($words, 0, self::STEM_WORD_COUNT));
    }

    private function extractFragment(string $criterion): string
    {
        $cleaned = preg_replace(self::CLASS_NAME_PATTERN, '', $criterion) ?? $criterion;
        $cleaned = strtolower(trim((string) preg_replace('/\s+/', ' ', $cleaned)));

        return substr($cleaned, 0, self::FRAGMENT_MAX_LEN);
    }
}
