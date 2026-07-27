<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence;

use App\Models\OperatorLearningSignal;
use App\Models\OperatorPatternDetection;
use App\Services\Ai\OperatorIntelligence\Support\OperatorComprehensionGateSupport;
use App\Services\Ai\OperatorIntelligence\Support\OperatorPatternDetectSupport;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The shared recurrence brain for both operator-intelligence bridges (auto-built skill +
 * proactive mission). It mines the operator's OWN expressed history for genuine
 * recurrence — never inventing a pattern:
 *
 *   • OPERATOR-ORIGINATED ONLY — mines only signals the operator actually expressed
 *     (manual/app/voice/chat), excluding any system/agent-emitted rows, so Atlas's own
 *     activity can't manufacture a "pattern" about the operator.
 *   • EVIDENCE-LOCKED — every Pattern carries its ≥3 concrete occurrence rows with real
 *     timestamps; a sub-threshold group is DROPPED before it can become a proposal.
 *   • WINDOW-BOUNDED — recent window only; a one-off old action can't resurface.
 *   • CAPPED + FLOORED — at most N patterns per run, above a confidence floor (ordered by
 *     confidence × occurrence), so it can never flood the build loop or the Sunday digest.
 *   • DEDUPED — a stable pattern_id (operator|kind|signature) means a still-active
 *     recurrence refreshes ONE detection row, never re-proposing.
 *   • PRIVACY RAISE-ONLY — a Pattern inherits the most restrictive privacy of its evidence.
 *
 * It writes ONLY the derived detections ledger; the canonical truth stays in
 * operator_learning_signals / operator_profile_items.
 */
final class OperatorPatternDetector
{
    public const SCHEMA = 'atlas.operator.pattern.v1';

    /** Source types that represent the OPERATOR's own expression (never agent/system noise). */
    private const OPERATOR_SOURCE_TYPES = ['manual', 'app', 'voice_realtime', 'chat_comprehension', 'chat_explicit_operator_signal'];

    private const MIN_OCCURRENCES = 3;
    private const DEFAULT_WINDOW_DAYS = 28;
    private const DEFAULT_MAX_PATTERNS = 10;
    private const DEFAULT_MIN_CONFIDENCE = 0.6;

    /**
     * Detect + persist recurrence patterns for an operator. Returns the NEW detections
     * (status=detected) eligible for proposal, capped + floored + ordered by strength.
     *
     * @param  array<string,mixed>  $opts
     * @return list<OperatorPatternDetection>
     */
    public function detect(string $operatorId, array $opts = []): array
    {
        if (! DatabaseTableAvailability::all(['operator_learning_signals', 'operator_pattern_detections'])) {
            return [];
        }

        $window = (int) ($opts['window_days'] ?? config('atlas_operator_intelligence.pattern_window_days', self::DEFAULT_WINDOW_DAYS));
        $minOcc = max(self::MIN_OCCURRENCES, (int) ($opts['min_occurrences'] ?? self::MIN_OCCURRENCES));
        $cap = max(1, (int) ($opts['max_patterns'] ?? config('atlas_operator_intelligence.pattern_max_per_run', self::DEFAULT_MAX_PATTERNS)));
        $minConf = (float) ($opts['min_confidence'] ?? config('atlas_operator_intelligence.pattern_min_confidence', self::DEFAULT_MIN_CONFIDENCE));

        $signals = OperatorLearningSignal::query()
            ->where('operator_id', $operatorId)
            ->whereIn('source_type', self::OPERATOR_SOURCE_TYPES)
            ->where('created_at', '>=', now()->subDays($window))
            ->orderBy('created_at')
            ->get(['id', 'taxonomy_item_id', 'normalized_claim', 'signal_kind', 'confidence', 'privacy_class', 'created_at']);

        if ($signals->isEmpty()) {
            return [];
        }

        $patterns = array_merge(
            $this->repeatedAction($signals, $minOcc, $window),
            $this->temporalCadence($signals, $minOcc, $window),
        );

        // Floor + order by strength (confidence × occurrence) + cap — never flood.
        $patterns = array_values(array_filter($patterns, static fn (array $p): bool => $p['confidence'] >= $minConf));
        usort($patterns, static fn (array $a, array $b): int => ($b['confidence'] * $b['occurrence_count']) <=> ($a['confidence'] * $a['occurrence_count']));
        $patterns = array_slice($patterns, 0, $cap);

        $eligible = [];
        foreach ($patterns as $pattern) {
            $existing = OperatorPatternDetection::query()
                ->where('operator_id', $operatorId)
                ->where('pattern_id', $pattern['pattern_id'])
                ->first();

            if ($existing !== null) {
                // Refresh metrics but PRESERVE status — an already-proposed pattern is never re-proposed.
                $existing->forceFill([
                    'occurrence_count' => $pattern['occurrence_count'],
                    'confidence' => $pattern['confidence'],
                    'evidence' => $pattern['evidence'],
                ])->save();

                // A detection persisted but NEVER proposed is still eligible. Returning only
                // rows created in THIS call made the row itself the dead end: a --dry-run, a
                // crash between detect and propose, or any run predating the bridges wrote
                // status=detected, and every later run then found it existing and skipped it
                // forever. Status is the authority on "already proposed", not row age.
                if ($existing->status === OperatorPatternDetection::STATUS_DETECTED) {
                    $eligible[] = $existing;
                }

                continue;
            }

            $eligible[] = OperatorPatternDetection::query()->create(array_merge($pattern, [
                'operator_id' => $operatorId,
                'status' => OperatorPatternDetection::STATUS_DETECTED,
            ]));
        }

        return $eligible;
    }

