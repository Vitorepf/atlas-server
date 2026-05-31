<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

final class AtlasAaeosPhaseRouterService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.phase_router.v1';

    public const PHASE_LEGACY = 'legacy';

    public const PHASE_1 = '1';

    public const PHASE_2 = '2';

    public const PHASE_3 = '3';

    public const PHASE_4 = '4';

    public const VALID_PHASES = [
        self::PHASE_LEGACY,
        self::PHASE_1,
        self::PHASE_2,
        self::PHASE_3,
        self::PHASE_4,
    ];

    private const ACTIVE_PHASE_RANKS = [
        self::PHASE_1 => 1,
        self::PHASE_2 => 2,
        self::PHASE_3 => 3,
        self::PHASE_4 => 4,
    ];

    private const PHASE_DESCRIPTIONS = [
        self::PHASE_LEGACY => 'Legacy HTTP path; AAEOS facade inactive.',
        self::PHASE_1 => 'Phase 1; placement gate active.',
        self::PHASE_2 => 'Phase 2; classification and policy gates active.',
        self::PHASE_3 => 'Phase 3; topology and routing envelopes active.',
        self::PHASE_4 => 'Phase 4; spec, tasks and receipt envelopes active.',
    ];

    private readonly string $configuredPhase;

    public function __construct(?string $configuredPhase = null)
    {
        $this->configuredPhase = $configuredPhase ?? (string) config('atlas.aaeos.http_path_phase', self::PHASE_LEGACY);
    }

    public static function isValidPhase(string $phase): bool
    {
        return in_array($phase, self::VALID_PHASES, true);
    }

    public static function isActivePhase(string $phase): bool
    {
        return array_key_exists($phase, self::ACTIVE_PHASE_RANKS);
    }

    public static function phaseAtLeast(string $configuredPhase, string $threshold): bool
    {
        $configuredRank = self::ACTIVE_PHASE_RANKS[$configuredPhase] ?? null;
        $thresholdRank = self::ACTIVE_PHASE_RANKS[$threshold] ?? null;

        return $configuredRank !== null
            && $thresholdRank !== null
            && $configuredRank >= $thresholdRank;
    }

    public function configuredPhase(): string
    {
        return $this->configuredPhase;
    }

    public function isValid(): bool
    {
        return self::isValidPhase($this->configuredPhase);
    }

    public function isActive(): bool
    {
        return self::isActivePhase($this->configuredPhase);
    }

    public function isLegacy(): bool
    {
        return $this->configuredPhase === self::PHASE_LEGACY;
    }

    public function atLeast(string $threshold): bool
    {
        return self::phaseAtLeast($this->configuredPhase, $threshold);
    }

    public function atLeastPhase1(): bool
    {
        return $this->atLeast(self::PHASE_1);
    }

    public function atLeastPhase2(): bool
    {
        return $this->atLeast(self::PHASE_2);
    }

    public function atLeastPhase3(): bool
    {
        return $this->atLeast(self::PHASE_3);
    }

    public function atLeastPhase4(): bool
    {
        return $this->atLeast(self::PHASE_4);
    }

    public function describePhase(): string
    {
        return self::PHASE_DESCRIPTIONS[$this->configuredPhase] ?? 'Invalid AAEOS HTTP path phase.';
    }

    /**
     * @return array<string,mixed>
     */
    public function statusSnapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'configured_phase' => $this->configuredPhase,
            'is_valid' => $this->isValid(),
            'is_active' => $this->isActive(),
            'is_legacy' => $this->isLegacy(),
            'description' => $this->describePhase(),
            'valid_phases' => self::VALID_PHASES,
            'phase_capabilities' => [
                'intent_capture' => $this->isActive(),
                'disambiguation' => $this->isActive(),
                'placement' => $this->atLeastPhase1(),
                'classification' => $this->atLeastPhase2(),
                'policy_gate' => $this->atLeastPhase2(),
                'topology' => $this->atLeastPhase3(),
                'routing' => $this->atLeastPhase3(),
                'spec' => $this->atLeastPhase4(),
                'tasks' => $this->atLeastPhase4(),
                'receipt' => $this->atLeastPhase4(),
            ],
        ];
    }
}
