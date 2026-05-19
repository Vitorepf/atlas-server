<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Rivals;

use App\Models\AtlasVoxRivalsCase;
use App\Services\Ai\Vox\VoxEvidenceService;
use App\Services\Ai\Vox\VoxSchema;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Records and reports Atlas Vox rivals cases.
 *
 * Rivals cases are human-evaluated head-to-head comparisons between Vox
 * and a baseline (Wispr dictation, manual prompting, or "did it by hand").
 * Vitor records them ad-hoc; the runner persists each case durably to
 * `atlas_vox_rivals_cases`, emits a `VOX_RIVALS_CASE_RECORDED` ledger
 * event for audit, and produces an aggregated report consumed by both the
 * metrics endpoint and the V3 promotion gate.
 *
 * Hard rules:
 *   - The runner does NOT execute, transcribe, or call any provider.
 *   - It accepts and persists only the human verdict + timing metadata.
 *   - No audio bytes, no transcript text, no prompt body are stored.
 */
final class VoxRivalsRunner
{
    public const KIND_WISPR_BASELINE = 'wispr_baseline';
    public const KIND_PROVIDER_DIRECT = 'provider_direct';
    public const KIND_MANUAL = 'manual';

    public const PREFERENCE_VOX = 'vox';
    public const PREFERENCE_BASELINE = 'baseline';
    public const PREFERENCE_TIE = 'tie';

    /** Persistent backing table. Doctor/setup paths check this before reads. */
    public const TABLE = 'atlas_vox_rivals_cases';

    /**
     * Setup-pending next action used both by `report()` (when the table is
     * missing) and by the doctor (when it surfaces the warn-state to the
     * operator). Single source of truth so the message can't drift.
     */
    public const SETUP_PENDING_NEXT_ACTION =
        'Rode `php artisan migrate` para criar a tabela atlas_vox_rivals_cases antes de registrar rivals.';

    public function __construct(
        private readonly VoxEvidenceService $evidence,
    ) {}

    /**
     * @return list<string>
     */
    public static function allowedKinds(): array
    {
        return [self::KIND_WISPR_BASELINE, self::KIND_PROVIDER_DIRECT, self::KIND_MANUAL];
    }

