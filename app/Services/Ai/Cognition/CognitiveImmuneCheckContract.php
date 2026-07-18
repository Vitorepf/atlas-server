<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Minimal PSR-4 data contract for cognitive immune checks on autonomous
 * engineering decisions. Step-1 shape only; gate evaluation wiring lands later.
 */
final class CognitiveImmuneCheckContract
{
    public const SCHEMA = 'atlas.cognition.cognitive_immune_check.v1';

    /** Canonical G0-G8 gate ids aligned with AtlasCognitionScoreCardService. */
    public const GATE_IDS = ['G0', 'G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8'];

    /** Finding detail categories for autonomous engineering decision checks. */
    public const CHECK_CATEGORIES = [
        self::FIELD_DRIFT,
        self::FIELD_CONTRADICTION,
        self::FIELD_BIAS,
        self::FIELD_HALLUCINATED_AUTHORITY,
        self::FIELD_SCOPE_CREEP,
    ];

    public const GATE_STATUS_PENDING = 'pending';

    public const GATE_STATUS_PASS = 'pass';

    public const GATE_STATUS_BLOCK = 'block';

    public const GATE_STATUS_UNKNOWN = 'unknown';

    public const DEFAULT_GATE_STATUS = self::GATE_STATUS_PENDING;
    public const FIELD_FINDING_ID = 'finding_id';
    public const FIELD_DECISION_SURFACE = 'decision_surface';
    public const FIELD_TARGET_PATHS = 'target_paths';
    public const FIELD_GATE_STATUSES = 'gate_statuses';
    public const FIELD_AUTONOMOUS_EXECUTION_ALLOWED = 'autonomous_execution_allowed';
    public const FIELD_BLOCKERS = 'blockers';
    public const FIELD_CHECK_CATEGORIES = 'check_categories';
    public const FIELD_PENDING_GATES = 'pending_gates';
    public const FIELD_INPUTS = 'inputs';
    public const FIELD_OUTPUTS = 'outputs';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_AUTONOMOUS_ENGINEERING = 'autonomous_engineering';
    public const FIELD_CONTRADICTION = 'contradiction';
    public const FIELD_BIAS = 'bias';
    public const FIELD_SCOPE_CREEP = 'scope_creep';
    public const FIELD_HALLUCINATED_AUTHORITY = 'hallucinated_authority';
    public const FIELD_DRIFT = 'drift';

    public const ALLOWED_GATE_STATUSES = [
        self::GATE_STATUS_PENDING,
        self::GATE_STATUS_PASS,
        self::GATE_STATUS_BLOCK,
        self::GATE_STATUS_UNKNOWN,
    ];

    /**
     * @param  list<string>  $targetPaths
     * @param  array<string,string>  $gateStatuses
     * @param  list<string>  $checkCategories
     * @param  list<string>  $blockers
     */
    private function __construct(
        public readonly string $findingId,
        public readonly string $decisionSurface,
        public readonly array $targetPaths,
        public readonly array $gateStatuses,
        public readonly array $checkCategories,
        public readonly array $blockers,
        public readonly bool $autonomousExecutionAllowed,
    ) {}

    public static function defaults(
        string $findingId = '',
        string $decisionSurface = self::FIELD_AUTONOMOUS_ENGINEERING,
    ): self {
        return new self(
            findingId: $findingId,
            decisionSurface: $decisionSurface,
            targetPaths: [],
            gateStatuses: array_fill_keys(self::GATE_IDS, self::DEFAULT_GATE_STATUS),
            checkCategories: self::CHECK_CATEGORIES,
            blockers: [],
            autonomousExecutionAllowed: false,
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $gateStatuses = array_fill_keys(self::GATE_IDS, self::DEFAULT_GATE_STATUS);
        foreach (AiValueNormalizer::arrayOrEmpty($input[self::FIELD_GATE_STATUSES] ?? null) as $gateId => $status) {
            $gateKey = AiValueNormalizer::trimmedStringOrNull($gateId);
            if ($gateKey === null || ! in_array($gateKey, self::GATE_IDS, true)) {
                continue;
            }

            $normalized = AiValueNormalizer::lowerTrimmedString($status);
            if (in_array($normalized, self::ALLOWED_GATE_STATUSES, true)) {
                $gateStatuses[$gateKey] = $normalized;
            }
        }

        $targetPaths = array_values(array_filter(
            array_map(static fn ($path): string => AiValueNormalizer::trimmedStringOrNull($path) ?? '', AiValueNormalizer::arrayOrEmpty($input[self::FIELD_TARGET_PATHS] ?? null)),
            static fn (string $path): bool => $path !== '',
        ));

        $blockers = array_values(array_filter(
            array_map(static fn ($blocker): string => AiValueNormalizer::trimmedStringOrNull($blocker) ?? '', AiValueNormalizer::arrayOrEmpty($input[self::FIELD_BLOCKERS] ?? null)),
            static fn (string $blocker): bool => $blocker !== '',
        ));

        return new self(
            findingId: AiValueNormalizer::trimmedStringOrNull($input[self::FIELD_FINDING_ID] ?? null) ?? '',
            decisionSurface: AiValueNormalizer::trimmedStringOrNull($input[self::FIELD_DECISION_SURFACE] ?? null) ?? self::FIELD_AUTONOMOUS_ENGINEERING,
            targetPaths: $targetPaths,
            gateStatuses: $gateStatuses,
            checkCategories: self::CHECK_CATEGORIES,
            blockers: $blockers,
            autonomousExecutionAllowed: (AiValueNormalizer::boolOrNull($input[self::FIELD_AUTONOMOUS_EXECUTION_ALLOWED] ?? null) ?? false),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA,
            self::FIELD_FINDING_ID => $this->findingId,
            self::FIELD_DECISION_SURFACE => $this->decisionSurface,
            self::FIELD_INPUTS => [
                self::FIELD_TARGET_PATHS => $this->targetPaths,
                self::FIELD_GATE_STATUSES => $this->gateStatuses,
                self::FIELD_CHECK_CATEGORIES => $this->checkCategories,
            ],
            self::FIELD_OUTPUTS => [
                self::FIELD_AUTONOMOUS_EXECUTION_ALLOWED => $this->autonomousExecutionAllowed,
                self::FIELD_BLOCKERS => $this->blockers,
                self::FIELD_PENDING_GATES => array_keys(array_filter(
                    $this->gateStatuses,
                    static fn (string $status): bool => $status === self::DEFAULT_GATE_STATUS,
                )),
            ],
        ];
    }
}
