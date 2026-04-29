<?php

namespace App\Services\Digital;

class RizeApiPage
{
    public function __construct(
        public readonly array $records,
        public readonly bool $hasNextPage,
        public readonly ?string $endCursor,
        public readonly array $rawData,
    ) {}
}
