<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\Support\AiValueNormalizer;

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

    public const STATUS_PENDING = 'pending';
    public const FIELD_PHASE = 'phase';
    public const FIELD_STATUS = 'status';
    public const FIELD_AUTONOMY_LEVEL = 'autonomy_level';
    public const FIELD_INTENT = 'intent';
    public const FIELD_OUTPUT_PHASES = 'output_phases';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_EVIDENCE = 'evidence';
    public const FIELD_SIMULATION = 'simulation';
    public const FIELD_REVIEW = 'review';
    public const FIELD_SWARM = 'swarm';
    public const FIELD_SPEC = 'spec';

    /** @var list<string> */
    public const EXECUTION_PHASES = [
        self::FIELD_SPEC,
        self::FIELD_SIMULATION,
        self::FIELD_SWARM,
        self::FIELD_EVIDENCE,
        self::FIELD_REVIEW,
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
            static fn (string $phase): array => [self::FIELD_PHASE => $phase, self::FIELD_STATUS => self::STATUS_PENDING],
            self::EXECUTION_PHASES,
        );

        return new self('', 'L0', $phases);
    }

    /**
     * Observe-friendly constructor from a loose map. Unknown/empty autonomy
     * falls back to L0; empty output_phases use the default pending ladder.
     *
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $default = self::defaultShape();
        $autonomy = AiValueNormalizer::trimmedStringOrNull($input[self::FIELD_AUTONOMY_LEVEL] ?? null) ?? '';
        $phases = self::normalizePhases(AiValueNormalizer::arrayOrEmpty($input[self::FIELD_OUTPUT_PHASES] ?? null));

        return new self(
            AiValueNormalizer::trimmedStringOrNull($input[self::FIELD_INTENT] ?? null) ?? '',
            $autonomy !== '' ? $autonomy : $default->autonomyLevel,
            $phases !== [] ? $phases : $default->outputPhases,
        );
    }

    /**
     * @param  array<mixed>  $raw
     * @return list<array{phase: string, status: string}>
     */
    private static function normalizePhases(array $raw): array
    {
        $phases = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $phase = AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_PHASE] ?? null);
            $status = AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_STATUS] ?? null);
            if ($phase === null || $status === null) {
                continue;
            }
            $phases[] = [self::FIELD_PHASE => $phase, self::FIELD_STATUS => $status];
        }

        return $phases;
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
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_INTENT => $this->intent,
            self::FIELD_AUTONOMY_LEVEL => $this->autonomyLevel,
            self::FIELD_OUTPUT_PHASES => $this->outputPhases,
        ];
    }
}
