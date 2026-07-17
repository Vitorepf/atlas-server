<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * ESP-09 — Independent challenger advisory (anti-blind-alignment).
 *
 * Pure advisory: high operator alignment OR decision_kind ∈
 * {recursive_improvement, composed_obra} emits a challenger block authored
 * under a distinct engine-id (ELEV-18). Deterministic gates remain the only
 * decision makers. Missing challenger on a triggered decision DELAYS
 * promotion — never vetoes.
 */
final class Esp09IndependentChallengerService
{
    public const SCHEMA_VERSION = 'atlas.esp_09.challenger_advisory.v1';

    public const MEASURE_ID = 'atlas.esp_09.challenger_advisory.v1';

    public const MODE = self::STATUS_ADVISORY;

    public const HIGH_ALIGNMENT_BAND = 0.80;

    public const TRIGGER_KIND_RECURSIVE_IMPROVEMENT = 'recursive_improvement';

    public const TRIGGER_KIND_COMPOSED_OBRA = 'composed_obra';

    /** @var list<string> */
    public const TRIGGER_KINDS = [
        self::TRIGGER_KIND_RECURSIVE_IMPROVEMENT,
        self::TRIGGER_KIND_COMPOSED_OBRA,
    ];

    public const DEFAULT_MIN_WINDOWS = 2;

    public const DEFAULT_MIN_PER_WINDOW = 2;

    public const DECISION_KIND_ORDINARY_ROUTE = 'ordinary_route';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_ADVISORY = 'advisory';

    public const STATUS_DELAYED = 'delayed';

    public const STATUS_CLEAR = 'clear';

    public const ERROR_ENGINE_IDS_REQUIRED = 'engine_ids_required';

    public const ERROR_CHALLENGER_ENGINE_MUST_DIFFER = 'challenger_engine_must_differ';

    public const FIELD_ERROR = 'error';

    public const SKIP_REASON_LOW_AFFINITY = 'low_affinity';

    public const TRIGGER_DECISION_KIND = 'decision_kind';

    public const TRIGGER_HIGH_OPERATOR_ALIGNMENT = 'high_operator_alignment';

    public const REASON_AWAITING_CHALLENGER_BLOCK = 'awaiting_challenger_block';

    public const REASON_CHALLENGER_BLOCK_PRESENT = 'challenger_block_present';

    public const REASON_CHALLENGER_NOT_REQUIRED = 'challenger_not_required';

    public const OUTCOME_ACCEPTED = 'accepted';

    public const OUTCOME_IGNORED = 'ignored';

    public const DEATH_REVIEW_REASON_NEAR_ZERO_ACCEPTED = 'near_zero_accepted_rate_across_windows';

    public const PROMOTION_WITHOUT_BLOCK = 'delayed_not_vetoed';

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function evaluate(array $context): array
    {
        $author = AiValueNormalizer::trimmedStringOrNull($context['author_engine_id'] ?? null) ?? '';
        $challengerEngine = AiValueNormalizer::trimmedStringOrNull($context['challenger_engine_id'] ?? null) ?? '';
        $alignmentRaw = AiValueNormalizer::finiteFloatOrNull($context['operator_alignment'] ?? null);
        $alignment = $alignmentRaw === null ? null : AiValueNormalizer::clampUnit($alignmentRaw);
        $kind = AiValueNormalizer::trimmedStringOrNull($context['decision_kind'] ?? null) ?? self::DECISION_KIND_ORDINARY_ROUTE;

        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'mode' => self::MODE,
            'advisory_only' => true,
            'gates_override' => false,
            'triggered' => false,
            'promotion_delayed' => false,
            'challenger' => null,
        ];

        if ($author === '' || $challengerEngine === '') {
            return array_merge($base, [
                'status' => self::STATUS_INVALID,
                self::FIELD_ERROR => self::ERROR_ENGINE_IDS_REQUIRED,
            ]);
        }

        if ($author === $challengerEngine) {
            return array_merge($base, [
                'status' => self::STATUS_INVALID,
                self::FIELD_ERROR => self::ERROR_CHALLENGER_ENGINE_MUST_DIFFER,
            ]);
        }

        $kindTriggered = in_array($kind, self::TRIGGER_KINDS, true);
        $highAlignment = $alignment !== null && $alignment >= self::HIGH_ALIGNMENT_BAND;

        if (! $kindTriggered && ! $highAlignment) {
            return array_merge($base, [
                'status' => self::STATUS_SKIPPED,
                'skip_reason' => self::SKIP_REASON_LOW_AFFINITY,
                'operator_alignment' => $alignment,
                'decision_kind' => $kind,
            ]);
        }

