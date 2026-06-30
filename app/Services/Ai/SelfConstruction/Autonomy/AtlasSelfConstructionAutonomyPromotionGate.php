<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Autonomy;

/**
 * Evidence-only promotion gate for the Self-Construction autonomy ladder.
 *
 * Verdict is derived from explicit facts — never a single scalar score, never a black-box LLM call.
 * Atlas-native target levels (atlas_native_bounded, atlas_native_24_7) ALSO require the ladder's
 * `final_runtime_owner` to be `atlas_native` for the target level; any other owner is a hard refuse.
 */
final class AtlasSelfConstructionAutonomyPromotionGate
{
    public const SCHEMA = 'atlas.self_construction.autonomy_promotion_gate.v1';

    public const VERDICT_PROMOTE = 'promote';

    public const VERDICT_HOLD = 'hold';

    public const VERDICT_REFUSE = 'refuse';

    public const REQUIRED_FACT_KEYS = [
        'queue_health_green',
        'native_implementation_ready',
        'verification_court_green',
        'merge_governor_green',
        'rollback_proven',
        'learning_transfer_green',
        'knowledge_sync_green',
    ];

    /** Additional facts required when promoting to atlas_native_24_7. */
    public const REQUIRED_FACT_KEYS_24_7 = [
        'unattended_liveness_green',
        'recovery_action_green',
        'no_human_dependency_green',
    ];

    /** @var \Closure(string):array<string,mixed> */
    private \Closure $describeLevel;

    /**
     * @param  (\Closure(string):array<string,mixed>)|null  $levelDescriberOverride  test hook — when null, uses the ladder.
     */
    public function __construct(
        private readonly AtlasSelfConstructionAutonomyLevelLadder $ladder,
        ?\Closure $levelDescriberOverride = null,
    ) {
        $this->describeLevel = $levelDescriberOverride ?? fn (string $level): array => $this->ladder->describe($level);
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function evaluate(string $fromLevel, string $toLevel, array $facts): array
    {
        $transition = $this->ladder->transition($fromLevel, $toLevel);
        if (! $transition['allowed']) {
            return $this->envelope(self::VERDICT_REFUSE, $fromLevel, $toLevel, [
                'refusal_reason' => (string) $transition['reason'],
            ]);
        }

        $atlasNativeTarget = in_array($toLevel, [
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_BOUNDED,
            AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_24_7,
        ], true);

        if ($atlasNativeTarget) {
            $targetDescription = ($this->describeLevel)($toLevel);
            $owner = (string) ($targetDescription['final_runtime_owner'] ?? '');
            if ($owner !== AtlasSelfConstructionAutonomyLevelLadder::FINAL_OWNER_ATLAS_NATIVE) {
                return $this->envelope(self::VERDICT_REFUSE, $fromLevel, $toLevel, [
                    'refusal_reason' => 'final_runtime_owner_not_atlas_native',
                    'observed_final_runtime_owner' => $owner,
                ]);
            }
        }

        $keysToCheck = $toLevel === AtlasSelfConstructionAutonomyLevelLadder::LEVEL_ATLAS_NATIVE_24_7
            ? array_merge(self::REQUIRED_FACT_KEYS, self::REQUIRED_FACT_KEYS_24_7)
            : self::REQUIRED_FACT_KEYS;

        $missing = [];
        $failing = [];
        foreach ($keysToCheck as $key) {
            if (! array_key_exists($key, $facts)) {
                $missing[] = $key;

                continue;
            }
            if ($facts[$key] !== true) {
                $failing[] = $key;
            }
        }

        if ($missing !== [] || $failing !== []) {
            return $this->envelope(self::VERDICT_HOLD, $fromLevel, $toLevel, [
                'missing_fact_keys' => $missing,
                'failing_fact_keys' => $failing,
            ]);
        }

        return $this->envelope(self::VERDICT_PROMOTE, $fromLevel, $toLevel, [
            'satisfied_fact_keys' => $keysToCheck,
        ]);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function envelope(string $verdict, string $from, string $to, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA,
            'verdict' => $verdict,
            'from_level' => $from,
            'to_level' => $to,
            'required_fact_keys' => self::REQUIRED_FACT_KEYS,
        ], $extra);
    }
}
