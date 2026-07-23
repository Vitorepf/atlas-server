<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Fail-closed contract-drift detector: compression must never proceed on the strength of ONE
 * source of truth about a contract's shape. Documentation, tests, and observed runtime behavior
 * are three independent witnesses — when they disagree about what a contract actually is, that
 * disagreement itself is the signal that compression is unsafe until reconciled, regardless of
 * which source is "probably right".
 *
 * Input shape:
 *   { documented_contracts: array<string,mixed>, test_contracts: array<string,mixed>,
 *     runtime_contracts: array<string,mixed> }
 * Each contract source is an (optionally nested) associative array describing the same
 * capability's facts (e.g. method signatures, return shapes, field lists). Nested arrays are
 * compared recursively via dot-notation paths (e.g. `response.fields.id`).
 *
 * DRIFT: a path is in drift when the three sources do not all agree on its value — including when
 * a path exists in one source but is absent from another (a source is silent on something another
 * source claims is part of the contract). Every drift path names ALL THREE observed values (a
 * sentinel string when a source doesn't declare that path at all) — never a boolean-only label.
 *
 * COMPRESSION APPROVAL: 'approved' only when zero drift paths exist; 'blocked' otherwise.
 *
 * Pure: no I/O, no provider calls, no queue mutation.
 * @unwired-until 2026-08-05 (Obra #7 W2: capability testada aguardando consumidor; triagem 2026-07-06)
 */
final class AtlasExternalBrainContractDriftDetector
{
    public const SCHEMA = 'atlas.external_brain.contract_drift_detector.v1';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_BLOCKED = 'blocked';

    private const MISSING_SENTINEL = '__missing__';

    /**
     * @param  array{documented_contracts?: array<string,mixed>, test_contracts?: array<string,mixed>, runtime_contracts?: array<string,mixed>}  $input
     * @return array{schema:string, drift_detected:bool, drift_paths:list<array<string,mixed>>, compression_approval:string}
     */
    public function detect(array $input): array
    {
        $documented = $this->flatten(is_array($input['documented_contracts'] ?? null) ? $input['documented_contracts'] : []);
        $tests = $this->flatten(is_array($input['test_contracts'] ?? null) ? $input['test_contracts'] : []);
        $runtime = $this->flatten(is_array($input['runtime_contracts'] ?? null) ? $input['runtime_contracts'] : []);

        $allPaths = array_values(array_unique(array_merge(
            array_keys($documented),
            array_keys($tests),
            array_keys($runtime),
        )));
        sort($allPaths, SORT_STRING);

        $driftPaths = [];
        foreach ($allPaths as $path) {
            $docValue = array_key_exists($path, $documented) ? $documented[$path] : self::MISSING_SENTINEL;
            $testValue = array_key_exists($path, $tests) ? $tests[$path] : self::MISSING_SENTINEL;
            $runtimeValue = array_key_exists($path, $runtime) ? $runtime[$path] : self::MISSING_SENTINEL;

            $distinct = array_unique(array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : (string) json_encode($v),
                [$docValue, $testValue, $runtimeValue],
            ));

            if (count($distinct) <= 1) {
                continue;
            }

            $driftPaths[] = [
                'path' => $path,
                'documented' => $docValue,
                'test' => $testValue,
                'runtime' => $runtimeValue,
            ];
        }

        $driftDetected = $driftPaths !== [];

        return [
            'schema' => self::SCHEMA,
            'drift_detected' => $driftDetected,
            'drift_paths' => $driftPaths,
            'compression_approval' => $driftDetected ? self::APPROVAL_BLOCKED : self::APPROVAL_APPROVED,
        ];
    }

    /**
     * @param  array<string,mixed>  $contract
     * @return array<string,mixed>
     */
    private function flatten(array $contract, string $prefix = ''): array
    {
        $flat = [];
        foreach ($contract as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value) && $value !== [] && array_is_list($value) === false) {
                $flat = array_merge($flat, $this->flatten($value, $path));

                continue;
            }
            $flat[$path] = $value;
        }

        return $flat;
    }
}
