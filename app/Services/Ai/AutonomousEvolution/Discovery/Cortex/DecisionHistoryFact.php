<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex;

use JsonSerializable;

final class DecisionHistoryFact implements JsonSerializable
{
    /**
     * @param  list<array{sha:string, subject:string, decided_on:int, keyword_hit:bool}>  $decisions
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $filePath,
        public readonly int $commitCount,
        public readonly array $decisions,
    ) {
    }

    /**
     * @return array{fqcn:string, file_path:string, commit_count:int, decisions:list<array{sha:string, subject:string, decided_on:int, keyword_hit:bool}>}
     */
    public function toArray(): array
    {
        return [
            'fqcn' => $this->fqcn,
            'file_path' => $this->filePath,
            'commit_count' => $this->commitCount,
            'decisions' => $this->decisions,
        ];
    }

    /**
     * @return array{fqcn:string, file_path:string, commit_count:int, decisions:list<array{sha:string, subject:string, decided_on:int, keyword_hit:bool}>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
