<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Dogfood;

use App\Models\AtlasVoxDogfoodSession;
use App\Services\Ai\Vox\VoxEvidenceService;
use App\Services\Ai\Vox\VoxSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Records and reports Atlas Vox dogfood sessions.
 *
 * Dogfood is distinct from rivals:
 *   - Rivals = head-to-head comparison (Vox vs Wispr/manual/provider direct).
 *   - Dogfood = Vitor's diary of REAL Vox usage. "Today I used Vox in a real
 *     session and here's how it went."
 *
 * The service is the only writer/reader for `atlas_vox_dogfood_sessions`.
 * It:
 *   - Persists one row per session Vitor recorded.
 *   - Emits a `VOX_DOGFOOD_SESSION_RECORDED` ledger event for audit.
 *   - Produces an aggregated report (`atlas.vox.dogfood_report.v1`) with
 *     totals / rates / last-7-days slice / recommendation /
 *     `gate_v3_contribution` informational block.
 *
 * Hard rules:
 *   - No raw audio, no transcript text, no prompt body is ever stored.
 *   - `notes` are short (≤1000 chars, enforced at controller).
 *   - `metadata` is small JSON (≤64 KiB, enforced at controller).
 *   - The service NEVER mutates VoxMetricsService / VoxV3PromotionGateService
 *     state — the report's `gate_v3_contribution` block is informational
 *     only. A future wave can opt-in via VoxMetricsService extension.
 */
class VoxDogfoodService
{
    public const SCHEMA_SESSION = 'atlas.vox.dogfood_session.v1';
    public const SCHEMA_REPORT = 'atlas.vox.dogfood_report.v1';

    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_PARTIAL = 'partial';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_CANCELLED = 'cancelled';

    /** Max bytes the controller may forward in `metadata`. */
    public const METADATA_MAX_BYTES = 65_536;

    /** Max chars the controller may forward in `notes`. */
    public const NOTES_MAX_CHARS = 1000;

    public function __construct(
        private readonly VoxEvidenceService $evidence,
    ) {}

