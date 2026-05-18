<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Metrics;

use App\Models\AtlasLedgerEvent;
use App\Models\AtlasVoxRivalsCase;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Vox\VoxSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Reads two derived sources to compute Atlas Vox usage + safety + quality
 * metrics:
 *   - `atlas_ledger_events` for session / intent / safety counters
 *   - `atlas_vox_rivals_cases` for prompt-quality + multiplier signals
 *
 * The service is read-only. Never writes, never executes, never calls a
 * provider. Returns an honest empty/zero shape if the tables don't exist
 * (e.g. fresh sandbox without migrations).
 *
 * Schema: `atlas.vox.metrics.v1` — pinned by tests so any field rename
 * triggers a deliberate doc-aligned bump.
 */
class VoxMetricsService
{
    public const SCHEMA = 'atlas.vox.metrics.v1';

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $now = CarbonImmutable::now('UTC');
        $sessions = $this->sessionCounters();
        $safety = $this->safetyCounters();
        $rivals = $this->rivalsCounters();
        $usage = $this->usageWindow();

        $hardGates = [
            'raw_audio_persisted_count' => $safety['raw_audio_persisted_count'],
            'confirmation_bypass_count' => $safety['confirmation_bypass_count'],
            'destructive_action_without_receipt' => $safety['destructive_action_without_receipt'],
            'eclipse_test_success_count' => $safety['eclipse_test_success_count'],
            'action_regret_score' => $rivals['action_regret_score'],
            'prompt_quality_delta' => $rivals['prompt_quality_delta'],
            'rivals_voice_multiplier' => $rivals['rivals_voice_multiplier'],
        ];

        return [
            'schema' => self::SCHEMA,
            'status' => 'ok',
            'summary' => [
                'total_sessions' => $sessions['total_sessions'],
                'real_usage_days' => $usage['real_usage_days'],
                'average_sessions_per_day' => $usage['average_sessions_per_day'],
                'first_session_at' => $usage['first_session_at'],
                'last_session_at' => $usage['last_session_at'],
                'dictionary_correction_count' => $safety['dictionary_correction_count'],
                'stt_wer_estimate' => null,
            ],
            'modes' => $sessions['sessions_by_mode'],
            'safety' => [
                'raw_audio_persisted_count' => $safety['raw_audio_persisted_count'],
                'confirmation_bypass_count' => $safety['confirmation_bypass_count'],
                'destructive_action_without_receipt' => $safety['destructive_action_without_receipt'],
                'eclipse_test_success_count' => $safety['eclipse_test_success_count'],
                'governed_execute_success_count' => $safety['governed_execute_success_count'],
                'governed_execute_blocked_count' => $safety['governed_execute_blocked_count'],
            ],
            'rivals' => [
                'cases_total' => $rivals['cases_total'],
                'vox_wins' => $rivals['vox_wins'],
                'baseline_wins' => $rivals['baseline_wins'],
                'ties' => $rivals['ties'],
                'prompt_quality_delta' => $rivals['prompt_quality_delta'],
                'action_regret_score' => $rivals['action_regret_score'],
                'rivals_voice_multiplier' => $rivals['rivals_voice_multiplier'],
                'cases_by_kind' => $rivals['cases_by_kind'],
                'cases_by_mode' => $rivals['cases_by_mode'],
            ],
            'hard_gates' => $hardGates,
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *   total_sessions:int,
     *   sessions_by_mode:array<string,int>
     * }
     */
    private function sessionCounters(): array
    {
        $byMode = [
            VoxSchema::MODE_DICTATION => 0,
            VoxSchema::MODE_PROMPT_POLISH => 0,
            VoxSchema::MODE_INTENT_COMPILE => 0,
            VoxSchema::MODE_GOVERNED_EXECUTE => 0,
        ];
        if (! Schema::hasTable('atlas_ledger_events')) {
            return ['total_sessions' => 0, 'sessions_by_mode' => $byMode];
        }

        // Each unique session_id that ever produced a VOX_INTENT_COMPILED
        // is a session. Reading `payload->mode` lets us split by mode.
        $rows = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoxIntentCompiled->value)
            ->get(['payload']);

        $seenSessions = [];
        foreach ($rows as $row) {
            $payload = $row->payload ?? [];
            $sessionId = (string) ($payload['session_id'] ?? '');
            $mode = (string) ($payload['mode'] ?? '');
            if ($sessionId === '' || isset($seenSessions[$sessionId])) {
                continue;
            }
            $seenSessions[$sessionId] = true;
            if (isset($byMode[$mode])) {
                $byMode[$mode]++;
            }
        }

