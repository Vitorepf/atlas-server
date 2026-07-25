<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Pure signal extractors / ratio helpers for template-farm similarity.
 *
 * No FS, DI, or I/O — used by
 * {@see \App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricTemplateFarmSimilarityGate}
 * which keeps batch orchestration and the assess() verdict surface.
 */
final class TaskFabricTemplateFarmSignalSupport
{
    public const STEM_WORD_COUNT = 8;

    private const CLASS_NAME_PATTERN = '/\b[A-Z][A-Za-z]{4,}\b/';

    private const FRAGMENT_MAX_LEN = 60;

    private const MIN_WORD_LEN = 2;

    private const PROOF_PATH_PATTERN = '/--filter=\s*[A-Za-z0-9_]+|\bexits?\s*0\b|\bphp artisan test\b/i';

    private const ACCEPTANCE_VERB_PATTERN = '/\b(?:must|shall|will)\s+([a-z]+)\b/i';

    private function __construct()
    {
    }

    /**
     * @param  list<string>  $proofPaths
     */
    public static function mechanismHash(string $stem, array $proofPaths): string
    {
        sort($proofPaths);

        return hash('sha256', $stem.'|'.implode(',', $proofPaths));
    }

    /**
     * Generic "one signal value per packet" repetition ratio (used by the allowed_files shape
     * signal). Null/empty values never count toward repetition.
     *
     * @param  list<?string>  $values
     * @return array{0:list<string>,1:int}
     */
    public static function repeatedSignalRatio(array $values): array
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
    public static function repeatedMultiSignalRatio(array $perPacketValues): array
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
    public static function extractAllowedFilesShape(array $allowedFiles): ?string
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
    public static function extractProofPaths(array $acceptanceCriteria): array
    {
        $paths = [];
        foreach ($acceptanceCriteria as $criterion) {
            $criterion = (string) $criterion;
            if (preg_match(self::PROOF_PATH_PATTERN, $criterion) !== 1) {
                continue;
            }
            $paths[] = self::extractProofPathFragment($criterion);
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
    public static function extractAcceptanceVerbs(array $acceptanceCriteria): array
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

    /**
     * All single-word-masked variants of a stem — two stems sharing any masked variant differ by
     * exactly one word (the disguised noun) and are the same underlying template.
     *
     * @return list<string>
     */
    public static function extractTemplateSignatures(string $stem): array
    {
        if ($stem === '') {
            return [];
        }

        $words = explode(' ', $stem);
        $wordCount = count($words);
        if ($wordCount < 3) {
            return [];
        }

        $signatures = [];
        for ($i = 0; $i < $wordCount; $i++) {
            $masked = $words;
            $masked[$i] = '*';
            $signatures[] = implode(' ', $masked);
        }

        return $signatures;
    }

    public static function extractStem(string $objective): string
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
    public static function extractProofPathFragment(string $criterion): string
    {
        $withoutFilterValue = preg_replace('/--filter=\S+/', '--filter=', $criterion) ?? $criterion;

        return self::extractFragment($withoutFilterValue);
    }

    public static function extractFragment(string $criterion): string
    {
        $cleaned = preg_replace(self::CLASS_NAME_PATTERN, '', $criterion) ?? $criterion;
        $cleaned = strtolower(trim((string) preg_replace('/\s+/', ' ', $cleaned)));

        return substr($cleaned, 0, self::FRAGMENT_MAX_LEN);
    }

    /**
     * @param  list<string>  $repeatedMechanismHashes
     */
    public static function replacementHint(array $repeatedMechanismHashes): string
    {
        return $repeatedMechanismHashes !== []
            ? 'Multiple packets share a mechanism hash (same stem + proof shape). Renaming the target class or rewording the objective will not clear this — propose a genuinely different leverage mechanism (different failure mode, different proof path, different structural approach) for the duplicate packets.'
            : 'This batch is a disguised template farm. Renaming the target class or rewording the objective will not clear this — propose a genuinely different leverage mechanism (different failure mode, different proof path, different structural approach) for the repeated packets.';
    }
}
