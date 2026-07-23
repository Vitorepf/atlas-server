<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Services\Ai\Support\AiValueNormalizer;

final class AtlasPhaseRouterService
{
    public const FIELD_POLICY_GATE = 'policy_gate';
    public const FIELD_RECEIPT = 'receipt';
    public const SCHEMA_VERSION = 'atlas.aaeos.phase_router.v1';

    public const PHASE_LEGACY = 'legacy';

    public const PHASE_1 = '1';

    public const PHASE_2 = '2';

    public const PHASE_3 = '3';

    public const PHASE_4 = '4';

    public const HTTP_PATH_PHASE_CONFIG_KEY = 'atlas.aaeos.http_path_phase';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_CONFIGURED_PHASE = 'configured_phase';
    public const FIELD_IS_VALID = 'is_valid';
    public const FIELD_IS_ACTIVE = 'is_active';
    public const FIELD_IS_LEGACY = 'is_legacy';
    public const FIELD_DESCRIPTION = 'description';
    public const FIELD_VALID_PHASES = 'valid_phases';
    public const FIELD_PHASE_CAPABILITIES = 'phase_capabilities';
    public const FIELD_INTENT_CAPTURE = 'intent_capture';
    public const FIELD_DISAMBIGUATION = 'disambiguation';
    public const FIELD_CLASSIFICATION = 'classification';
    public const FIELD_PLACEMENT = 'placement';
    public const FIELD_ROUTING = 'routing';
    public const FIELD_SPEC = 'spec';
    public const FIELD_TASKS = 'tasks';
    public const FIELD_TOPOLOGY = 'topology';
    public const FIELD_INVALID_AAEOS_HTTP_PATH_PHASE_ = 'Invalid AAEOS HTTP path phase.';
    public const FIELD_LEGACY_HTTP_PATH__AAEOS_FACADE_INACTIVE_ = 'Legacy HTTP path; AAEOS facade inactive.';
    public const FIELD_PHASE_1__PLACEMENT_GATE_ACTIVE_ = 'Phase 1; placement gate active.';
    public const FIELD_PHASE_2__CLASSIFICATION_AND_POLICY_GATES_ACTIVE_ = 'Phase 2; classification and policy gates active.';
    public const FIELD_PHASE_3__TOPOLOGY_AND_ROUTING_ENVELOPES_ACTIVE_ = 'Phase 3; topology and routing envelopes active.';
    public const FIELD_PHASE_4__SPEC__TASKS_AND_RECEIPT_ENVELOPES_ACTIVE_ = 'Phase 4; spec, tasks and receipt envelopes active.';
    public const INT_4 = 4;
    public const INT_2 = 2;
    public const INT_3 = 3;

    public const VALID_PHASES = [
        self::PHASE_LEGACY,
        self::PHASE_1,
        self::PHASE_2,
        self::PHASE_3,
        self::PHASE_4,
    ];

    public const ACTIVE_PHASE_RANKS = [
        self::PHASE_1 => 1,
        self::PHASE_2 => self::INT_2,
        self::PHASE_3 => self::INT_3,
        self::PHASE_4 => self::INT_4,
    ];

    public const PHASE_DESCRIPTIONS = [
        self::PHASE_LEGACY => self::FIELD_LEGACY_HTTP_PATH__AAEOS_FACADE_INACTIVE_,
        self::PHASE_1 => self::FIELD_PHASE_1__PLACEMENT_GATE_ACTIVE_,
        self::PHASE_2 => self::FIELD_PHASE_2__CLASSIFICATION_AND_POLICY_GATES_ACTIVE_,
        self::PHASE_3 => self::FIELD_PHASE_3__TOPOLOGY_AND_ROUTING_ENVELOPES_ACTIVE_,
        self::PHASE_4 => self::FIELD_PHASE_4__SPEC__TASKS_AND_RECEIPT_ENVELOPES_ACTIVE_,
    ];

    private readonly string $configuredPhase;

    public function __construct(?string $configuredPhase = null)
    {
        $resolved = $configuredPhase !== null
            ? (AiValueNormalizer::trimmedStringOrNull($configuredPhase) ?? '')
            : (AiValueNormalizer::trimmedStringOrNull(config(self::HTTP_PATH_PHASE_CONFIG_KEY, self::PHASE_LEGACY)) ?? '');
        $this->configuredPhase = $resolved !== '' ? $resolved : self::PHASE_LEGACY;
    }

    public static function isValidPhase(string $phase): bool
    {
        return in_array(AiValueNormalizer::trimmedStringOrNull($phase) ?? '', self::VALID_PHASES, true);
    }

    public static function isActivePhase(string $phase): bool
    {
        return array_key_exists(AiValueNormalizer::trimmedStringOrNull($phase) ?? '', self::ACTIVE_PHASE_RANKS);
    }

    public static function phaseAtLeast(string $configuredPhase, string $threshold): bool
    {
        $configuredRank = self::ACTIVE_PHASE_RANKS[AiValueNormalizer::trimmedStringOrNull($configuredPhase) ?? ''] ?? null;
        $thresholdRank = self::ACTIVE_PHASE_RANKS[AiValueNormalizer::trimmedStringOrNull($threshold) ?? ''] ?? null;

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
        return self::PHASE_DESCRIPTIONS[$this->configuredPhase] ?? self::FIELD_INVALID_AAEOS_HTTP_PATH_PHASE_;
    }

    /**
     * @return array<string,mixed>
     */
    public function statusSnapshot(): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_CONFIGURED_PHASE => $this->configuredPhase,
            self::FIELD_IS_VALID => $this->isValid(),
            self::FIELD_IS_ACTIVE => $this->isActive(),
            self::FIELD_IS_LEGACY => $this->isLegacy(),
            self::FIELD_DESCRIPTION => $this->describePhase(),
            self::FIELD_VALID_PHASES => self::VALID_PHASES,
            self::FIELD_PHASE_CAPABILITIES => [
                self::FIELD_INTENT_CAPTURE => $this->isActive(),
                self::FIELD_DISAMBIGUATION => $this->isActive(),
                self::FIELD_PLACEMENT => $this->atLeastPhase1(),
                self::FIELD_CLASSIFICATION => $this->atLeastPhase2(),
                self::FIELD_POLICY_GATE => $this->atLeastPhase2(),
                self::FIELD_TOPOLOGY => $this->atLeastPhase3(),
                self::FIELD_ROUTING => $this->atLeastPhase3(),
                self::FIELD_SPEC => $this->atLeastPhase4(),
                self::FIELD_TASKS => $this->atLeastPhase4(),
                self::FIELD_RECEIPT => $this->atLeastPhase4(),
            ],
        ];
    }
}
