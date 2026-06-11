<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Support\AiStringListNormalizer;

/**
 * Atlas Forge Rivals · Evidence Policy.
 *
 * Single source of truth for "which artifacts are required vs optional" at
 * each evidence stage. Removes the circular bug where collect-evidence forced
 * `scorecard` into the artifact list before adjudication could create it, and
 * replay then failed with `scorecard:not_present_at_replay`.
 *
 * Two stages exist:
 *   - `pre_adjudication`: collected BEFORE the adjudicator writes the
 *     scorecard. `scorecard` is intentionally NOT listed.
 *   - `final`: collected AFTER adjudication. `scorecard` is required.
 *
 * Required vs optional is decided by (stage, verdict, mode):
 *   - `manifest`, `events_jsonl`, `intent_json`: always required.
 *   - `atlas_receipt`, `rival_receipt`, `workspace_hashes`: required when
 *     verdict=`comparable`; optional when verdict starts with `invalid` or
 *     when verdict is `unknown`/`inconclusive` (the run failed before it
 *     could produce a complete bundle).
 *   - `atlas_patch`, `rival_patch`, `atlas_test_log`, `rival_test_log`:
 *     required when verdict=`comparable` AND the mode actually drove a real
 *     provider (`fair`, `full_power`). For `local_fake` they are optional
 *     because the in-process fake provider does not produce them.
 *   - `scorecard`: present only in stage=`final`; required there. It is the
 *     downstream proof that adjudication ran.
 */
final class AtlasForgeRivalsEvidencePolicy
{
    public const STAGE_PRE_ADJUDICATION = 'pre_adjudication';

    public const STAGE_FINAL = 'final';

    public const POLICY_REQUIRED = 'required';

    public const POLICY_OPTIONAL = 'optional';

    /** @var list<string> */
    public const ALL_STAGES = [
        self::STAGE_PRE_ADJUDICATION,
        self::STAGE_FINAL,
    ];

    /**
     * Normalize a stage string. Defaults to `final` for legacy callers that
     * don't pass a stage (they expect the full bundle, including scorecard).
     */
    public static function normalizeStage(?string $stage): string
    {
        $stage = is_string($stage) ? strtolower(trim($stage)) : '';

        return match ($stage) {
            self::STAGE_PRE_ADJUDICATION => self::STAGE_PRE_ADJUDICATION,
            self::STAGE_FINAL, '' => self::STAGE_FINAL,
            default => self::STAGE_FINAL,
        };
    }

    /**
     * Plan the artifact policy for a (stage, manifest) pair. Returns:
     *   - stage: normalized stage
     *   - required: list<string> of artifact keys
     *   - optional: list<string> of artifact keys
     *   - policy: map<key, 'required'|'optional'>
     *   - is_comparable_real_run: bool
     *   - verdict: string (canonical, lower-case)
     *   - mode: string (canonical, lower-case)
     *
     * @param  array<string,mixed>  $manifest
     * @return array{
     *   stage:string,
     *   required:list<string>,
     *   optional:list<string>,
     *   policy:array<string,string>,
     *   is_comparable_real_run:bool,
     *   verdict:string,
     *   mode:string,
     * }
     */
    public static function plan(string $stage, array $manifest): array
    {
        $stage = self::normalizeStage($stage);
        $verdict = strtolower((string) ($manifest['verdict'] ?? 'unknown'));
        $mode = strtolower((string) ($manifest['mode'] ?? 'unknown'));
        $isInvalid = $verdict === 'unknown'
            || $verdict === 'inconclusive'
            || str_starts_with($verdict, 'invalid');
        $isComparable = $verdict === 'comparable';
        $isRealMode = $mode === AtlasForgeRivalsModeRegistry::MODE_FAIR
            || $mode === AtlasForgeRivalsModeRegistry::MODE_FULL_POWER;
        $isComparableRealRun = $isComparable && $isRealMode;

        $required = ['manifest', 'events_jsonl', 'intent_json'];
        $optional = [];

        if ($isComparable) {
            array_push($required, 'atlas_receipt', 'rival_receipt', 'workspace_hashes');
            if ($isComparableRealRun) {
                array_push(
                    $required,
                    'atlas_patch',
                    'rival_patch',
                    'atlas_test_log',
                    'rival_test_log',
                );
            } else {
                array_push(
                    $optional,
                    'atlas_patch',
                    'rival_patch',
                    'atlas_test_log',
                    'rival_test_log',
                );
            }
        } else {
            // Invalid / inconclusive / unknown verdict: every per-arm artifact
            // is optional. Missing them feeds the verdict (invalid) but never
            // makes replay throw the circular `not_present_at_replay` blocker.
            array_push(
                $optional,
                'atlas_receipt',
                'rival_receipt',
                'workspace_hashes',
                'atlas_patch',
                'rival_patch',
                'atlas_test_log',
                'rival_test_log',
            );
        }

        if ($stage === self::STAGE_FINAL) {
            $required[] = 'scorecard';
        }

        $required = AiStringListNormalizer::uniqueStrings($required);
        $optional = AiStringListNormalizer::uniqueStrings($optional);

        $policy = [];
        foreach ($required as $key) {
            $policy[$key] = self::POLICY_REQUIRED;
        }
        foreach ($optional as $key) {
            $policy[$key] = self::POLICY_OPTIONAL;
        }

        return [
            'stage' => $stage,
            'required' => $required,
            'optional' => $optional,
            'policy' => $policy,
            'is_comparable_real_run' => $isComparableRealRun,
            'verdict' => $verdict,
            'mode' => $mode,
        ];
    }

    /**
     * All artifact keys collect-evidence enumerates, in canonical order.
     * Excludes `scorecard` for pre_adjudication stage (it would be referenced
     * before it exists).
     *
     * @return list<string>
     */
    public static function keysForStage(string $stage): array
    {
        $base = [
            'manifest',
            'events_jsonl',
            'intent_json',
            'atlas_receipt',
            'rival_receipt',
            'workspace_hashes',
            'atlas_patch',
            'rival_patch',
            'atlas_test_log',
            'rival_test_log',
        ];

        if (self::normalizeStage($stage) === self::STAGE_FINAL) {
            $base[] = 'scorecard';
        }

        return $base;
    }
}