    /**
     * @return list<string>
     */
    public static function allowedModes(): array
    {
        return [
            VoxSchema::MODE_DICTATION,
            VoxSchema::MODE_PROMPT_POLISH,
            VoxSchema::MODE_INTENT_COMPILE,
            VoxSchema::MODE_GOVERNED_EXECUTE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedPreferences(): array
    {
        return [self::PREFERENCE_VOX, self::PREFERENCE_BASELINE, self::PREFERENCE_TIE];
    }

    /**
     * Returns true when the persistent backing table is present and
     * `record()` / `report()` can read+write it. Used by the doctor and
     * by readiness probes to decide between `ready` and `setup_pending`.
     */
    public function isStoragePresent(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (\Throwable) {
            // DB connection itself is down — treat as setup pending so the
            // doctor surfaces a clear next action instead of crashing.
            return false;
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{case: AtlasVoxRivalsCase, event: array<string,mixed>}
     */
    public function record(array $payload): array
    {
        $this->guardPayload($payload);

        if (! $this->isStoragePresent()) {
            throw new RuntimeException(
                'atlas_vox_rivals_cases not present · '.self::SETUP_PENDING_NEXT_ACTION,
            );
        }

        $case = AtlasVoxRivalsCase::create([
            'case_id' => 'voxc_'.(string) Str::uuid(),
            'kind' => $payload['kind'],
            'mode' => $payload['mode'],
            'vox_session_id' => $payload['vox_session_id'] ?? null,
            'vox_intent_id' => $payload['vox_intent_id'] ?? null,
            'baseline_label' => $payload['baseline_label'],
            'baseline_duration_ms' => $payload['baseline_duration_ms'] ?? null,
            'vox_duration_ms' => $payload['vox_duration_ms'] ?? null,
            'baseline_score' => $payload['baseline_score'] ?? null,
            'vox_score' => $payload['vox_score'] ?? null,
            'preference' => $payload['preference'],
            'prompt_quality_vote' => $payload['prompt_quality_vote'] ?? null,
            'regret_flag' => (bool) ($payload['regret_flag'] ?? false),
            'notes' => $payload['notes'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
        ]);

        $event = $this->evidence->rivalsCaseRecorded([
            'case_id' => $case->case_id,
            'kind' => $case->kind,
            'mode' => $case->mode,
            'vox_session_id' => $case->vox_session_id,
            'vox_intent_id' => $case->vox_intent_id,
            'baseline_label' => $case->baseline_label,
            'baseline_duration_ms' => $case->baseline_duration_ms,
            'vox_duration_ms' => $case->vox_duration_ms,
            'baseline_score' => $case->baseline_score,
            'vox_score' => $case->vox_score,
            'preference' => $case->preference,
            'prompt_quality_vote' => $case->prompt_quality_vote,
            'regret_flag' => $case->regret_flag,
        ]);

        return ['case' => $case, 'event' => $event];
    }

    /**
     * Aggregated report consumed by `/ai/vox/rivals/report` and the V3
     * promotion gate.
     *
     * Setup contract: when `atlas_vox_rivals_cases` is missing, returns a
     * deterministic zero-shape with `storage_status='setup_pending'` and a
     * `setup_next_action` field. Never throws. The doctor surfaces this as
     * `warn`, not `fail`.
     *
     * @return array<string,mixed>
     */
    public function report(): array
    {
        if (! $this->isStoragePresent()) {
            return $this->emptyReport(
                storageStatus: 'setup_pending',
                nextAction: self::SETUP_PENDING_NEXT_ACTION,
            );
        }

        $cases = AtlasVoxRivalsCase::query()->get();
        $total = $cases->count();

        $voxWins = $cases->where('preference', self::PREFERENCE_VOX)->count();
        $baselineWins = $cases->where('preference', self::PREFERENCE_BASELINE)->count();
        $ties = $cases->where('preference', self::PREFERENCE_TIE)->count();

        $byKind = [
            self::KIND_WISPR_BASELINE => 0,
            self::KIND_PROVIDER_DIRECT => 0,
            self::KIND_MANUAL => 0,
        ];
        foreach ($cases->groupBy('kind') as $kind => $bucket) {
            $byKind[(string) $kind] = $bucket->count();
        }

        $votes = $cases->whereNotNull('prompt_quality_vote');
        $promptQualityDelta = $votes->isEmpty()
            ? 0.0
            : round((float) $votes->avg('prompt_quality_vote'), 4);

        $regretRate = $total > 0
            ? round($cases->where('regret_flag', true)->count() / $total, 4)
            : 0.0;

        $timed = $cases->filter(fn ($c): bool => $c->baseline_duration_ms !== null
            && $c->vox_duration_ms !== null
            && (int) $c->vox_duration_ms > 0);
        $multiplier = $timed->isEmpty()
            ? 0.0
            : round(
                $timed->avg(fn ($c): float => ((int) $c->baseline_duration_ms) / ((int) $c->vox_duration_ms)),
                4
            );

        return [
            'cases_total' => $total,
            'cases_by_kind' => $byKind,
            'vox_wins' => $voxWins,
            'baseline_wins' => $baselineWins,
            'ties' => $ties,
            'prompt_quality_delta' => $promptQualityDelta,
            'action_regret_score' => $regretRate,
            'rivals_voice_multiplier' => $multiplier,
            'recommendation' => $this->recommendation($total, $voxWins, $multiplier, $regretRate),
            'storage_status' => 'present',
            'setup_next_action' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyReport(string $storageStatus, ?string $nextAction): array
    {
        return [
            'cases_total' => 0,
            'cases_by_kind' => [
                self::KIND_WISPR_BASELINE => 0,
                self::KIND_PROVIDER_DIRECT => 0,
                self::KIND_MANUAL => 0,
            ],
            'vox_wins' => 0,
            'baseline_wins' => 0,
            'ties' => 0,
            'prompt_quality_delta' => 0.0,
            'action_regret_score' => 0.0,
            'rivals_voice_multiplier' => 0.0,
            'recommendation' => 'no_cases_yet',
            'storage_status' => $storageStatus,
            'setup_next_action' => $nextAction,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function guardPayload(array $payload): void
    {
        $required = ['kind', 'mode', 'baseline_label', 'preference'];
        foreach ($required as $field) {
            if (! array_key_exists($field, $payload)
                || (is_string($payload[$field]) && trim($payload[$field]) === '')) {
                throw new InvalidArgumentException("missing required field: {$field}");
            }
        }
        if (! in_array($payload['kind'], self::allowedKinds(), true)) {
            throw new InvalidArgumentException("invalid kind: {$payload['kind']}");
        }
        if (! in_array($payload['mode'], self::allowedModes(), true)) {
            throw new InvalidArgumentException("invalid mode: {$payload['mode']}");
        }
        if (! in_array($payload['preference'], self::allowedPreferences(), true)) {
            throw new InvalidArgumentException("invalid preference: {$payload['preference']}");
        }
        if (isset($payload['prompt_quality_vote'])) {
            $vote = $payload['prompt_quality_vote'];
            if (! in_array($vote, [-1, 0, 1], true)) {
                throw new InvalidArgumentException('prompt_quality_vote must be -1, 0 or 1');
            }
        }
        foreach (['baseline_score', 'vox_score'] as $scoreField) {
            if (isset($payload[$scoreField])) {
                $score = $payload[$scoreField];
                if (! is_int($score) || $score < 1 || $score > 5) {
                    throw new InvalidArgumentException("{$scoreField} must be integer 1..5");
                }
            }
        }
        foreach (['baseline_duration_ms', 'vox_duration_ms'] as $durField) {
            if (isset($payload[$durField])) {
                $value = $payload[$durField];
                if (! is_int($value) || $value < 0) {
                    throw new InvalidArgumentException("{$durField} must be non-negative integer");
                }
            }
        }
    }

    private function recommendation(int $total, int $voxWins, float $multiplier, float $regret): string
    {
        if ($total === 0) {
            return 'no_cases_yet';
        }
        if ($regret >= 0.10) {
            return 'investigate_regret';
        }
        if ($multiplier >= 1.2 && $voxWins >= max(3, intdiv($total, 2))) {
            return 'vox_winning';
        }
        if ($voxWins === 0) {
            return 'vox_underperforming';
        }

        return 'inconclusive_more_cases_needed';
    }
}
