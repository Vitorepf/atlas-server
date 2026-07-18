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
    public const FIELD_STATUS = 'status';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_PROMOTION_DELAYED = 'promotion_delayed';
    public const FIELD_DECISION_KIND = 'decision_kind';
    public const FIELD_MEASURE_ID = 'measure_id';
    public const FIELD_MODE = 'mode';
    public const FIELD_GATES_OVERRIDE = 'gates_override';
    public const FIELD_TRIGGERED = 'triggered';
    public const FIELD_CHALLENGER = 'challenger';
    public const FIELD_OPERATOR_ALIGNMENT = 'operator_alignment';
    public const FIELD_VETOED = 'vetoed';
    public const FIELD_REASON = 'reason';
    public const FIELD_ADVISORY_ONLY = 'advisory_only';
    public const FIELD_SKIP_REASON = 'skip_reason';
    public const FIELD_AUTHOR_ENGINE_ID = 'author_engine_id';
    public const FIELD_CHALLENGER_ENGINE_ID = 'challenger_engine_id';
    public const FIELD_ACCEPTED_RATE = 'accepted_rate';
    public const FIELD_ALTERNATIVE = 'alternative';
    public const FIELD_CHALLENGER_BLOCK_PRESENT = 'challenger_block_present';
    public const FIELD_DEATH_REVIEW_CANDIDATE = 'death_review_candidate';
    public const FIELD_DEATH_REVIEW_REASON = 'death_review_reason';
    public const FIELD_DENOMINATOR = 'denominator';
    public const FIELD_PROPOSED_CHOICE = 'proposed_choice';
    public const FIELD_REFUTATION = 'refutation';
    public const FIELD_ELEV18_ENGINE_IDS_DISTINCT = 'elev18_engine_ids_distinct';
    public const FIELD_HIGH_ALIGNMENT_BAND = 'high_alignment_band';
    public const FIELD_OUTCOME = 'outcome';
    public const FIELD_PROMOTION_WITHOUT_BLOCK = 'promotion_without_block';

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
    public const FIELD_TRIGGER = 'trigger';
    public const FIELD_TRIGGER_KINDS = 'trigger_kinds';
    public const FIELD_WINDOWS = 'windows';
    public const FIELD_WINDOW = 'window';
    public const FIELD_REQUIRES_CHALLENGER = 'requires_challenger';
    public const FIELD_SERIES = 'series';

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function evaluate(array $context): array
    {
        $author = AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_AUTHOR_ENGINE_ID] ?? null) ?? '';
        $challengerEngine = AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_CHALLENGER_ENGINE_ID] ?? null) ?? '';
        $alignmentRaw = AiValueNormalizer::finiteFloatOrNull($context[self::FIELD_OPERATOR_ALIGNMENT] ?? null);
        $alignment = $alignmentRaw === null ? null : AiValueNormalizer::clampUnit($alignmentRaw);
        $kind = AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_DECISION_KIND] ?? null) ?? self::DECISION_KIND_ORDINARY_ROUTE;

        $base = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_MODE => self::MODE,
            self::FIELD_ADVISORY_ONLY => true,
            self::FIELD_GATES_OVERRIDE => false,
            self::FIELD_TRIGGERED => false,
            self::FIELD_PROMOTION_DELAYED => false,
            self::FIELD_CHALLENGER => null,
        ];

        if ($author === '' || $challengerEngine === '') {
            return array_merge($base, [
                self::FIELD_STATUS => self::STATUS_INVALID,
                self::FIELD_ERROR => self::ERROR_ENGINE_IDS_REQUIRED,
            ]);
        }

        if ($author === $challengerEngine) {
            return array_merge($base, [
                self::FIELD_STATUS => self::STATUS_INVALID,
                self::FIELD_ERROR => self::ERROR_CHALLENGER_ENGINE_MUST_DIFFER,
            ]);
        }

        $kindTriggered = in_array($kind, self::TRIGGER_KINDS, true);
        $highAlignment = $alignment !== null && $alignment >= self::HIGH_ALIGNMENT_BAND;

        if (! $kindTriggered && ! $highAlignment) {
            return array_merge($base, [
                self::FIELD_STATUS => self::STATUS_SKIPPED,
                self::FIELD_SKIP_REASON => self::SKIP_REASON_LOW_AFFINITY,
                self::FIELD_OPERATOR_ALIGNMENT => $alignment,
                self::FIELD_DECISION_KIND => $kind,
            ]);
        }

        $trigger = $kindTriggered ? self::TRIGGER_DECISION_KIND : self::TRIGGER_HIGH_OPERATOR_ALIGNMENT;

        return array_merge($base, [
            self::FIELD_STATUS => self::STATUS_ADVISORY,
            self::FIELD_TRIGGERED => true,
            self::FIELD_TRIGGER => $trigger,
            self::FIELD_OPERATOR_ALIGNMENT => $alignment,
            self::FIELD_DECISION_KIND => $kind,
            self::FIELD_CHALLENGER => [
                self::FIELD_AUTHOR_ENGINE_ID => $author,
                self::FIELD_CHALLENGER_ENGINE_ID => $challengerEngine,
                self::FIELD_DECISION_KIND => $kind,
                self::FIELD_PROPOSED_CHOICE => (AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_PROPOSED_CHOICE] ?? null) ?? ''),
                self::FIELD_ALTERNATIVE => (AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_ALTERNATIVE] ?? null) ?? ''),
                self::FIELD_REFUTATION => (AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_REFUTATION] ?? null) ?? ''),
                self::FIELD_ELEV18_ENGINE_IDS_DISTINCT => true,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function promotionGate(array $context): array
    {
        $requires = ($context[self::FIELD_REQUIRES_CHALLENGER] ?? false) === true;
        $present = ($context[self::FIELD_CHALLENGER_BLOCK_PRESENT] ?? false) === true;

        if ($requires && ! $present) {
            return [
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_STATUS => self::STATUS_DELAYED,
                self::FIELD_VETOED => false,
                self::FIELD_PROMOTION_DELAYED => true,
                self::FIELD_REASON => self::REASON_AWAITING_CHALLENGER_BLOCK,
            ];
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_STATUS => self::STATUS_CLEAR,
            self::FIELD_VETOED => false,
            self::FIELD_PROMOTION_DELAYED => false,
            self::FIELD_REASON => $requires ? self::REASON_CHALLENGER_BLOCK_PRESENT : self::REASON_CHALLENGER_NOT_REQUIRED,
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
            $outcome = AiValueNormalizer::lowerTrimmedString($event[self::FIELD_OUTCOME] ?? '');
            $window = AiValueNormalizer::trimmedStringOrNull($event[self::FIELD_WINDOW] ?? null) ?? 'default';
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
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_SERIES => self::MEASURE_ID,
            self::FIELD_DENOMINATOR => $denominator,
            self::OUTCOME_ACCEPTED => $accepted,
            self::OUTCOME_IGNORED => $ignored,
            self::FIELD_ACCEPTED_RATE => $acceptedRate,
            self::FIELD_WINDOWS => $byWindow,
            self::FIELD_DEATH_REVIEW_CANDIDATE => $deathReview,
            self::FIELD_DEATH_REVIEW_REASON => $deathReview
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
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_MODE => self::MODE,
            self::FIELD_HIGH_ALIGNMENT_BAND => self::HIGH_ALIGNMENT_BAND,
            self::FIELD_TRIGGER_KINDS => self::TRIGGER_KINDS,
            'ttl_days' => 30,
            self::FIELD_GATES_OVERRIDE => false,
            self::FIELD_PROMOTION_WITHOUT_BLOCK => self::PROMOTION_WITHOUT_BLOCK,
        ];
    }

}
