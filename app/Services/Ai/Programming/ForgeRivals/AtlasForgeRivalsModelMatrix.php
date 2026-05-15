<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Atlas Forge Rivals · Model Matrix.
 *
 * Single authority on which model can be used as the Atlas arm or rival arm
 * under each mode. Centralises four rules:
 *
 *   1. Each arm's model must be in {claude_sonnet, claude_opus, codex, auto}.
 *   2. The chosen model must be in the active mode's `allowed_models` list.
 *   3. In `fair` mode both arms MUST be the same model (canonical fairness).
 *   4. `auto` is only valid in `full_power` mode (Atlas chooses topology).
 *   5. `codex` is rival-only at this stage (Atlas arm uses Forge dispatch
 *      with claude-class models; codex-as-Atlas is reserved for a future
 *      slice when the Forge codex adapter is real).
 *
 * NOTE: This is a pure validator — no provider call, no I/O.
 */
final class AtlasForgeRivalsModelMatrix
{
    public const MODEL_CLAUDE_SONNET = 'claude_sonnet';

    public const MODEL_CLAUDE_OPUS = 'claude_opus';

    public const MODEL_CODEX = 'codex';

    public const MODEL_AUTO = 'auto';

    /** @var list<string> */
    public const MODELS = [
        self::MODEL_CLAUDE_SONNET,
        self::MODEL_CLAUDE_OPUS,
        self::MODEL_CODEX,
        self::MODEL_AUTO,
    ];

    /** Models that may only be used on the rival arm in Slice 0–5. */
    public const RIVAL_ONLY_MODELS = [self::MODEL_CODEX];

    public function __construct(
        private readonly AtlasForgeRivalsModeRegistry $modes,
    ) {}

    /**
     * @return array{
     *   ok:bool,
     *   blockers:list<string>,
     *   resolved_atlas:string,
     *   resolved_rival:string,
     *   mode:string,
     *   fair_mode_requires_same_model_on_both_arms:bool,
     *   auto_only_valid_in_full_power_mode:bool
     * }
     */
    public function validate(string $mode, string $atlasModel, string $rivalModel): array
    {
        $modeKey = strtolower(trim($mode));
        $atlas = strtolower(trim($atlasModel));
        $rival = strtolower(trim($rivalModel));
        $blockers = [];

        $modeDef = null;
        try {
            $modeDef = $this->modes->mode($modeKey);
        } catch (\InvalidArgumentException $e) {
            $blockers[] = 'unknown_mode:'.$modeKey;
        }

        foreach (['atlas' => $atlas, 'rival' => $rival] as $arm => $value) {
            if ($value === '') {
                $blockers[] = "{$arm}_model_missing";

                continue;
            }
            if (! in_array($value, self::MODELS, true)) {
                $blockers[] = "{$arm}_model_unknown:{$value}";

                continue;
            }
            if ($modeDef !== null) {
                $allowed = $modeDef['allowed_models'];
                if ($allowed === []) {
                    $blockers[] = "{$arm}_model_not_allowed_in_mode:{$modeKey}";

                    continue;
                }
                if (! in_array($value, $allowed, true)) {
                    $blockers[] = "{$arm}_model_disallowed_in_mode:{$modeKey}:{$value}";
                }
            }
        }

        if ($modeKey === AtlasForgeRivalsModeRegistry::MODE_FAIR
            && $atlas !== ''
            && $rival !== ''
            && $atlas !== $rival
        ) {
            $blockers[] = 'fair_mode_requires_same_model_on_both_arms';
        }

        if (($atlas === self::MODEL_AUTO || $rival === self::MODEL_AUTO)
            && $modeKey !== AtlasForgeRivalsModeRegistry::MODE_FULL_POWER
        ) {
            $blockers[] = 'auto_only_valid_in_full_power_mode';
        }

        if (in_array($atlas, self::RIVAL_ONLY_MODELS, true)) {
            $blockers[] = 'atlas_arm_cannot_use_rival_only_model:'.$atlas;
        }

        return [
            'ok' => $blockers === [],
            'blockers' => $blockers,
            'resolved_atlas' => $atlas,
            'resolved_rival' => $rival,
            'mode' => $modeKey,
            'fair_mode_requires_same_model_on_both_arms' => true,
            'auto_only_valid_in_full_power_mode' => true,
        ];
    }
}
