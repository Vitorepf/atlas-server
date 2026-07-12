<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use Throwable;

final class Maxa04JinaV3DualReadLedger
{
    public const SCHEMA = 'atlas.semantic.jina_v3_dual_read.v1';

    public const RELATIVE_PATH = 'app/atlas/evidence/maxa04-jina-v3-dual-read.jsonl';

    public function __construct(private readonly ?string $path = null) {}

    /** @param array<string,mixed> $receipt */
    public function append(array $receipt): void
    {
        try {
            (new JsonlReceiptStore($this->resolvedPath()))->appendSilently(
                $receipt,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    public function path(): string
    {
        return $this->resolvedPath();
    }

    private function resolvedPath(): string
    {
        return $this->path
            ?? (string) config('atlas.semantic_memory.jina_v3_dual_read_ledger_path', storage_path(self::RELATIVE_PATH));
    }
}
