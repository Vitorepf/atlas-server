<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class AreaFocusAdmissionCandidateNormalizer
{
    /**
     * @param  array<string, mixed>  $candidate
     */
    public static function level(array $candidate): string
    {
        $explicit = self::upperToken($candidate['level'] ?? null);

        if ($explicit !== '') {
            return $explicit;
        }

        return self::splitId($candidate)[0];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    public static function phase(array $candidate): string
    {
        $explicit = self::upperToken($candidate['phase'] ?? null);

        if ($explicit !== '') {
            return $explicit;
        }

        return self::splitId($candidate)[1];
    }

    /**
     * The candidate's declared kind, normalized to a lower-case underscore token.
     *
     * @param  array<string, mixed>  $candidate
     */
    public static function kind(array $candidate): string
    {
        $raw = $candidate['kind'] ?? $candidate['work_kind'] ?? $candidate['phase'] ?? null;
        $explicit = self::lowerSnakeToken($raw);

        if ($explicit !== '') {
            return $explicit;
        }

        return self::lowerSnakeToken(self::splitId($candidate)[1]);
    }

    /**
     * Split a combined identifier such as "L8-P5" / "l10_spec".
     *
     * @param  array<string, mixed>  $candidate
     * @return array{0: string, 1: string}
     */
    public static function splitId(array $candidate): array
    {
        $raw = $candidate['id'] ?? $candidate['candidate_id'] ?? $candidate['candidate'] ?? null;

        if (! is_string($raw)) {
            return ['', ''];
        }

        $normalized = strtoupper(trim($raw));
        $parts = preg_split('/[^A-Z0-9]+/', $normalized, 2, PREG_SPLIT_NO_EMPTY);

        if ($parts === false || $parts === []) {
            return ['', ''];
        }

        return [
            $parts[0],
            $parts[1] ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    public static function candidateId(string $level, string $tail, array $candidate): string
    {
        if ($level !== '' && $tail !== '') {
            return $level.'-'.$tail;
        }

        if ($level !== '') {
            return $level;
        }

        $raw = $candidate['id'] ?? $candidate['candidate_id'] ?? $candidate['candidate'] ?? null;

        return is_string($raw) && trim($raw) !== '' ? strtoupper(trim($raw)) : 'unknown';
    }

    /**
     * @param  list<mixed>|array<string, mixed>  $completed
     * @param  array<string, mixed>  $phasePrerequisites
     * @return list<string>
     */
    public static function completedPhases(array $completed, array $phasePrerequisites): array
    {
        $phases = [];

        foreach (self::uniqueUpperTokens($completed) as $token) {
            $parts = preg_split('/[^A-Z0-9]+/', $token, -1, PREG_SPLIT_NO_EMPTY);

            if ($parts === false) {
                continue;
            }

            foreach ($parts as $part) {
                if (array_key_exists($part, $phasePrerequisites) && ! in_array($part, $phases, true)) {
                    $phases[] = $part;
                }
            }
        }

        return $phases;
    }

    /**
     * @param  list<mixed>|array<string, mixed>  $entries
     * @return list<string>
     */
    public static function uniqueUpperTokens(array $entries): array
    {
        $ids = [];

        foreach ($entries as $entry) {
            $token = self::upperToken($entry);

            if ($token === '' || in_array($token, $ids, true)) {
                continue;
            }

            $ids[] = $token;
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  list<string>  $completedIds
     * @return list<string>
     */
    public static function missingDependencies(array $candidate, array $completedIds): array
    {
        $declared = $candidate['depends_on'] ?? $candidate['dependencies'] ?? [];

        if (! is_array($declared)) {
            return [];
        }

        $missing = [];

        foreach ($declared as $dependency) {
            $token = self::upperToken($dependency);

            if ($token === '') {
                continue;
            }

            if (! in_array($token, $completedIds, true)) {
                $missing[] = $token;
            }
        }

        return $missing;
    }

    public static function upperToken(mixed $value): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return '';
        }

        return strtoupper(trim($value));
    }

    public static function lowerSnakeToken(mixed $value): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return '';
        }

        $lower = strtolower(trim($value));

        if ($lower === '') {
            return '';
        }

        $collapsed = preg_replace('/[^a-z0-9]+/', '_', $lower);

        if (! is_string($collapsed)) {
            return '';
        }

        return trim($collapsed, '_');
    }
}