    /**
     * "The operator keeps expressing X" — ≥3 signals on the same taxonomy item, grouped by
     * a sorted normalized-token shingle. This collapses EXACT normalized-token-set matches
     * (not loose paraphrases — a different filler token splits the group); that is the
     * safe direction (it under-groups, never inventing a larger false group), at the cost
     * of missing some paraphrased recurrences.
     *
     * @param  Collection<int,OperatorLearningSignal>  $signals
     * @return list<array<string,mixed>>
     */
    private function repeatedAction(Collection $signals, int $minOcc, int $window): array
    {
        $groups = $signals->groupBy(fn (OperatorLearningSignal $s): string => (string) $s->taxonomy_item_id.'|'.$this->shingle((string) $s->normalized_claim));

        $out = [];
        foreach ($groups as $signature => $group) {
            $count = $group->count();
            if ($count < $minOcc) {
                continue;
            }
            /** @var OperatorLearningSignal $latest */
            $latest = $group->last();
            $taxonomy = (string) $latest->taxonomy_item_id;
            $privacy = $this->raisePrivacy($group);
            $confidence = $this->confidence($group, 0.0);

            $out[] = [
                'pattern_id' => $this->patternId('repeated_action', (string) $signature),
                'kind' => 'repeated_action',
                'taxonomy_item_id' => $taxonomy,
                'signature' => Str::limit((string) $signature, 250, ''),
                'summary' => $privacy === 'normal'
                    ? Str::limit('O operador repete: '.(string) $latest->normalized_claim, 240)
                    : '[recorrência '.$privacy.' — redigida] '.$taxonomy,
                'occurrence_count' => $count,
                'window_days' => $window,
                'confidence' => $confidence,
                'privacy_class' => $privacy,
                'proposal_target' => 'both',
                'cadence' => null,
                'evidence' => $this->evidence($group),
            ];
        }

        return $out;
    }

