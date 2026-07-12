<?php

declare(strict_types=1);

namespace App\Services\Ai\Compaction;

interface LocalL2SummaryRewriter
{
    /**
     * @param  array<string,mixed>  $context
     * @return array{summary:string,runtime:string,metadata?:array<string,mixed>}
     */
    public function rewrite(string $l1Summary, array $context = []): array;
}
