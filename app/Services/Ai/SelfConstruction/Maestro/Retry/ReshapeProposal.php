<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Retry;

final class ReshapeProposal
{
    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $rationale
     */
    public function __construct(
        public readonly array $allowedFiles,
        public readonly array $rationale,
        public readonly string $confidence,
        public readonly bool $empty = false,
    ) {}

    /**
     * @return array{
     *   allowed_files:list<string>,
     *   confidence:string,
     *   empty:bool,
     *   rationale:list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'allowed_files' => $this->allowedFiles,
            'confidence' => $this->confidence,
            'empty' => $this->empty,
            'rationale' => $this->rationale,
        ];
    }

    public static function empty(): self
    {
        return new self([], ['no_anchor_evidence'], 'none', true);
    }
}
