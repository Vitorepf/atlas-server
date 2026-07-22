<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\LiveCycle\FactPassing;

final readonly class AtlasLoopPhaseBoundaryFactValidationResult
{
    /**
     * @param  list<string>  $missingKeys
     * @param  list<array{key:string,expected_type:string,actual_type:string}>  $typeMismatches
     * @param  list<string>  $unknownKeys
     */
    public function __construct(
        public bool $ok,
        public array $missingKeys,
        public array $typeMismatches,
        public array $unknownKeys,
        public ?string $reason = null,
    ) {}
}
