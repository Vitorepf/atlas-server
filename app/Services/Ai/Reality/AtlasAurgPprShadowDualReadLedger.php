<?php

declare(strict_types=1);

namespace App\Services\Ai\Reality;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use Throwable;

final class AtlasAurgPprShadowDualReadLedger
{
    public const SCHEMA = 'atlas.aurg.ppr_shadow_dual_read.v1';

    public const RELATIVE_PATH = 'app/atlas/evidence/aurg-ppr-shadow-dual-read.jsonl';

    public function __construct(private readonly ?string $path = null) {}

    /**
     * @param  array<string,mixed>  $receipt
     */
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
            ?? (string) config('atlas.aurg.query_ppr_shadow_ledger_path', storage_path(self::RELATIVE_PATH));
    }
}
