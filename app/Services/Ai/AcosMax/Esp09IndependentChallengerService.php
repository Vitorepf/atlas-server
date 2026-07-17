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

    public const MODE = 'advisory';

    public const HIGH_ALIGNMENT_BAND = 0.80;

    /** @var list<string> */
    public const TRIGGER_KINDS = ['recursive_improvement', 'composed_obra'];

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
        $kind = AiValueNormalizer::trimmedStringOrNull($context['decision_kind'] ?? null) ?? 'ordinary_route';

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
                'status' => 'invalid',
                'error' => 'engine_ids_required',
            ]);
        }

        if ($author === $challengerEngine) {
            return array_merge($base, [
                'status' => 'invalid',
                'error' => 'challenger_engine_must_differ',
            ]);
        }

        $kindTriggered = in_array($kind, self::TRIGGER_KINDS, true);
        $highAlignment = $alignment !== null && $alignment >= self::HIGH_ALIGNMENT_BAND;

        if (! $kindTriggered && ! $highAlignment) {
            return array_merge($base, [
                'status' => 'skipped',
                'skip_reason' => 'low_affinity',
                'operator_alignment' => $alignment,
                'decision_kind' => $kind,
            ]);
        }

        $trigger = $kindTriggered ? 'decision_kind' : 'high_operator_alignment';

        return array_merge($base, [
            'status' => 'advisory',
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
                'status' => 'delayed',
                'vetoed' => false,
                'promotion_delayed' => true,
                'reason' => 'awaiting_challenger_block',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'clear',
            'vetoed' => false,
            'promotion_delayed' => false,
            'reason' => $requires ? 'challenger_block_present' : 'challenger_not_required',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    public static function refutationSeries(array $events, int $minWindows = 2, int $minPerWindow = 2): array
    {
        $accepted = 0;
        $ignored = 0;
        $byWindow = [];

        foreach ($events as $event) {
            $outcome = AiValueNormalizer::lowerTrimmedString($event['outcome'] ?? '');
            $window = AiValueNormalizer::trimmedStringOrNull($event['window'] ?? null) ?? 'default';
            $byWindow[$window] ??= ['accepted' => 0, 'ignored' => 0];

            if ($outcome === 'accepted') {
                $accepted++;
                $byWindow[$window]['accepted']++;
            } elseif ($outcome === 'ignored') {
                $ignored++;
                $byWindow[$window]['ignored']++;
            }
        }

        $denominator = $accepted + $ignored;
        $acceptedRate = $denominator > 0 ? round($accepted / $denominator, 4) : 0.0;

        $qualifyingWindows = 0;
        $zeroAcceptedWindows = 0;
        foreach ($byWindow as $stats) {
            $n = (int) (AiValueNormalizer::finiteFloatOrNull($stats['accepted'] ?? null) ?? 0) + (int) (AiValueNormalizer::finiteFloatOrNull($stats['ignored'] ?? null) ?? 0);
            if ($n < $minPerWindow) {
                continue;
            }
            $qualifyingWindows++;
            if ((int) (AiValueNormalizer::finiteFloatOrNull($stats['accepted'] ?? null) ?? 0) === 0) {
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
            'accepted' => $accepted,
            'ignored' => $ignored,
            'accepted_rate' => $acceptedRate,
            'windows' => $byWindow,
            'death_review_candidate' => $deathReview,
            'death_review_reason' => $deathReview
                ? 'near_zero_accepted_rate_across_windows'
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
            'promotion_without_block' => 'delayed_not_vetoed',
        ];
    }

}
