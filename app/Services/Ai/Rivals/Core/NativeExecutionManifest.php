<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Contracts\BenchmarkSuiteAdapter;
use App\Services\Ai\Rivals\Support\RunPaths;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Immutable bridge between a Rivals plan and suite-native execution.
 *
 * One entry represents exactly one case × arm × repetition. Native runners may
 * batch entries, but they must return evidence for every execution_id.
 */
final class NativeExecutionManifest
{
    public const SCHEMA = 'atlas.rivals2.native_execution_manifest.v1';

    private function __construct(public readonly array $data) {}

    /** @param array<int, array<string, mixed>> $commands */
    public static function fromPlan(
        RunPlan $plan,
        BenchmarkSuiteAdapter $adapter,
        array $commands,
    ): self {
        $arms = collect($plan->data['arms'])->keyBy('arm_id');
        $entryBudget = count($commands) > 0
            ? ((float) ($plan->data['budget']['max_usd'] ?? 0.0)) / count($commands)
            : 0.0;
        $entries = [];
        foreach ($commands as $command) {
            $caseId = (string) ($command['case_id'] ?? '');
            $armId = (string) ($command['arm_id'] ?? '');
            $repetition = (int) ($command['repetition'] ?? 0);
            if ($caseId === '' || $armId === '' || $repetition < 1) {
                throw new InvalidArgumentException('rivals_native_manifest_invalid_command_identity');
            }
            $arm = (array) ($arms[$armId] ?? []);
            $executionId = self::executionId(
                $plan->runId(),
                $caseId,
                $armId,
                $repetition,
            );
            $expectedPath = (string) ($command['output_path']
                ?? self::unitResultPath($caseId, $armId, $repetition));
            $nativeCommand = (string) ($command['command'] ?? '');
            $argv = array_values((array) ($command['argv'] ?? []));
            if ($nativeCommand === ''
                || $argv === []
                || count(array_filter($argv, 'is_string')) !== count($argv)) {
                throw new InvalidArgumentException("rivals_native_manifest_command_missing:{$executionId}");
            }
            $entries[] = [
                'execution_id' => $executionId,
                'case_id' => $caseId,
                'arm_id' => $armId,
                'model_id' => (string) ($arm['model_id'] ?? ''),
                'runtime' => (string) ($arm['runtime'] ?? ''),
                'cli_model' => (string) ($arm['native_model'] ?? $arm['cli_model'] ?? ''),
                'atlas_cli_model' => (string) ($arm['cli_model'] ?? ''),
                'native_model' => (string) ($arm['native_model'] ?? $arm['cli_model'] ?? ''),
                'native_agent' => $arm['native_agent'] ?? null,
                'native_binding_id' => $command['native_binding_id']
                    ?? $arm['native_binding_id']
                    ?? null,
                'repetition' => $repetition,
                'seed' => self::seedFor($plan->data['seed'], $caseId, $armId, $repetition),
                'argv' => $argv,
                'command' => $nativeCommand,
                'command_hash' => hash('sha256', self::canonicalJson($argv)),
                'expected_result_path' => $expectedPath,
                'normalization' => (array) ($command['normalization'] ?? []),
                'max_usd' => round($entryBudget, 8),
                'max_seconds' => max(1, (int) ($plan->data['budget']['max_minutes'] ?? 0) * 60),
                'status' => 'planned',
            ];
        }

        $reflection = new ReflectionClass($adapter);
        $sourcePath = $reflection->getFileName();
        $payload = [
            'schema_version' => self::SCHEMA,
            'run_id' => $plan->runId(),
            'suite_id' => $adapter->suiteId(),
            'claim_tier' => (string) $plan->data['claim_tier'],
            'plan_hash' => is_file(RunPaths::planPath($plan->runId()))
                ? hash_file('sha256', RunPaths::planPath($plan->runId()))
                : hash('sha256', self::canonicalJson($plan->data)),
            'adapter_class' => $adapter::class,
            'adapter_hash' => is_string($sourcePath) && is_file($sourcePath)
                ? hash_file('sha256', $sourcePath)
                : hash('sha256', $adapter::class),
            'runner_version' => 'rivals-native-manifest-v1',
            'budget' => $plan->data['budget'],
            'provider_spend_approved' => (bool) (
                $plan->data['environment']['approve_provider_spend'] ?? false
            ),
            'upstream' => [
                'source_repo' => $plan->data['environment']['source_repo'] ?? $adapter->suiteId(),
                'repo_commit' => $plan->data['environment']['repo_commit'] ?? null,
            ],
            'expected_executions' => count($entries),
            'entries' => $entries,
            'created_at' => now()->toIso8601String(),
        ];
        $payload['manifest_hash'] = self::hashPayload($payload);

        return self::fromArray($payload);
    }

