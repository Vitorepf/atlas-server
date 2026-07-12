<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\AtomicWriter;
use App\Services\Ai\Rivals\Support\RunPaths;
use InvalidArgumentException;

/** Immutable, content-addressed unit/repository snapshot for a Rivals run. */
final class FrozenUnitManifest
{
    public const SCHEMA = 'atlas.rivals2.frozen_units.v1';

    private function __construct(public readonly array $data) {}

    /** @param list<array<string,mixed>> $units */
    public static function fromPlan(RunPlan $plan, array $units, bool $allowSynthetic = false): self
    {
        $expected = array_values(array_map('strval', $plan->data['case_ids']));
        $provided = array_values(array_map(
            static fn (array $unit): string => (string) ($unit['case_id'] ?? ''),
            $units,
        ));
        sort($expected);
        sort($provided);
        if ($expected !== $provided) {
            throw new InvalidArgumentException('frozen_unit_case_set_mismatch');
        }
        if (trim((string) ($plan->data['preregistration_hash'] ?? '')) === '') {
            throw new InvalidArgumentException('frozen_unit_preregistration_missing');
        }

        $byCase = [];
        foreach ($units as $unit) {
            $caseId = (string) ($unit['case_id'] ?? '');
            $baseSha = (string) ($unit['base_sha'] ?? '');
            $goldenSha = (string) ($unit['golden_sha'] ?? '');
            $synthetic = false;
            if ($allowSynthetic && (! preg_match('/^[0-9a-f]{40}$/', $baseSha) || ! preg_match('/^[0-9a-f]{40}$/', $goldenSha))) {
                $baseSha = sha1('synthetic-base|'.$caseId);
                $goldenSha = sha1('synthetic-golden|'.$caseId);
                $synthetic = true;
            }
            if (! preg_match('/^[0-9a-f]{40}$/', $baseSha) || ! preg_match('/^[0-9a-f]{40}$/', $goldenSha)) {
                throw new InvalidArgumentException('frozen_unit_repo_snapshot_invalid:'.$caseId);
            }
            $hiddenTests = array_values(array_map('strval', (array) data_get($unit, 'changed_files.tests', [])));
            if ($allowSynthetic && $hiddenTests === []) {
                $hiddenTests = ['synthetic-hidden-proof:'.$caseId];
                $synthetic = true;
            }
            if ($hiddenTests === []) {
                throw new InvalidArgumentException('frozen_unit_hidden_proof_missing:'.$caseId);
            }
            sort($hiddenTests);
            $byCase[$caseId] = [
                'case_id' => $caseId,
                'unit_hash' => self::hashPayload([
                    'case_id' => $caseId,
                    'base_sha' => $baseSha,
                    'golden_sha' => $goldenSha,
                    'hidden_tests' => $hiddenTests,
                ]),
                'base_sha' => $baseSha,
                'golden_sha' => $goldenSha,
                'synthetic' => $synthetic,
                'hidden_tests_hash' => hash('sha256', json_encode($hiddenTests, JSON_UNESCAPED_SLASHES)),
                'repetition_ids' => range(1, (int) $plan->data['repetitions']),
            ];
        }
        ksort($byCase);

        $payload = [
            'schema_version' => self::SCHEMA,
            'run_id' => $plan->runId(),
            'revision' => 1,
            'preregistration_hash' => (string) $plan->data['preregistration_hash'],
            'suite_id' => (string) $plan->data['suite_id'],
            'case_set_hash' => self::hashPayload($expected),
            'units' => array_values($byCase),
            'frozen_at' => now()->toIso8601String(),
        ];
        $payload['manifest_hash'] = self::hashPayload($payload);

        return new self($payload);
    }

    public static function load(string $runId): self
    {
        $path = RunPaths::unitFreezePath($runId);
        if (! is_file($path)) {
            throw new InvalidArgumentException('frozen_unit_manifest_not_found:'.$runId);
        }

        $data = json_decode((string) file_get_contents($path), true) ?? [];
        if (($data['manifest_hash'] ?? null) !== self::hashPayload($data)) {
            throw new InvalidArgumentException('frozen_unit_manifest_hash_mismatch');
        }

        return new self($data);
    }

    public function persist(): string
    {
        $path = RunPaths::unitFreezePath((string) $this->data['run_id']);
        if (is_file($path)) {
            $existing = self::load((string) $this->data['run_id']);
            if ($existing->hash() !== $this->hash()) {
                throw new InvalidArgumentException('frozen_unit_manifest_immutable');
            }

            return $path;
        }
        RunPaths::ensureDir(dirname($path));
        AtomicWriter::write($path, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    public function hash(): string
    {
        return (string) $this->data['manifest_hash'];
    }

    private static function hashPayload(mixed $payload): string
    {
        if (is_array($payload)) {
            unset($payload['manifest_hash']);
            if (array_is_list($payload)) {
                $payload = array_map(self::canonicalize(...), $payload);
            } else {
                ksort($payload);
                $payload = array_map(self::canonicalize(...), $payload);
            }
        }

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonicalize(...), $value);
    }
}
