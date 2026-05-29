<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

/**
 * Immutable data contract for Reality Compiler slices — intent → governed
 * AAEOS execution packets traversing spec, simulation, swarm, evidence, review.
 *
 * Step 1 contract only: no compilation behavior or service wiring yet.
 *
 * Provenance: salvaged from the AP-790 autonomous loop run 2026-05-29
 * (finding slice_dfc2c2532043ee96). The loop's provider produced this contract but
 * placed the class inside AutonomousWorkExecutionOs.php (two classes in one file =
 * PSR-4 violation), so the multi-agent workcell judge correctly returned
 * repair_required. Extracted to its own file under operator review — the judge's
 * repair (proper file placement) is what makes it mergeable.
 */
final readonly class RealityCompilerSlice
{
    public const SCHEMA_VERSION = 'atlas.reality_compiler.slice.v1';

    /** @var list<string> */
    public const EXECUTION_PHASES = [
        'spec',
        'simulation',
        'swarm',
        'evidence',
        'review',
    ];

    /**
     * @param  list<array{phase: string, status: string}>  $outputPhases
     */
    public function __construct(
        public string $intent,
        public string $autonomyLevel,
        public array $outputPhases,
    ) {}

    public static function defaultShape(): self
    {
        $phases = array_map(
            static fn (string $phase): array => ['phase' => $phase, 'status' => 'pending'],
            self::EXECUTION_PHASES,
        );

        return new self('', 'L0', $phases);
    }

    /**
     * @return array{
     *   schema_version: string,
     *   intent: string,
     *   autonomy_level: string,
     *   output_phases: list<array{phase: string, status: string}>,
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'intent' => $this->intent,
            'autonomy_level' => $this->autonomyLevel,
            'output_phases' => $this->outputPhases,
        ];
    }
}