    public static function fromArray(array $data): self
    {
        foreach ([
            'schema_version', 'run_id', 'suite_id', 'claim_tier', 'plan_hash',
            'adapter_class', 'adapter_hash', 'runner_version', 'budget',
            'provider_spend_approved', 'upstream', 'expected_executions',
            'entries', 'created_at', 'manifest_hash',
        ] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidArgumentException("rivals_native_manifest_missing:{$field}");
            }
        }
        if ($data['schema_version'] !== self::SCHEMA) {
            throw new InvalidArgumentException('rivals_native_manifest_schema_mismatch');
        }
        ClaimTier::assert((string) $data['claim_tier']);
        if (! is_array($data['entries'])
            || (int) $data['expected_executions'] !== count($data['entries'])) {
            throw new InvalidArgumentException('rivals_native_manifest_execution_count_mismatch');
        }
        foreach ($data['entries'] as $index => $entry) {
            foreach ([
                'execution_id', 'case_id', 'arm_id', 'repetition', 'argv',
                'command_hash', 'expected_result_path', 'max_usd', 'max_seconds',
            ] as $field) {
                if (! array_key_exists($field, (array) $entry)) {
                    throw new InvalidArgumentException(
                        "rivals_native_manifest_entry_missing:{$index}:{$field}"
                    );
                }
            }
            $argv = (array) $entry['argv'];
            if ($argv === []
                || count(array_filter($argv, 'is_string')) !== count($argv)
                || ! hash_equals(
                    (string) $entry['command_hash'],
                    hash('sha256', self::canonicalJson(array_values($argv))),
                )) {
                throw new InvalidArgumentException(
                    "rivals_native_manifest_entry_command_invalid:{$index}"
                );
            }
            $resultPath = (string) $entry['expected_result_path'];
            if (! str_starts_with($resultPath, 'external_results/units/')
                || str_contains($resultPath, '..')
                || str_starts_with($resultPath, '/')) {
                throw new InvalidArgumentException(
                    "rivals_native_manifest_result_path_invalid:{$index}"
                );
            }
        }
        $executionIds = array_column($data['entries'], 'execution_id');
        $resultPaths = array_column($data['entries'], 'expected_result_path');
        if (count($executionIds) !== count(array_unique($executionIds))) {
            throw new InvalidArgumentException('rivals_native_manifest_duplicate_execution_id');
        }
        if (count($resultPaths) !== count(array_unique($resultPaths))) {
            throw new InvalidArgumentException('rivals_native_manifest_duplicate_result_path');
        }
        if (! hash_equals((string) $data['manifest_hash'], self::hashPayload($data))) {
            throw new InvalidArgumentException('rivals_native_manifest_hash_mismatch');
        }

        return new self($data);
    }

    public static function load(string $runId): self
    {
        $path = RunPaths::nativeManifestPath($runId);
        if (! is_file($path)) {
            throw new InvalidArgumentException("rivals_native_manifest_not_found:{$runId}");
        }

        return self::fromArray(json_decode((string) file_get_contents($path), true) ?? []);
    }

    public function persist(): string
    {
        RunPaths::ensureDir(RunPaths::runDir((string) $this->data['run_id']));
        RunPaths::ensureDir(RunPaths::nativeResultsDir((string) $this->data['run_id']));
        RunPaths::ensureDir(RunPaths::nativeReceiptsDir((string) $this->data['run_id']));
        $path = RunPaths::nativeManifestPath((string) $this->data['run_id']);
        file_put_contents(
            $path,
            json_encode(
                $this->data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            )
        );

        return $path;
    }

    public function hash(): string
    {
        return (string) $this->data['manifest_hash'];
    }

    /** @return list<array<string, mixed>> */
    public function entries(): array
    {
        return array_values($this->data['entries']);
    }

    public static function unitResultPath(string $caseId, string $armId, int $repetition): string
    {
        $identity = "{$caseId}|{$armId}|{$repetition}";
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '_', "{$caseId}__{$armId}")
            ?: 'execution';

        return 'external_results/units/'
            .substr($slug, 0, 120)
            .'__r'.$repetition
            .'__'.substr(hash('sha256', $identity), 0, 12)
            .'.json';
    }

    public static function executionId(
        string $runId,
        string $caseId,
        string $armId,
        int $repetition,
    ): string {
        return 'ne_'.substr(hash(
            'sha256',
            "{$runId}|{$caseId}|{$armId}|{$repetition}",
        ), 0, 24);
    }

    public static function seedFor(
        mixed $baseSeed,
        string $caseId,
        string $armId,
        int $repetition,
    ): int {
        return (int) sprintf('%u', crc32(
            (string) $baseSeed."|{$caseId}|{$armId}|{$repetition}",
        ));
    }

    /** @param array<string, mixed> $payload */
    private static function hashPayload(array $payload): string
    {
        unset($payload['manifest_hash']);

        return hash('sha256', self::canonicalJson($payload));
    }

    private static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ) ?: '';
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