        $trigger = $kindTriggered ? self::TRIGGER_DECISION_KIND : self::TRIGGER_HIGH_OPERATOR_ALIGNMENT;

        return array_merge($base, [
            'status' => self::STATUS_ADVISORY,
            'triggered' => true,
            'trigger' => $trigger,
            'operator_alignment' => $alignment,
            'decision_kind' => $kind,
            'challenger' => [
                'author_engine_id' => $author,
                'challenger_engine_id' => $challengerEngine,
                'decision_kind' => $kind,
                'proposed_choice' => (AiValueNormalizer::trimmedStringOrNull($context['proposed_choice'] ?? null) ?? ''),
                'alternative' => (AiValueNormalizer::trimmedStringOrNull($context['alternative'] ?? null) ?? ''),
                'refutation' => (AiValueNormalizer::trimmedStringOrNull($context['refutation'] ?? null) ?? ''),
                'elev18_engine_ids_distinct' => true,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function promotionGate(array $context): array
    {
        $requires = ($context['requires_challenger'] ?? false) === true;
        $present = ($context['challenger_block_present'] ?? false) === true;

        if ($requires && ! $present) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => self::STATUS_DELAYED,
                'vetoed' => false,
                'promotion_delayed' => true,
                'reason' => self::REASON_AWAITING_CHALLENGER_BLOCK,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => self::STATUS_CLEAR,
            'vetoed' => false,
            'promotion_delayed' => false,
            'reason' => $requires ? self::REASON_CHALLENGER_BLOCK_PRESENT : self::REASON_CHALLENGER_NOT_REQUIRED,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    public static function refutationSeries(array $events, int $minWindows = self::DEFAULT_MIN_WINDOWS, int $minPerWindow = self::DEFAULT_MIN_PER_WINDOW): array
    {
        $accepted = 0;
        $ignored = 0;
        $byWindow = [];

        foreach ($events as $event) {
            $outcome = AiValueNormalizer::lowerTrimmedString($event['outcome'] ?? '');
            $window = AiValueNormalizer::trimmedStringOrNull($event['window'] ?? null) ?? 'default';
            $byWindow[$window] ??= [self::OUTCOME_ACCEPTED => 0, self::OUTCOME_IGNORED => 0];

            if ($outcome === self::OUTCOME_ACCEPTED) {
                $accepted++;
                $byWindow[$window][self::OUTCOME_ACCEPTED]++;
            } elseif ($outcome === self::OUTCOME_IGNORED) {
                $ignored++;
                $byWindow[$window][self::OUTCOME_IGNORED]++;
            }
        }

        $denominator = $accepted + $ignored;
        $acceptedRate = $denominator > 0 ? round($accepted / $denominator, 4) : 0.0;

        $qualifyingWindows = 0;
        $zeroAcceptedWindows = 0;
        foreach ($byWindow as $stats) {
            $n = (int) (AiValueNormalizer::finiteFloatOrNull($stats[self::OUTCOME_ACCEPTED] ?? null) ?? 0) + (int) (AiValueNormalizer::finiteFloatOrNull($stats[self::OUTCOME_IGNORED] ?? null) ?? 0);
            if ($n < $minPerWindow) {
                continue;
            }
            $qualifyingWindows++;
            if ((int) (AiValueNormalizer::finiteFloatOrNull($stats[self::OUTCOME_ACCEPTED] ?? null) ?? 0) === 0) {
                $zeroAcceptedWindows++;
            }
        }

        $deathReview = $qualifyingWindows >= $minWindows
            && $zeroAcceptedWindows >= $minWindows
            && $acceptedRate <= 0.0;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'series' => self::MEASURE_ID,
            'denominator' => $denominator,
            self::OUTCOME_ACCEPTED => $accepted,
            self::OUTCOME_IGNORED => $ignored,
            'accepted_rate' => $acceptedRate,
            'windows' => $byWindow,
            'death_review_candidate' => $deathReview,
            'death_review_reason' => $deathReview
                ? self::DEATH_REVIEW_REASON_NEAR_ZERO_ACCEPTED
                : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function freezePayload(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'mode' => self::MODE,
            'high_alignment_band' => self::HIGH_ALIGNMENT_BAND,
            'trigger_kinds' => self::TRIGGER_KINDS,
            'ttl_days' => 30,
            'gates_override' => false,
            'promotion_without_block' => self::PROMOTION_WITHOUT_BLOCK,
        ];
    }

}
