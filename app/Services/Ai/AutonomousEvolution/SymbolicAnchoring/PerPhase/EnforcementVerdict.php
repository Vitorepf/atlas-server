<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase;

final readonly class EnforcementVerdict
{
    public const REASON_ALLOWED = 'allowed';

    public const REASON_DENSITY_BELOW_FLOOR = 'anchor_density_below_floor';

    public const REASON_MISSING_ANCHOR_KINDS = 'missing_required_anchor_kinds';

    public const REASON_DISABLED = 'per_phase_anchor_gate_disabled';

    /**
     * @param  list<string>  $missingAnchorKinds
     */
    public function __construct(
        public bool $allow,
        public string $reasonCode,
        public float $measuredDensity,
        public float $requiredDensity,
        public array $missingAnchorKinds,
    ) {}

    /**
     * @param  list<string>  $missing
     */
    public static function refuse(string $reasonCode, float $measured, float $required, array $missing = []): self
    {
        return new self(false, $reasonCode, $measured, $required, $missing);
    }

    public static function allow(float $measured, float $required): self
    {
        return new self(true, self::REASON_ALLOWED, $measured, $required, []);
    }

    public static function disabled(): self
    {
        return new self(true, self::REASON_DISABLED, 0.0, 0.0, []);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'allow' => $this->allow,
            'reason_code' => $this->reasonCode,
            'measured_density' => $this->measuredDensity,
            'required_density' => $this->requiredDensity,
            'missing_anchor_kinds' => $this->missingAnchorKinds,
        ];
    }
}
