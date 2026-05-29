<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

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
        'drift',
        'contradiction',
        'bias',
        'hallucinated_authority',
        'scope_creep',
    ];

    public const DEFAULT_GATE_STATUS = 'pending';

    public const ALLOWED_GATE_STATUSES = ['pending', 'pass', 'block', 'unknown'];

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
        string $decisionSurface = 'autonomous_engineering',
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
        foreach ((array) ($input['gate_statuses'] ?? []) as $gateId => $status) {
            if (! is_string($gateId) || ! in_array($gateId, self::GATE_IDS, true)) {
                continue;
            }

            $normalized = strtolower(trim((string) $status));
            if (in_array($normalized, self::ALLOWED_GATE_STATUSES, true)) {
                $gateStatuses[$gateId] = $normalized;
            }
        }

        $targetPaths = array_values(array_filter(
            array_map(static fn ($path): string => trim((string) $path), (array) ($input['target_paths'] ?? [])),
            static fn (string $path): bool => $path !== '',
        ));

        $blockers = array_values(array_filter(
            array_map(static fn ($blocker): string => trim((string) $blocker), (array) ($input['blockers'] ?? [])),
            static fn (string $blocker): bool => $blocker !== '',
        ));

        return new self(
            findingId: trim((string) ($input['finding_id'] ?? '')),
            decisionSurface: trim((string) ($input['decision_surface'] ?? 'autonomous_engineering')),
            targetPaths: $targetPaths,
            gateStatuses: $gateStatuses,
            checkCategories: self::CHECK_CATEGORIES,
            blockers: $blockers,
            autonomousExecutionAllowed: (bool) ($input['autonomous_execution_allowed'] ?? false),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'finding_id' => $this->findingId,
            'decision_surface' => $this->decisionSurface,
            'inputs' => [
                'target_paths' => $this->targetPaths,
                'gate_statuses' => $this->gateStatuses,
                'check_categories' => $this->checkCategories,
            ],
            'outputs' => [
                'autonomous_execution_allowed' => $this->autonomousExecutionAllowed,
                'blockers' => $this->blockers,
                'pending_gates' => array_keys(array_filter(
                    $this->gateStatuses,
                    static fn (string $status): bool => $status === self::DEFAULT_GATE_STATUS,
                )),
            ],
        ];
    }
}
