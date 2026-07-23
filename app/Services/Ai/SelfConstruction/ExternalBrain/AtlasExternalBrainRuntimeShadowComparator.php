<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Proof gate for high-risk simplification: compares a STRUCTURED shadow observation
 * (output, errors, evidence_hash) against the expected behavior receipt for the same case.
 * Replay fixtures alone are not enough for high-risk compression — a shadow run catches
 * runtime-shaped divergence a static fixture can't (timing-dependent output, environment-shaped
 * errors, drifted evidence).
 *
 * FAIL CLOSED: a missing shadow observation is never treated as "presumably fine" — it is an
 * explicit mismatch. Approval requires every declared case to match on all three fields; a
 * single field diverging in a single case blocks the whole comparison, with the EXACT mismatch
 * path(s) reported (never a bare boolean shadow_ok).
 *
 * Input contract:
 *   {cases: list<{
 *     case_id: string,
 *     expected: {output?:mixed, errors?:list<string>, evidence_hash?:string},
 *     shadow?:  {output?:mixed, errors?:list<string>, evidence_hash?:string},
 *   }>}
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainRuntimeShadowComparator
{
    public const SCHEMA = 'atlas.external_brain.runtime_shadow_comparator.v1';

    /**
     * @param  array{cases?: list<array<string,mixed>>}  $facts
     * @return array{schema:string, approved:bool, case_results:list<array<string,mixed>>, reasons:list<string>}
     */
    public function compare(array $facts): array
    {
        $cases = (array) ($facts['cases'] ?? []);
        $caseResults = [];
        $reasons = [];

        if ($cases === []) {
            return [
                'schema' => self::SCHEMA,
                'approved' => false,
                'case_results' => [],
                'reasons' => ['no_cases_declared'],
            ];
        }

        foreach ($cases as $case) {
            $caseId = (string) ($case['case_id'] ?? 'unknown');
            $expected = is_array($case['expected'] ?? null) ? $case['expected'] : [];
            $shadow = $case['shadow'] ?? null;

            if (! is_array($shadow)) {
                $caseResults[] = [
                    'case_id' => $caseId,
                    'approved' => false,
                    'mismatch_paths' => ['shadow_observation_missing'],
                ];

                continue;
            }

            $mismatchPaths = $this->mismatchPaths($expected, $shadow);

            $caseResults[] = [
                'case_id' => $caseId,
                'approved' => $mismatchPaths === [],
                'mismatch_paths' => $mismatchPaths,
            ];
        }

        $approved = true;
        foreach ($caseResults as $result) {
            if (! $result['approved']) {
                $approved = false;
                foreach ($result['mismatch_paths'] as $path) {
                    $reasons[] = "{$result['case_id']}:{$path}";
                }
            }
        }

        return [
            'schema' => self::SCHEMA,
            'approved' => $approved,
            'case_results' => $caseResults,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array<string,mixed>  $expected
     * @param  array<string,mixed>  $shadow
     * @return list<string>
     */
    private function mismatchPaths(array $expected, array $shadow): array
    {
        $mismatches = [];

        $expectedOutput = $expected['output'] ?? null;
        $shadowOutput = $shadow['output'] ?? null;
        if ($this->normalize($expectedOutput) !== $this->normalize($shadowOutput)) {
            $mismatches[] = 'output';
        }

        $expectedErrors = array_values(array_map('strval', (array) ($expected['errors'] ?? [])));
        $shadowErrors = array_values(array_map('strval', (array) ($shadow['errors'] ?? [])));
        sort($expectedErrors, SORT_STRING);
        sort($shadowErrors, SORT_STRING);
        if ($expectedErrors !== $shadowErrors) {
            $mismatches[] = 'errors';
        }

        $expectedHash = (string) ($expected['evidence_hash'] ?? '');
        $shadowHash = (string) ($shadow['evidence_hash'] ?? '');
        if ($expectedHash === '' || $expectedHash !== $shadowHash) {
            $mismatches[] = 'evidence_hash';
        }

        return $mismatches;
    }

    private function normalize(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