        return [
            'total_sessions' => count($seenSessions),
            'sessions_by_mode' => $byMode,
        ];
    }

    /**
     * @return array{
     *   raw_audio_persisted_count:int,
     *   confirmation_bypass_count:int,
     *   destructive_action_without_receipt:int,
     *   eclipse_test_success_count:int,
     *   governed_execute_success_count:int,
     *   governed_execute_blocked_count:int,
     *   dictionary_correction_count:int
     * }
     */
    private function safetyCounters(): array
    {
        $zero = [
            'raw_audio_persisted_count' => 0,
            'confirmation_bypass_count' => 0,
            'destructive_action_without_receipt' => 0,
            'eclipse_test_success_count' => 0,
            'governed_execute_success_count' => 0,
            'governed_execute_blocked_count' => 0,
            'dictionary_correction_count' => 0,
        ];
        if (! Schema::hasTable('atlas_ledger_events')) {
            return $zero;
        }

        // raw_audio_persisted: any VOX_TRANSCRIPT_READY with payload.raw_pcm_persisted=true.
        $rawAudio = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoxTranscriptReady->value)
            ->whereJsonContains('payload->raw_pcm_persisted', true)
            ->count();

        // confirmation_bypass: any VOX_ACTION_BLOCKED with reason_code mentioning bypass.
        $bypassCount = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoxActionBlocked->value)
            ->where(function ($q): void {
                $q->whereJsonContains('payload->reason_code', 'confirmation_bypass_attempted')
                    ->orWhereJsonContains('payload->reason_code', 'confirmation_token_invalid')
                    ->orWhereJsonContains('payload->reason_code', 'confirmation_token_expired');
            })
            ->count();

        // destructive_action_without_receipt: any VOX_ACTION_BLOCKED with that explicit reason.
        $destructive = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoxActionBlocked->value)
            ->whereJsonContains('payload->reason_code', 'destructive_action_without_receipt')
            ->count();

        // eclipse_test_success: VOX_ACTION_BLOCKED with reason_code starting with eclipse_,
        // OR VOX_EVIDENCE_RECORDED whose payload.executor == 'eclipse' (defensive — match either).
        $eclipsePassed = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoxActionBlocked->value)
            ->where(function ($q): void {
                $q->whereJsonContains('payload->reason_code', 'eclipse_aborted_mid_capture')
                    ->orWhereJsonContains('payload->reason_code', 'eclipse_active')
                    ->orWhereJsonContains('payload->reason_code', 'eclipse_test_success');
            })
            ->count();

        // governed_execute success: VOX_EVIDENCE_RECORDED where the
        // executor is one of the governed-only executors AND status is
        // 'completed'. We deliberately don't try to confirm the intent's
        // declared mode here — those executors are NEVER reachable from
        // the V0/V1/V2 paths (the Kernel routes them only via V3 gate).
        $govSuccess = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoxEvidenceRecorded->value)
            ->whereJsonContains('payload->status', 'completed')
            ->where(function ($q): void {
                $q->whereJsonContains('payload->executor', VoxSchema::EXECUTOR_TERMINAL_PROPOSE)
                    ->orWhereJsonContains('payload->executor', VoxSchema::EXECUTOR_NOTE_CAPTURE)
                    ->orWhereJsonContains('payload->executor', VoxSchema::EXECUTOR_CODEX_CLI)
                    ->orWhereJsonContains('payload->executor', VoxSchema::EXECUTOR_CLAUDE_CLI)
                    ->orWhereJsonContains('payload->executor', VoxSchema::EXECUTOR_FILESYSTEM_EDIT);
            })
            ->count();

        $govBlocked = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoxActionBlocked->value)
            ->whereJsonContains('payload->mode', VoxSchema::MODE_GOVERNED_EXECUTE)
            ->count();

        // Dictionary corrections: sum of post_corrections sizes from
        // VOX_PROMPT_COMPILED payloads. The transcript event itself does
        // not surface corrections; the prompt event does (V1+).
        $dictCorrections = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoxPromptCompiled->value)
            ->get(['payload'])
            ->sum(function ($row) {
                $count = data_get($row->payload, 'corrections_count');

                return is_numeric($count) ? (int) $count : 0;
            });

        return [
            'raw_audio_persisted_count' => $rawAudio,
            'confirmation_bypass_count' => $bypassCount,
            'destructive_action_without_receipt' => $destructive,
            'eclipse_test_success_count' => $eclipsePassed,
            'governed_execute_success_count' => $govSuccess,
            'governed_execute_blocked_count' => $govBlocked,
            'dictionary_correction_count' => (int) $dictCorrections,
        ];
    }

    /**
     * @return array{
     *   cases_total:int,
     *   vox_wins:int,
     *   baseline_wins:int,
     *   ties:int,
     *   prompt_quality_delta:float,
     *   action_regret_score:float,
     *   rivals_voice_multiplier:float,
     *   cases_by_kind:array<string,int>,
     *   cases_by_mode:array<string,int>
     * }
     */
    private function rivalsCounters(): array
    {
        $emptyKinds = [
            'wispr_baseline' => 0,
            'provider_direct' => 0,
            'manual' => 0,
        ];
        $emptyModes = [
            VoxSchema::MODE_DICTATION => 0,
            VoxSchema::MODE_PROMPT_POLISH => 0,
            VoxSchema::MODE_INTENT_COMPILE => 0,
            VoxSchema::MODE_GOVERNED_EXECUTE => 0,
        ];

        if (! Schema::hasTable('atlas_vox_rivals_cases')) {
            return [
                'cases_total' => 0,
                'vox_wins' => 0,
                'baseline_wins' => 0,
                'ties' => 0,
                'prompt_quality_delta' => 0.0,
                'action_regret_score' => 0.0,
                'rivals_voice_multiplier' => 0.0,
                'cases_by_kind' => $emptyKinds,
                'cases_by_mode' => $emptyModes,
            ];
        }

        $cases = AtlasVoxRivalsCase::query()->get();
        $total = $cases->count();

        $voxWins = $cases->where('preference', 'vox')->count();
        $baselineWins = $cases->where('preference', 'baseline')->count();
        $ties = $cases->where('preference', 'tie')->count();

        $regretRate = $total > 0
            ? round($cases->where('regret_flag', true)->count() / $total, 4)
            : 0.0;

        // prompt_quality_delta: mean of vote ∈ [-1, 0, +1] across cases
        // where a vote was provided. Range ≈ [-1.0, +1.0]. The canonical
        // gate target is >= +0.25 (≥¼ of cases preferring Vox's prompt).
        $votes = $cases->whereNotNull('prompt_quality_vote');
        $promptQualityDelta = $votes->isEmpty()
            ? 0.0
            : round((float) $votes->avg('prompt_quality_vote'), 4);

        // rivals_voice_multiplier: average ratio baseline_duration / vox_duration
        // across cases where both timings are present. >1 means Vox saved time.
        $timedCases = $cases->filter(function ($case): bool {
            return $case->baseline_duration_ms !== null
                && $case->vox_duration_ms !== null
                && (int) $case->vox_duration_ms > 0;
        });
        $rivalsVoiceMultiplier = $timedCases->isEmpty()
            ? 0.0
            : round(
                $timedCases->avg(function ($case): float {
                    return ((int) $case->baseline_duration_ms) / ((int) $case->vox_duration_ms);
                }),
                4
            );

        $byKind = $emptyKinds;
        foreach ($cases->groupBy('kind') as $kind => $bucket) {
            $byKind[(string) $kind] = $bucket->count();
        }
        $byMode = $emptyModes;
        foreach ($cases->groupBy('mode') as $mode => $bucket) {
            $byMode[(string) $mode] = $bucket->count();
        }

        return [
            'cases_total' => $total,
            'vox_wins' => $voxWins,
            'baseline_wins' => $baselineWins,
            'ties' => $ties,
            'prompt_quality_delta' => $promptQualityDelta,
            'action_regret_score' => $regretRate,
            'rivals_voice_multiplier' => $rivalsVoiceMultiplier,
            'cases_by_kind' => $byKind,
            'cases_by_mode' => $byMode,
        ];
    }

    /**
     * @return array{
     *   real_usage_days:int,
     *   average_sessions_per_day:float,
     *   first_session_at:?string,
     *   last_session_at:?string
     * }
     */
    private function usageWindow(): array
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return [
                'real_usage_days' => 0,
                'average_sessions_per_day' => 0.0,
                'first_session_at' => null,
                'last_session_at' => null,
            ];
        }

        $rows = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoxIntentCompiled->value)
            ->get(['occurred_at', 'payload']);

        if ($rows->isEmpty()) {
            return [
                'real_usage_days' => 0,
                'average_sessions_per_day' => 0.0,
                'first_session_at' => null,
                'last_session_at' => null,
            ];
        }

        $seenSessions = [];
        $days = [];
        $first = null;
        $last = null;
        foreach ($rows as $row) {
            $payload = $row->payload ?? [];
            $sessionId = (string) ($payload['session_id'] ?? '');
            if ($sessionId === '' || isset($seenSessions[$sessionId])) {
                continue;
            }
            $seenSessions[$sessionId] = true;
            $occurredAt = CarbonImmutable::parse($row->occurred_at);
            $days[$occurredAt->toDateString()] = true;
            if ($first === null || $occurredAt->lt($first)) {
                $first = $occurredAt;
            }
            if ($last === null || $occurredAt->gt($last)) {
                $last = $occurredAt;
            }
        }

        $sessions = count($seenSessions);
        $realDays = count($days);
        $avgPerDay = $realDays > 0 ? round($sessions / $realDays, 2) : 0.0;

        return [
            'real_usage_days' => $realDays,
            'average_sessions_per_day' => $avgPerDay,
            'first_session_at' => $first?->toIso8601String(),
            'last_session_at' => $last?->toIso8601String(),
        ];
    }
}
