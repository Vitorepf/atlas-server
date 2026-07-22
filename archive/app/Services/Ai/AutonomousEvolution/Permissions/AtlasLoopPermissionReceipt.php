<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Permissions;

/**
 * Immutable value object capturing one Enforcer decision (allow or deny).
 *
 * Fields (exactly 8, fixed lexical order on serialization):
 *   attempted_level, caller_chokepoint, decision, master_switch_state,
 *   phase, reason, required_level, timestamp
 */
final readonly class AtlasLoopPermissionReceipt
{
    public const DECISION_ALLOW = 'allow';

    public const DECISION_DENY = 'deny';

    public function __construct(
        public string $timestamp,
        public string $phase,
        public string $attemptedLevel,
        public string $requiredLevel,
        public string $decision,
        public string $reason,
        public bool $masterSwitchState,
        public string $callerChokepoint,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'timestamp' => $this->timestamp,
            'phase' => $this->phase,
            'attempted_level' => $this->attemptedLevel,
            'required_level' => $this->requiredLevel,
            'decision' => $this->decision,
            'reason' => $this->reason,
            'master_switch_state' => $this->masterSwitchState,
            'caller_chokepoint' => $this->callerChokepoint,
        ];
        ksort($payload, SORT_STRING);

        return $payload;
    }

    public function toJsonLine(): string
    {
        return (string) json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
