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
    /**
     * V6-E · evento de dogfood automático. Pousa dentro do envelope
     * `metadata.auto_event` do `dogfood_session.v1`, mantendo a tabela
     * inalterada (sem migration) mas marcando o schema vivo.
     */
    public const SCHEMA_AUTO_EVENT = 'atlas.vox.dogfood_event.v1';

    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_PARTIAL = 'partial';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_CANCELLED = 'cancelled';

    public const SOURCE_HOTKEY = 'hotkey';
    public const SOURCE_AMBIENT_HELPER = 'ambient_helper';
    public const SOURCE_AMBIENT_LAUNCH = 'ambient_launch';
    public const SOURCE_APP = 'app';
    public const SOURCE_UNKNOWN = 'unknown';

    public const CLICKED_INSERT = 'insert';
    public const CLICKED_SEND = 'send';
    public const CLICKED_CONFIRM = 'confirm';
    public const CLICKED_CANCEL = 'cancel';
    public const CLICKED_NONE = 'none';

    public const INTERVENTION_NONE = 'none';
    public const INTERVENTION_CLARIFY = 'clarify';
    public const INTERVENTION_CAUTION = 'caution';
    public const INTERVENTION_DISAGREE = 'disagree';
    public const INTERVENTION_SUGGEST = 'suggest_better_prompt';

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
     * @return list<string>
     */
    public static function allowedLaunchSources(): array
    {
        return [
            self::SOURCE_HOTKEY,
            self::SOURCE_AMBIENT_HELPER,
            self::SOURCE_AMBIENT_LAUNCH,
            self::SOURCE_APP,
            self::SOURCE_UNKNOWN,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedClickedActions(): array
    {
        return [
            self::CLICKED_INSERT,
            self::CLICKED_SEND,
            self::CLICKED_CONFIRM,
            self::CLICKED_CANCEL,
            self::CLICKED_NONE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedInterventionKinds(): array
    {
        return [
            self::INTERVENTION_NONE,
            self::INTERVENTION_CLARIFY,
            self::INTERVENTION_CAUTION,
            self::INTERVENTION_DISAGREE,
            self::INTERVENTION_SUGGEST,
        ];
    }

    /**
     * V6-E · campos estruturais que o overlay anexa via `auto_event`.
     * Anything outside this list is silently dropped antes de persistir.
     * Mantém o contrato pequeno e auditável.
     *
     * @return list<string>
     */
    public static function autoEventFields(): array
    {
        return [
            'suggested_mode',
            'final_mode',
            'manual_override',
            'auto_router_confidence',
            'intervention_present',
            'intervention_kind',
            'empty_transcript',
            'stt_failed',
            'clicked_action',
            'launch_source',
            'error_kind',
            'raw_audio_persisted',
            // V6-K · sinais novos sem atrito. São booleans / chars curtos,
            // nunca textos longos ou conteúdo do usuário.
            // - `re_recorded`: operador apertou "Gravar de novo" no overlay
            //   antes de confirmar (sinal de qualidade do STT na rodada).
            // - `edited_transcript`: operador editou o transcript no overlay
            //   antes de enviar (sinal de calibração de dicionário).
            're_recorded',
            'edited_transcript',
        ];
    }

    /**
     * V6-E · campos textuais proibidos no envelope automático. Mesmo que o
     * cliente envie por engano, o service apaga antes de gravar — qualquer
     * conteúdo bruto fica fora do diário.
     *
     * @return list<string>
     */
    public static function prohibitedAutoEventFields(): array
    {
        return [
            // Conteúdo de fala / prompt — nunca entra no diário.
            'transcript',
            'transcript_text',
            'transcript_raw',
            'prompt_body',
            'compiled_prompt',
            'compiled_prompt_template',
            // Áudio cru.
            'audio',
            'audio_bytes',
            'audio_base64',
            'pcm',
            'pcm_bytes',
            'wav',
            // Clipboard.
            'clipboard',
            'clipboard_text',
            // Tokens de confirmação V3 (single-shot, nunca persistidos).
            'confirmation_token',
            'literal_confirmation_text',
            // V6-K · segredos genéricos. Defesa em profundidade — operador
            // nunca deve enviar isso, e se enviou por engano (ex.: log de erro
            // colado), apagamos antes da gravação.
            'authorization',
            'auth_token',
            'access_token',
            'refresh_token',
            'bearer',
            'api_key',
            'apikey',
            'secret',
            'password',
            'cookie',
            'session_cookie',
            // V6-K · stack traces e exceções com mensagem completa. O canal
            // canon é `error_kind` (≤ 80 chars, categórico). Stack trace
            // completo é ruído e pode vazar paths absolutos.
            'stack_trace',
            'stacktrace',
            'exception_trace',
            'exception_message',
            'trace',
            'error_message_full',
            'error_stack',
            // V6-K · dados pessoais. O dogfood é diário técnico, não diário
            // pessoal. Se algum dia o transcript escapou, garantimos que
            // chaves óbvias não viram persistência.
            'email',
            'phone',
            'cpf',
            'rg',
            'address',
            'personal_info',
            // V6-K · paths sensíveis. `error_kind` curto é OK; full path do
            // dev é ruído + possível vazamento.
            'home_path',
            'absolute_path',
            'file_content',
            'file_body',
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

        // V6-E · auto_event vai dentro de metadata para preservar a tabela
        // existente (zero migration). Sanitização rejeita campos brutos.
        $metadata = $payload['metadata'] ?? null;
        if (isset($payload['auto_event']) && is_array($payload['auto_event'])) {
            $metadata = is_array($metadata) ? $metadata : [];
            $metadata['auto_event'] = $this->sanitizeAutoEvent($payload['auto_event']);
        }
        // Garante invariante: raw_audio_persisted vira sempre false no auto_event.
        if (isset($metadata['auto_event']) && is_array($metadata['auto_event'])) {
            $metadata['auto_event']['raw_audio_persisted'] = false;
            $metadata['auto_event']['schema'] = self::SCHEMA_AUTO_EVENT;
        }

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
            'metadata' => $metadata,
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

        // V6-E · agregação dos auto_events embutidos em metadata. Ignora
        // sessões legadas (sem auto_event) — totais sempre honestos.
        $autoAggregates = $this->aggregateAutoEvents($sessions);

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
            // V6-E · sinais novos. Cada um devolve `rate` baseado no número
            // de sessões com auto_event presente, não no total global —
            // sessões antigas (sem auto_event) não distorcem.
            'auto_event_coverage' => $this->rate($autoAggregates['auto_event_count'], $total),
            'auto_event_sessions' => $autoAggregates['auto_event_count'],
            'manual_override_rate' => $autoAggregates['manual_override_rate'],
            'intervention_rate' => $autoAggregates['intervention_rate'],
            'intervention_by_kind' => $autoAggregates['intervention_by_kind'],
            'stt_failed_rate' => $autoAggregates['stt_failed_rate'],
            'empty_transcript_rate' => $autoAggregates['empty_transcript_rate'],
            'launch_source_breakdown' => $autoAggregates['launch_source_breakdown'],
            'clicked_action_breakdown' => $autoAggregates['clicked_action_breakdown'],
            'auto_router_confidence_avg' => $autoAggregates['auto_router_confidence_avg'],
            // V6-K · regravação dentro da sessão + edição manual do transcript.
            // Útil pra entender se o STT está dando trabalho extra ao operador
            // (alto re_recorded = ajustar dicionário; alto edited = afinar).
            're_recorded_rate' => $autoAggregates['re_recorded_rate'],
            'edited_transcript_rate' => $autoAggregates['edited_transcript_rate'],
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
     * V6-E · marca uma sessão existente como `regret=true` ou desmarca.
     * Pequeno e idempotente: serve só pro chip "Marcar como ruim" / "Voltou
     * a funcionar". Não muda outcome, não cria eventos novos no ledger
     * (regret é um override leve sobre o que o auto-record já capturou).
     *
     * Retorna a sessão atualizada ou `null` se não existir.
     */
    public function updateFeedback(string $dogfoodSessionId, bool $regretFlag): ?AtlasVoxDogfoodSession
    {
        $session = AtlasVoxDogfoodSession::query()
            ->where('dogfood_session_id', '=', $dogfoodSessionId)
            ->first();
        if ($session === null) {
            return null;
        }
        $session->regret_flag = $regretFlag;
        $session->save();
        return $session;
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function sanitizeAutoEvent(array $raw): array
    {
        $out = [];
        $allowed = self::autoEventFields();
        $prohibited = self::prohibitedAutoEventFields();
        foreach ($raw as $k => $v) {
            $key = (string) $k;
            if (in_array($key, $prohibited, true)) {
                // Silenciosamente descarta — auditável via tracing seria
                // melhor, mas o canon é: ninguém envia isso por engano e
                // se enviou, o service garante que não persiste.
                continue;
            }
            if (! in_array($key, $allowed, true)) {
                continue;
            }
            $out[$key] = $v;
        }
        // Normaliza enums conhecidos para evitar lixo silenciosamente
        // distorcendo o report.
        if (isset($out['launch_source']) && is_string($out['launch_source'])) {
            $out['launch_source'] = in_array($out['launch_source'], self::allowedLaunchSources(), true)
                ? $out['launch_source']
                : self::SOURCE_UNKNOWN;
        }
        if (isset($out['clicked_action']) && is_string($out['clicked_action'])) {
            $out['clicked_action'] = in_array($out['clicked_action'], self::allowedClickedActions(), true)
                ? $out['clicked_action']
                : self::CLICKED_NONE;
        }
        if (isset($out['intervention_kind']) && is_string($out['intervention_kind'])) {
            $out['intervention_kind'] = in_array($out['intervention_kind'], self::allowedInterventionKinds(), true)
                ? $out['intervention_kind']
                : self::INTERVENTION_NONE;
        }
        if (isset($out['suggested_mode']) && is_string($out['suggested_mode'])) {
            $out['suggested_mode'] = in_array($out['suggested_mode'], self::allowedModes(), true)
                ? $out['suggested_mode']
                : null;
        }
        if (isset($out['final_mode']) && is_string($out['final_mode'])) {
            $out['final_mode'] = in_array($out['final_mode'], self::allowedModes(), true)
                ? $out['final_mode']
                : null;
        }
        if (isset($out['auto_router_confidence'])
            && ! is_float($out['auto_router_confidence'])
            && ! is_int($out['auto_router_confidence'])) {
            unset($out['auto_router_confidence']);
        } elseif (isset($out['auto_router_confidence'])) {
            $out['auto_router_confidence'] = max(0.0, min(1.0, (float) $out['auto_router_confidence']));
        }
        return $out;
    }

    /**
     * @param  \Illuminate\Support\Collection<int,AtlasVoxDogfoodSession>  $sessions
     * @return array<string,mixed>
     */
    private function aggregateAutoEvents($sessions): array
    {
        $launchBreakdown = array_fill_keys(self::allowedLaunchSources(), 0);
        $clickedBreakdown = array_fill_keys(self::allowedClickedActions(), 0);
        $interventionByKind = array_fill_keys(self::allowedInterventionKinds(), 0);

        $autoCount = 0;
        $overrideCount = 0;
        $interventionCount = 0;
        $sttFailedCount = 0;
        $emptyTranscriptCount = 0;
        $reRecordedCount = 0;
        $editedTranscriptCount = 0;
        $confidenceSum = 0.0;
        $confidenceCount = 0;

        foreach ($sessions as $session) {
            $meta = $session->metadata;
            if (! is_array($meta) || ! isset($meta['auto_event']) || ! is_array($meta['auto_event'])) {
                continue;
            }
            $ev = $meta['auto_event'];
            $autoCount++;
            if (($ev['manual_override'] ?? false) === true) {
                $overrideCount++;
            }
            if (($ev['intervention_present'] ?? false) === true) {
                $interventionCount++;
            }
            $kind = is_string($ev['intervention_kind'] ?? null) ? $ev['intervention_kind'] : self::INTERVENTION_NONE;
            if (isset($interventionByKind[$kind])) {
                $interventionByKind[$kind]++;
            }
            if (($ev['stt_failed'] ?? false) === true) {
                $sttFailedCount++;
            }
            if (($ev['empty_transcript'] ?? false) === true) {
                $emptyTranscriptCount++;
            }
            // V6-K · agrega sinais de regravação e edição manual.
            if (($ev['re_recorded'] ?? false) === true) {
                $reRecordedCount++;
            }
            if (($ev['edited_transcript'] ?? false) === true) {
                $editedTranscriptCount++;
            }
            $launch = is_string($ev['launch_source'] ?? null) ? $ev['launch_source'] : self::SOURCE_UNKNOWN;
            if (isset($launchBreakdown[$launch])) {
                $launchBreakdown[$launch]++;
            }
            $clicked = is_string($ev['clicked_action'] ?? null) ? $ev['clicked_action'] : self::CLICKED_NONE;
            if (isset($clickedBreakdown[$clicked])) {
                $clickedBreakdown[$clicked]++;
            }
            if (isset($ev['auto_router_confidence'])
                && (is_int($ev['auto_router_confidence']) || is_float($ev['auto_router_confidence']))) {
                $confidenceSum += (float) $ev['auto_router_confidence'];
                $confidenceCount++;
            }
        }

        return [
            'auto_event_count' => $autoCount,
            'manual_override_rate' => $this->rate($overrideCount, $autoCount),
            'intervention_rate' => $this->rate($interventionCount, $autoCount),
            'intervention_by_kind' => $interventionByKind,
            'stt_failed_rate' => $this->rate($sttFailedCount, $autoCount),
            'empty_transcript_rate' => $this->rate($emptyTranscriptCount, $autoCount),
            'launch_source_breakdown' => $launchBreakdown,
            'clicked_action_breakdown' => $clickedBreakdown,
            'auto_router_confidence_avg' => $confidenceCount > 0
                ? round($confidenceSum / $confidenceCount, 4)
                : 0.0,
            // V6-K · taxas novas no relatório.
            're_recorded_rate' => $this->rate($reRecordedCount, $autoCount),
            'edited_transcript_rate' => $this->rate($editedTranscriptCount, $autoCount),
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