    /**
     * @return list<string>
     */
    public static function allowedOutcomes(): array
    {
        return [
            self::OUTCOME_SUCCESS,
            self::OUTCOME_PARTIAL,
            self::OUTCOME_FAILED,
            self::OUTCOME_CANCELLED,
        ];
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
     * Persist one dogfood session row + emit ledger event.
     *
     * @param  array<string,mixed>  $payload
     * @return array{
     *     session: AtlasVoxDogfoodSession,
     *     event: array<string,mixed>,
     * }
     */
    public function record(array $payload): array
    {
        $this->guardPayload($payload);

        $startedAt = $this->normalizeStartedAt($payload['started_at'] ?? null);

        $session = AtlasVoxDogfoodSession::create([
            'dogfood_session_id' => 'voxd_'.(string) Str::uuid(),
            'vox_session_id' => $payload['vox_session_id'] ?? null,
            'mode' => $payload['mode'],
            'outcome' => $payload['outcome'],
            'used_hotkey' => (bool) ($payload['used_hotkey'] ?? false),
            'used_real_stt' => (bool) ($payload['used_real_stt'] ?? false),
            'used_governed_execute' => (bool) ($payload['used_governed_execute'] ?? false),
            'regret_flag' => (bool) ($payload['regret_flag'] ?? false),
            'eclipse_used' => (bool) ($payload['eclipse_used'] ?? false),
            'started_at' => $startedAt,
            'duration_ms' => $payload['duration_ms'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
        ]);

        $event = $this->evidence->dogfoodSessionRecorded([
            'dogfood_session_id' => $session->dogfood_session_id,
            'vox_session_id' => $session->vox_session_id,
            'mode' => $session->mode,
            'outcome' => $session->outcome,
            'used_hotkey' => $session->used_hotkey,
            'used_real_stt' => $session->used_real_stt,
            'used_governed_execute' => $session->used_governed_execute,
            'regret_flag' => $session->regret_flag,
            'eclipse_used' => $session->eclipse_used,
            'duration_ms' => $session->duration_ms,
        ]);

        return ['session' => $session, 'event' => $event];
    }

    /**
     * Aggregated dogfood report consumed by `/ai/vox/dogfood/report`.
     *
     * Honest empty shape when the table doesn't exist yet (e.g. fresh
     * sandbox without migrations) — we never invent counters.
     *
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $now = CarbonImmutable::now('UTC');
        if (! Schema::hasTable('atlas_vox_dogfood_sessions')) {
            return $this->emptyReport($now);
        }

        $sessions = AtlasVoxDogfoodSession::query()->get();
        $total = $sessions->count();
        $sevenDaysAgo = $now->subDays(7);
        $last7 = $sessions->filter(static function (AtlasVoxDogfoodSession $s) use ($sevenDaysAgo): bool {
            $created = $s->created_at;
            return $created !== null && $created->greaterThanOrEqualTo($sevenDaysAgo);
        });

        $successCount = $sessions->where('outcome', self::OUTCOME_SUCCESS)->count();
        $partialCount = $sessions->where('outcome', self::OUTCOME_PARTIAL)->count();
        $failedCount = $sessions->where('outcome', self::OUTCOME_FAILED)->count();
        $cancelledCount = $sessions->where('outcome', self::OUTCOME_CANCELLED)->count();

        $regretCount = $sessions->where('regret_flag', true)->count();
        $hotkeyCount = $sessions->where('used_hotkey', true)->count();
        $realSttCount = $sessions->where('used_real_stt', true)->count();
        $governedExecuteCount = $sessions->where('used_governed_execute', true)->count();
        $eclipseUsedCount = $sessions->where('eclipse_used', true)->count();

        $byMode = [
            VoxSchema::MODE_DICTATION => 0,
            VoxSchema::MODE_PROMPT_POLISH => 0,
            VoxSchema::MODE_INTENT_COMPILE => 0,
            VoxSchema::MODE_GOVERNED_EXECUTE => 0,
        ];
        foreach ($sessions->groupBy('mode') as $mode => $bucket) {
            $byMode[(string) $mode] = $bucket->count();
        }

        $realUsageDays = $this->countDistinctDays($sessions);

        return [
            'schema' => self::SCHEMA_REPORT,
            'status' => 'ok',
            'sessions_total' => $total,
            'sessions_last_7_days' => $last7->count(),
            'success_rate' => $this->rate($successCount, $total),
            'partial_rate' => $this->rate($partialCount, $total),
            'failed_rate' => $this->rate($failedCount, $total),
            'cancelled_rate' => $this->rate($cancelledCount, $total),
            'regret_rate' => $this->rate($regretCount, $total),
            'hotkey_usage_rate' => $this->rate($hotkeyCount, $total),
            'real_stt_usage_rate' => $this->rate($realSttCount, $total),
            'governed_execute_usage_count' => $governedExecuteCount,
            'eclipse_used_count' => $eclipseUsedCount,
            'sessions_by_mode' => $byMode,
            'outcomes' => [
                self::OUTCOME_SUCCESS => $successCount,
                self::OUTCOME_PARTIAL => $partialCount,
                self::OUTCOME_FAILED => $failedCount,
                self::OUTCOME_CANCELLED => $cancelledCount,
            ],
            'real_usage_days' => $realUsageDays,
            'recommendation' => $this->recommendation(
                total: $total,
                successCount: $successCount,
                regretCount: $regretCount,
                last7: $last7->count(),
            ),
            // Informational only. We do NOT mutate VoxMetricsService or
            // VoxV3PromotionGateService — keeping the gate untouched protects
            // the existing test surface. A future wave can opt-in.
            'gate_v3_contribution' => [
                'integration_mode' => 'informational_only',
                'description' => 'Dogfood reportado separadamente; gate-v3 atual deriva de VOX_INTENT_COMPILED + rivals cases.',
                'would_contribute' => [
                    'real_sessions' => $total,
                    'real_usage_days' => $realUsageDays,
                    'regret_rate' => $this->rate($regretCount, $total),
                    'eclipse_used_count' => $eclipseUsedCount,
                ],
            ],
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function guardPayload(array $payload): void
    {
        $required = ['mode', 'outcome'];
        foreach ($required as $field) {
            if (! array_key_exists($field, $payload)
                || (is_string($payload[$field]) && trim($payload[$field]) === '')) {
                throw new InvalidArgumentException("missing required field: {$field}");
            }
        }
        if (! in_array($payload['mode'], self::allowedModes(), true)) {
            throw new InvalidArgumentException("invalid mode: {$payload['mode']}");
        }
        if (! in_array($payload['outcome'], self::allowedOutcomes(), true)) {
            throw new InvalidArgumentException("invalid outcome: {$payload['outcome']}");
        }
        if (isset($payload['duration_ms'])) {
            $duration = $payload['duration_ms'];
            if (! is_int($duration) || $duration < 0) {
                throw new InvalidArgumentException('duration_ms must be non-negative integer');
            }
        }
        if (isset($payload['notes']) && is_string($payload['notes'])
            && mb_strlen($payload['notes']) > self::NOTES_MAX_CHARS) {
            throw new InvalidArgumentException(
                'notes must be at most '.self::NOTES_MAX_CHARS.' chars',
            );
        }
        if (isset($payload['metadata']) && is_array($payload['metadata'])) {
            $encoded = json_encode($payload['metadata']);
            if ($encoded === false || strlen($encoded) > self::METADATA_MAX_BYTES) {
                throw new InvalidArgumentException(
                    'metadata must encode to at most '.self::METADATA_MAX_BYTES.' bytes',
                );
            }
        }
    }

    private function normalizeStartedAt(mixed $raw): ?CarbonImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if ($raw instanceof CarbonImmutable) {
            return $raw->utc();
        }
        if (is_string($raw)) {
            try {
                return CarbonImmutable::parse($raw)->utc();
            } catch (\Throwable) {
                throw new InvalidArgumentException("invalid started_at: {$raw}");
            }
        }
        throw new InvalidArgumentException('started_at must be ISO 8601 string or omitted');
    }

    /**
     * @param  \Illuminate\Support\Collection<int,AtlasVoxDogfoodSession>  $sessions
     */
    private function countDistinctDays($sessions): int
    {
        $days = [];
        foreach ($sessions as $session) {
            $marker = $session->started_at ?? $session->created_at;
            if ($marker === null) {
                continue;
            }
            $days[$marker->copy()->utc()->toDateString()] = true;
        }
        return count($days);
    }

    private function rate(int $numerator, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }
        return round($numerator / $total, 4);
    }

    private function recommendation(int $total, int $successCount, int $regretCount, int $last7): string
    {
        if ($total === 0) {
            return 'no_dogfood_sessions_yet';
        }
        // Regret is the loudest signal — investigate before anything else.
        if ($total > 0 && ($regretCount / $total) >= 0.20) {
            return 'investigate_regret_pattern';
        }
        if ($last7 === 0) {
            return 'stalled_no_recent_usage';
        }
        if ($total >= 10 && ($successCount / $total) >= 0.70 && $last7 >= 3) {
            return 'dogfood_healthy';
        }
        return 'keep_dogfooding';
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyReport(CarbonImmutable $now): array
    {
        $byMode = [
            VoxSchema::MODE_DICTATION => 0,
            VoxSchema::MODE_PROMPT_POLISH => 0,
            VoxSchema::MODE_INTENT_COMPILE => 0,
            VoxSchema::MODE_GOVERNED_EXECUTE => 0,
        ];
        return [
            'schema' => self::SCHEMA_REPORT,
            'status' => 'ok',
            'sessions_total' => 0,
            'sessions_last_7_days' => 0,
            'success_rate' => 0.0,
            'partial_rate' => 0.0,
            'failed_rate' => 0.0,
            'cancelled_rate' => 0.0,
            'regret_rate' => 0.0,
            'hotkey_usage_rate' => 0.0,
            'real_stt_usage_rate' => 0.0,
            'governed_execute_usage_count' => 0,
            'eclipse_used_count' => 0,
            'sessions_by_mode' => $byMode,
            'outcomes' => [
                self::OUTCOME_SUCCESS => 0,
                self::OUTCOME_PARTIAL => 0,
                self::OUTCOME_FAILED => 0,
                self::OUTCOME_CANCELLED => 0,
            ],
            'real_usage_days' => 0,
            'recommendation' => 'no_dogfood_sessions_yet',
            'gate_v3_contribution' => [
                'integration_mode' => 'informational_only',
                'description' => 'Dogfood reportado separadamente; gate-v3 atual deriva de VOX_INTENT_COMPILED + rivals cases.',
                'would_contribute' => [
                    'real_sessions' => 0,
                    'real_usage_days' => 0,
                    'regret_rate' => 0.0,
                    'eclipse_used_count' => 0,
                ],
            ],
            'generated_at' => $now->toIso8601String(),
        ];
    }
}
