<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Adapters;

/**
 * Shared JSONL ledger mechanics for per-scope append-only NDJSON stores.
 *
 * Domain classes keep schema/payload shaping; this trait only owns root resolution,
 * scope slugification, file path mapping, and bounded tail replay.
 */
trait JsonlLedgerTrait
{
    private readonly string $root;

    /**
     * @return list<array<string,mixed>>
     */
    public function tail(string $scope, int $k = 30): array
    {
        if ($k <= 0) {
            return [];
        }

        return $this->doTail($scope, $k);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function doTail(string $scope, int $k): array
    {
        $out = (new JsonlReceiptStore($this->pathFor($this->slugify(trim($scope)))))->replay();

        return array_values(array_slice($out, -$k));
    }

    private function pathFor(string $scope): string
    {
        return $this->root.'/'.$scope.'.ndjson';
    }

    private function slugify(string $s): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9._-]+/i', '-', trim($s)));
    }
}