    /**
     * "The operator does X on a cadence" — same day-of-week hit on ≥3 distinct calendar
     * days, with a regularity (low coefficient of variation on inter-occurrence gaps).
     *
     * @param  Collection<int,OperatorLearningSignal>  $signals
     * @return list<array<string,mixed>>
     */
    private function temporalCadence(Collection $signals, int $minOcc, int $window): array
    {
        // COHERENCE: group by (day-of-week + taxonomy item), so a cadence means the operator
        // does the SAME thing on that weekday — NOT merely "did anything on 3 Mondays" (which
        // would manufacture a false pattern from 3 unrelated expressions sharing a weekday).
        $byDowTax = $signals->groupBy(fn (OperatorLearningSignal $s): string => $this->safeParse($s->created_at)->dayOfWeekIso.'|'.(string) $s->taxonomy_item_id);

        $out = [];
        foreach ($byDowTax as $key => $group) {
            $distinctDays = $group->map(fn (OperatorLearningSignal $s): string => $this->safeParse($s->created_at)->toDateString())->unique();
            if ($distinctDays->count() < $minOcc) {
                continue;
            }
            [$dow, $taxonomy] = array_pad(explode('|', (string) $key, 2), 2, '');
            $regularity = $this->regularity($distinctDays->values()->all());
            $privacy = $this->raisePrivacy($group);
            $confidence = $this->confidence($group, $regularity * 0.15);
            $signature = 'dow:'.$dow.':'.$taxonomy;

            $out[] = [
                'pattern_id' => $this->patternId('temporal_cadence', $signature),
                'kind' => 'temporal_cadence',
                'taxonomy_item_id' => $taxonomy,
                'signature' => $signature,
                'summary' => $privacy === 'normal'
                    ? 'O operador costuma tratar '.$taxonomy.' às '.$this->dowName((int) $dow).'s ('.$distinctDays->count().'x/'.$window.'d)'
                    : '[cadência '.$privacy.' — redigida] '.$taxonomy,
                'occurrence_count' => $distinctDays->count(),
                'window_days' => $window,
                'confidence' => $confidence,
                'privacy_class' => $privacy,
                'proposal_target' => 'mission',
                'cadence' => ['dow' => (int) $dow, 'taxonomy_item_id' => $taxonomy, 'distinct_days' => $distinctDays->count(), 'regularity' => round($regularity, 3)],
                'evidence' => $this->evidence($group),
            ];
        }

        return $out;
    }

    private function shingle(string $claim): string
    {
        return OperatorPatternDetectSupport::shingle($claim);
    }

    private function patternId(string $kind, string $signature): string
    {
        return OperatorPatternDetectSupport::patternId($kind, $signature);
    }

    /**
     * @param  Collection<int,OperatorLearningSignal>  $group
     */
    private function raisePrivacy(Collection $group): string
    {
        return OperatorComprehensionGateSupport::raisePrivacyList(
            $group->map(fn (OperatorLearningSignal $s): string => (string) $s->privacy_class)->all(),
        );
    }

    /**
     * Deterministic confidence: mean evidence confidence + a saturating occurrence bonus
     * (+ optional regularity bonus). Numeric-safe (no NaN, clamped).
     *
     * @param  Collection<int,OperatorLearningSignal>  $group
     */
    private function confidence(Collection $group, float $extraBonus): float
    {
        $confs = $group->map(fn (OperatorLearningSignal $s): float => (float) $s->confidence)->all();

        return OperatorPatternDetectSupport::confidenceFromValues($confs, $group->count(), $extraBonus);
    }

    /** Regularity in [0,1] from the coefficient of variation of inter-occurrence day gaps. */
    private function regularity(array $dates): float
    {
        return OperatorPatternDetectSupport::regularity($dates);
    }

    /**
     * @param  Collection<int,OperatorLearningSignal>  $group
     * @return list<array<string,mixed>>
     */
    private function evidence(Collection $group): array
    {
        return $group->take(12)->map(fn (OperatorLearningSignal $s): array => [
            'source' => 'operator_learning_signal',
            'id' => (string) $s->id,
            'occurred_at' => $this->safeParse($s->created_at)->toIso8601String(),
            'taxonomy_item_id' => (string) $s->taxonomy_item_id,
        ])->values()->all();
    }

    /**
     * Safely parse a date string, returning a Carbon instance that defaults to
     * '1970-01-01' when the value is not a valid date.
     */
    private function safeParse(mixed $value): Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return Carbon::parse('1970-01-01');
        }
    }

    private function dowName(int $dowIso): string
    {
        return OperatorPatternDetectSupport::dowName($dowIso);
    }
}
