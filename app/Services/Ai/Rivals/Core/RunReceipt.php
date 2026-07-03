<?php

namespace App\Services\Ai\Rivals2\Core;

use App\Services\Ai\Rivals2\Support\RunPaths;
use App\Services\Ai\Rivals2\Support\SchemaContract;
use InvalidArgumentException;

/**
 * Recibo por case × arm × repetition: status, tempo, tokens, custo e artifacts
 * hash-pinned. Sem receipt não há claim; receipt de falha é medição válida.
 */
class RunReceipt
{
    private function __construct(public readonly array $data)
    {
    }

    public static function fromArray(array $data): self
    {
        $violations = SchemaContract::validate($data, SchemaContract::RUN_RECEIPT);
        if ($violations !== []) {
            throw new InvalidArgumentException('rivals2_invalid_receipt: '.implode(',', $violations));
        }

        return new self($data);
    }

    public function key(): string
    {
        return "{$this->data['case_id']}|{$this->data['arm_id']}|{$this->data['repetition']}";
    }

    public function append(): void
    {
        $runId = $this->data['run_id'];
        RunPaths::ensureDir(RunPaths::runDir($runId));
        file_put_contents(
            RunPaths::receiptsPath($runId),
            json_encode($this->data, JSON_UNESCAPED_SLASHES).PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /** @return array<int, self> */
    public static function loadAll(string $runId): array
    {
        $path = RunPaths::receiptsPath($runId);
        if (! is_file($path)) {
            return [];
        }
        $receipts = [];
        foreach (array_filter(explode(PHP_EOL, file_get_contents($path))) as $line) {
            $receipts[] = self::fromArray(json_decode($line, true) ?? []);
        }

        return $receipts;
    }
}
