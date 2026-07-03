<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;
use InvalidArgumentException;

/** Plano imutável de um run: suite × cases × arms × repetitions × budget × seed. */
class RunPlan
{
    private function __construct(public readonly array $data)
    {
    }

    public static function make(
        string $suiteId,
        array $caseIds,
        array $arms,
        int $repetitions,
        array $budget,
        int $seed,
        ?array $judgeConfig = null,
    ): self {
        $data = [
            'schema_version' => SchemaContract::RUN_PLAN,
            'run_id' => self::newRunId(),
            'suite_id' => $suiteId,
            'case_ids' => array_values($caseIds),
            'arms' => array_values($arms),
            'repetitions' => $repetitions,
            'budget' => $budget + ['max_usd' => $budget['max_usd'] ?? 0.0, 'max_minutes' => $budget['max_minutes'] ?? 0],
            'seed' => $seed,
            'environment' => [
                'php' => PHP_VERSION,
                'os' => PHP_OS_FAMILY,
                'hostname' => gethostname() ?: 'unknown',
            ],
            'judge_config' => $judgeConfig,
            'created_at' => now()->toIso8601String(),
        ];

        $violations = SchemaContract::validate($data, SchemaContract::RUN_PLAN);
        if ($violations !== []) {
            throw new InvalidArgumentException('rivals_invalid_plan: '.implode(',', $violations));
        }

        return new self($data);
    }

    public static function fromArray(array $data): self
    {
        $violations = SchemaContract::validate($data, SchemaContract::RUN_PLAN);
        if ($violations !== []) {
            throw new InvalidArgumentException('rivals_invalid_plan: '.implode(',', $violations));
        }

        return new self($data);
    }

    public static function load(string $runId): self
    {
        $path = RunPaths::planPath($runId);
        if (! is_file($path)) {
            throw new InvalidArgumentException("rivals_plan_not_found:{$runId}");
        }

        return self::fromArray(json_decode(file_get_contents($path), true) ?? []);
    }

    public function persist(): string
    {
        $runId = $this->data['run_id'];
        RunPaths::ensureDir(RunPaths::runDir($runId));
        file_put_contents(
            RunPaths::planPath($runId),
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $runId;
    }

    public function runId(): string
    {
        return $this->data['run_id'];
    }

    /** case×arm×rep esperados — o Adjudicator exige receipt para cada um. */
    public function expectedReceiptKeys(): array
    {
        $keys = [];
        foreach ($this->data['case_ids'] as $caseId) {
            foreach ($this->data['arms'] as $arm) {
                for ($rep = 1; $rep <= $this->data['repetitions']; $rep++) {
                    $keys[] = "{$caseId}|{$arm['arm_id']}|{$rep}";
                }
            }
        }

        return $keys;
    }

    private static function newRunId(): string
    {
        // sortável por tempo p/ RunPaths::latestRunId()
        return now()->format('Ymd_His').'_'.substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
