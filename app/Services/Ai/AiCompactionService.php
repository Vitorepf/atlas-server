<?php

namespace App\Services\Ai;

use App\Models\AiCompaction;
use App\Models\AiMessage;
use App\Models\AiQualityAction;
use App\Models\AiQualityEvaluation;
use App\Models\AiSession;
use App\Models\AiSessionState;
use App\Models\AiThread;
use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AiCompactionService
{
    private const TRANSACTION_ATTEMPTS = 5;

    public function maybeAutoCompact(AiThread $thread, AiSession $session): ?AiCompaction
    {
        return DB::transaction(function () use ($thread, $session): ?AiCompaction {
            /** @var AiThread $lockedThread */
            $lockedThread = AiThread::query()
                ->whereKey($thread->id)
                ->lockForUpdate()
                ->firstOrFail();

            $messageCount = AiMessage::query()->where('thread_id', $lockedThread->id)->count();
            if ($messageCount < $this->autoMessageThreshold()) {
                return null;
            }

            $latest = AiCompaction::query()
                ->where('thread_id', $lockedThread->id)
                ->latest('created_at')
                ->lockForUpdate()
                ->first();

            $latestEnd = (int) ($latest?->source_position_end ?? 0);
            $maxPosition = (int) AiMessage::query()->where('thread_id', $lockedThread->id)->max('position');

            if (($maxPosition - $latestEnd) < $this->autoMessagesSinceLast()) {
                return null;
            }

            return $this->compactLocked($lockedThread, $session, 'auto', [
                'trigger' => 'message_threshold',
                'message_count' => $messageCount,
                'messages_since_last_compaction' => $maxPosition - $latestEnd,
            ]);
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function compact(AiThread $thread, ?AiSession $session, string $reason = 'manual', array $metadata = []): AiCompaction
    {
        return DB::transaction(function () use ($thread, $session, $reason, $metadata): AiCompaction {
            /** @var AiThread $lockedThread */
            $lockedThread = AiThread::query()
                ->whereKey($thread->id)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->compactLocked($lockedThread, $session, $reason, $metadata);
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function compactLocked(AiThread $thread, ?AiSession $session, string $reason, array $metadata): AiCompaction
    {
        $messages = AiMessage::query()
            ->where('thread_id', $thread->id)
            ->whereIn('role', ['user', 'assistant', 'summary'])
            ->where('status', '!=', 'redacted')
            ->orderBy('position')
            ->get();
        $protectedMessages = $messages->filter(fn (AiMessage $message): bool => str_contains($message->content, '<skill_content name='));
        $protectedSkillNames = $this->protectedSkillNames($protectedMessages);
        $compactableMessages = $messages->reject(fn (AiMessage $message): bool => str_contains($message->content, '<skill_content name='))->values();

        $state = AiSessionState::query()
            ->where('thread_id', $thread->id)
            ->where('active', true)
            ->latest('updated_at')
            ->first();

        $quality = $this->qualityContext($thread);
        $summary = $this->summary($thread, $compactableMessages, $state, $quality);
        $structured = $this->structuredState($thread, $compactableMessages, $state, $quality) + [
            'protected_skill_context' => [
                'message_count' => $protectedMessages->count(),
                'skill_names' => $protectedSkillNames,
                'policy' => 'skill_content_messages_are_not_summarized_as_conversation_turns',
            ],
        ];
        $tokenBefore = (int) $compactableMessages->sum('token_estimate');
        $tokenAfter = max(1, (int) ceil(mb_strlen($summary) / 4));

        $compaction = AiCompaction::query()->create([
            'thread_id' => $thread->id,
            'session_id' => $session?->id,
            'reason' => in_array($reason, ['manual', 'auto', 'provider_switch', 'phase_change', 'session_resume', 'session_close'], true) ? $reason : 'manual',
            'source_position_start' => $compactableMessages->min('position'),
            'source_position_end' => $compactableMessages->max('position'),
            'source_message_count' => $compactableMessages->count(),
            'summary' => $summary,
            'structured_state' => $structured,
            'token_estimate_before' => $tokenBefore,
            'token_estimate_after' => $tokenAfter,
            'quality_gate_status' => $this->qualityGateStatus($structured),
            'provider' => data_get($metadata, 'provider'),
            'model' => data_get($metadata, 'model'),
            'metadata' => array_merge($metadata, [
                'created_by' => 'ai_compaction_service',
                'compression_ratio_estimate' => $tokenBefore > 0 ? round($tokenAfter / $tokenBefore, 4) : null,
                'protected_skill_message_count' => $protectedMessages->count(),
                'protected_skill_names' => $protectedSkillNames,
            ]),
        ]);

        $thread->update([
            'summary' => $summary,
            'metadata' => array_merge($thread->metadata ?? [], [
                'last_compaction_id' => $compaction->id,
                'last_compaction_reason' => $compaction->reason,
                'last_compaction_at' => $compaction->created_at?->toJSON(),
            ]),
        ]);

        return $compaction->refresh();
    }

    private function protectedSkillNames($messages): array
    {
        return $messages
            ->flatMap(function (AiMessage $message): array {
                preg_match_all('/<skill_content\s+name="([^"]+)"/', $message->content, $matches);

                return $matches[1] ?? [];
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function summary(AiThread $thread, $messages, ?AiSessionState $state, array $quality): string
    {
        $parts = [];
        $parts[] = 'Thread: '.$thread->title;

        if ($state?->objective) {
            $parts[] = 'Objetivo atual: '.$state->objective;
        }

        if ($state?->current_phase) {
            $parts[] = 'Fase atual: '.$state->current_phase;
        }

        $decisions = collect($state?->decisions ?? [])->pluck('text')->filter()->take(6)->values();
        if ($decisions->isNotEmpty()) {
            $parts[] = 'Decisoes preservadas: '.$decisions->implode(' | ');
        }

        $openLoops = collect($state?->open_loops ?? [])->pluck('text')->filter()->take(6)->values();
        if ($openLoops->isNotEmpty()) {
            $parts[] = 'Pendencias/open loops: '.$openLoops->implode(' | ');
        }

        $nextSteps = collect($state?->next_steps ?? [])->pluck('text')->filter()->take(6)->values();
        if ($nextSteps->isNotEmpty()) {
            $parts[] = 'Proximos passos: '.$nextSteps->implode(' | ');
        }

        $recent = $messages
            ->take(-6)
            ->map(fn (AiMessage $message): string => "{$message->role}: ".Str::limit(trim($message->content), 320, '...'))
            ->implode(' || ');

        if ($recent !== '') {
            $parts[] = 'Ultimos turnos relevantes: '.$recent;
        }

        $qualityNotes = collect($quality['recent_evaluations'] ?? [])
            ->filter(fn (array $evaluation): bool => ($evaluation['status'] ?? null) !== 'passed')
            ->map(fn (array $evaluation): string => "score {$evaluation['score']} {$evaluation['status']} flags=".implode(',', $evaluation['flags'] ?? []))
            ->take(4)
            ->implode(' | ');

        if ($qualityNotes !== '') {
            $parts[] = 'Notas de qualidade a preservar: '.$qualityNotes;
        }

        return Str::limit(implode("\n", $parts), 12000, '...');
    }

    private function structuredState(AiThread $thread, $messages, ?AiSessionState $state, array $quality): array
    {
        return [
            'schema_version' => 1,
            'thread' => [
                'id' => $thread->id,
                'title' => $thread->title,
                'status' => $thread->status,
            ],
            'state' => [
                'objective' => $state?->objective,
                'current_phase' => $state?->current_phase,
                'current_topic' => $state?->current_topic,
                'user_position' => $state?->user_position,
                'decisions' => $state?->decisions ?? [],
                'open_loops' => $state?->open_loops ?? [],
                'next_steps' => $state?->next_steps ?? [],
                'relevant_artifacts' => $state?->relevant_artifacts ?? [],
                'constraints' => $state?->constraints ?? [],
            ],
            'message_window' => [
                'count' => $messages->count(),
                'start_position' => $messages->min('position'),
                'end_position' => $messages->max('position'),
            ],
            'quality' => $quality,
        ];
    }

    private function qualityGateStatus(array $structured): string
    {
        $state = $structured['state'] ?? [];
        $quality = $structured['quality'] ?? [];

        if (($quality['open_action_count'] ?? 0) > 0 || ($quality['failed_evaluation_count'] ?? 0) > 0) {
            return 'needs_review';
        }

        if (empty($state['objective']) && empty($state['current_topic'])) {
            return 'needs_review';
        }

        return 'passed';
    }

    private function qualityContext(AiThread $thread): array
    {
        $evaluations = [];
        $openActionCount = 0;
        $failedEvaluationCount = 0;

        if (Schema::hasTable('ai_quality_evaluations')) {
            $recent = AiQualityEvaluation::query()
                ->where('thread_id', $thread->id)
                ->latest('created_at')
                ->limit(8)
                ->get();

            $evaluations = $recent
                ->map(fn (AiQualityEvaluation $evaluation): array => [
                    'id' => $evaluation->id,
                    'trace_id' => $evaluation->trace_id,
                    'score' => $evaluation->score,
                    'status' => $evaluation->status,
                    'flags' => collect($evaluation->flags)->pluck('code')->values()->all(),
                ])
                ->values()
                ->all();

            $failedEvaluationCount = AiQualityEvaluation::query()
                ->where('thread_id', $thread->id)
                ->where('status', 'failed')
                ->count();
        }

        if (Schema::hasTable('ai_quality_actions')) {
            $openActionCount = AiQualityAction::query()
                ->where('thread_id', $thread->id)
                ->whereIn('status', ['queued', 'running', 'blocked', 'failed'])
                ->count();
        }

        return [
            'recent_evaluations' => $evaluations,
            'open_action_count' => $openActionCount,
            'failed_evaluation_count' => $failedEvaluationCount,
        ];
    }

    private function autoMessageThreshold(): int
    {
        return max(2, (int) config('atlas.ai.auto_compaction_message_threshold', 18));
    }

    private function autoMessagesSinceLast(): int
    {
        return max(1, (int) config('atlas.ai.auto_compaction_messages_since_last', 10));
    }

    /**
     * TEOS-I1 M4 entry point: compact a long-horizon scope and emit a
     * `atlas.long_horizon.compaction_receipt.v1` row.
     *
     * Inputs (all optional unless marked):
     *  - `scope_type` (required, must be in {@see AtlasLongHorizonCanon::ALLOWED_SCOPE_TYPES}).
     *  - `scope_id`   (string|null).
     *  - `source_context_refs` (list<string|array>).
     *  - `must_keep_items` (list<array>): each item carries
     *    `id` (string), `kind` (string; e.g. `decision`, `blocker`, `dod`,
     *    `risk_critical`, `evidence`, `fact`, `decision_note`), `digest`
     *    (short text), optional `payload` (rich body).
     *  - `forced_discards` (list<array{id:string, reason:string}>):
     *    caller-supplied assertion that an item could not be retained
     *    (budget pressure, downstream policy, etc.). Reason is one of
     *    {@see AtlasLongHorizonCanon::ALLOWED_DISCARDED_REASONS}.
     *  - `evidence_refs` (list<string|array>).
     *  - `stale_risks` (list<array|string>).
     *  - `detected_contradictions` (list<array>).
     *  - `recovery_queries` (list<string>): caller-supplied hints; engine
     *    adds derived queries for each unresolved_loss item.
     *  - `actor_alias` (string): recorded in receipt metadata; default
     *    `ai_compaction_service`.
     *
     * Output mirrors the canonical receipt plus auditing fields:
     *  - `summary` + `summary_hash` (sha256 of summary text).
     *  - `compaction_receipt_id` (DB id) + `receipt_hash`.
     *  - `must_keep_coverage` (0.0–1.0; **1.0 required for write_allowed**).
     *  - `unresolved_loss` (list of must_keep items dropped, with reason).
     *  - `loss_risk` (low|medium|high).
     *  - `write_allowed` (bool): false when coverage < 1.0 OR when a
     *    forced_discard touched a CRITICAL_KEEP_KIND.
     *
     * Reuse-first: this method does NOT spin up a parallel compaction
     * engine. It composes the receipt from caller-supplied items and the
     * service's existing summary/structured-state helpers when applicable.
     * Legacy `compact()` / `maybeAutoCompact()` paths are untouched.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compactForScope(array $input): array
    {
        $scopeType = $this->requireString($input, 'scope_type');
        if (! in_array($scopeType, AtlasLongHorizonCanon::ALLOWED_SCOPE_TYPES, true)) {
            throw new InvalidArgumentException(
                'AiCompactionService::compactForScope scope_type must be one of ['
                .implode(',', AtlasLongHorizonCanon::ALLOWED_SCOPE_TYPES)."], got '{$scopeType}'."
            );
        }
        $scopeId = isset($input['scope_id']) && is_string($input['scope_id']) && trim($input['scope_id']) !== ''
            ? trim($input['scope_id'])
            : null;

        $mustKeepItems = $this->normaliseMustKeepItems((array) ($input['must_keep_items'] ?? []));
        $forcedDiscards = $this->normaliseForcedDiscards((array) ($input['forced_discards'] ?? []));
        $sourceContextRefs = array_values((array) ($input['source_context_refs'] ?? []));
        $evidenceRefs = array_values((array) ($input['evidence_refs'] ?? []));
        $staleRisks = array_values((array) ($input['stale_risks'] ?? []));
        $detectedContradictions = array_values((array) ($input['detected_contradictions'] ?? []));
        $callerRecoveryQueries = $this->normaliseStringList((array) ($input['recovery_queries'] ?? []));
        $actorAlias = isset($input['actor_alias']) && is_string($input['actor_alias']) && trim($input['actor_alias']) !== ''
            ? trim($input['actor_alias'])
            : 'ai_compaction_service';

        [$retainedItems, $discardedItems, $unresolvedLoss] = $this->splitMustKeepItems(
            $mustKeepItems,
            $forcedDiscards,
        );

        $mustKeepCoverage = $mustKeepItems === []
            ? 1.0
            : round(count($retainedItems) / max(1, count($mustKeepItems)), 3);

        $touchedCriticalKind = false;
        foreach ($unresolvedLoss as $loss) {
            $kind = (string) ($loss['kind'] ?? '');
            if (in_array($kind, AtlasLongHorizonCanon::CRITICAL_KEEP_KINDS, true)) {
                $touchedCriticalKind = true;
                break;
            }
        }

        $writeAllowed = $mustKeepCoverage >= 1.0 && ! $touchedCriticalKind;
        $lossRisk = $this->deriveLossRisk(
            mustKeepCoverage: $mustKeepCoverage,
            touchedCriticalKind: $touchedCriticalKind,
            forcedDiscards: $forcedDiscards,
        );

        $summary = $this->composeScopeSummary(
            scopeType: $scopeType,
            scopeId: $scopeId,
            retained: $retainedItems,
            unresolvedLoss: $unresolvedLoss,
            evidenceRefs: $evidenceRefs,
            staleRisks: $staleRisks,
        );
        $summaryHash = hash('sha256', $summary);

        $dominantDiscardReason = $this->dominantDiscardReason($forcedDiscards);
        $recoveryQueries = $this->mergeRecoveryQueries($callerRecoveryQueries, $unresolvedLoss);
        $qualityScore = $this->deriveQualityScore(
            mustKeepCoverage: $mustKeepCoverage,
            unresolvedLoss: $unresolvedLoss,
            staleRisks: $staleRisks,
        );

        $payload = [
            'schema_version' => AtlasLongHorizonCanon::COMPACTION_RECEIPT_SCHEMA_VERSION,
            'uuid' => (string) Str::uuid(),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'source_context_refs' => $sourceContextRefs,
            'retained_items' => $retainedItems,
            'discarded_items' => $discardedItems,
            'discarded_reason' => $dominantDiscardReason,
            'must_keep_items' => array_values(array_map(
                static fn (array $i): array => [
                    'id' => $i['id'],
                    'kind' => $i['kind'],
                    'digest' => $i['digest'] ?? null,
                ],
                $mustKeepItems,
            )),
            'must_keep_coverage' => $mustKeepCoverage,
            'unresolved_loss' => $unresolvedLoss,
            'loss_risk' => $lossRisk,
            'recovery_queries' => $recoveryQueries,
            'evidence_refs' => $evidenceRefs,
            'summary_hash' => $summaryHash,
            'quality_score' => $qualityScore,
            'detected_contradictions' => $detectedContradictions,
            'stale_risks' => $staleRisks,
        ];

        $receiptHash = AtlasLongHorizonCompactionReceipt::canonicalReceiptHash($payload);
        $payload['receipt_hash'] = $receiptHash;

        $row = AtlasLongHorizonCompactionReceipt::query()->create($payload);

        return [
            'schema_version' => AtlasLongHorizonCanon::COMPACTION_RECEIPT_SCHEMA_VERSION,
            'compaction_receipt_id' => $row->id,
            'receipt_uuid' => $row->uuid,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'summary' => $summary,
            'summary_hash' => $summaryHash,
            'receipt_hash' => $receiptHash,
            'retained_items' => $retainedItems,
            'discarded_items' => $discardedItems,
            'discarded_reason' => $dominantDiscardReason,
            'must_keep_items' => $payload['must_keep_items'],
            'must_keep_coverage' => $mustKeepCoverage,
            'unresolved_loss' => $unresolvedLoss,
            'loss_risk' => $lossRisk,
            'recovery_queries' => $recoveryQueries,
            'evidence_refs' => $evidenceRefs,
            'detected_contradictions' => $detectedContradictions,
            'stale_risks' => $staleRisks,
            'source_context_refs' => $sourceContextRefs,
            'quality_score' => $qualityScore,
            'write_allowed' => $writeAllowed,
            'actor_alias' => $actorAlias,
            'created_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<int,mixed>  $items
     * @return list<array{id:string,kind:string,digest:?string,payload:mixed}>
     */
    private function normaliseMustKeepItems(array $items): array
    {
        $out = [];
        foreach ($items as $i => $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = isset($item['id']) && is_string($item['id']) && trim($item['id']) !== ''
                ? trim($item['id'])
                : ('mk-'.($i + 1));
            $kind = isset($item['kind']) && is_string($item['kind']) && trim($item['kind']) !== ''
                ? trim($item['kind'])
                : 'fact';
            $digest = isset($item['digest']) && is_string($item['digest']) ? $item['digest'] : null;
            $out[] = [
                'id' => $id,
                'kind' => $kind,
                'digest' => $digest,
                'payload' => $item['payload'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int,mixed>  $forced
     * @return list<array{id:string,reason:string}>
     */
    private function normaliseForcedDiscards(array $forced): array
    {
        $out = [];
        foreach ($forced as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $id = isset($entry['id']) && is_string($entry['id']) ? trim($entry['id']) : '';
            if ($id === '') {
                continue;
            }
            $reason = isset($entry['reason']) && is_string($entry['reason'])
                ? trim($entry['reason'])
                : AtlasLongHorizonCanon::DISCARDED_REASON_BUDGET_PRESSURE;
            if (! in_array($reason, AtlasLongHorizonCanon::ALLOWED_DISCARDED_REASONS, true)) {
                $reason = AtlasLongHorizonCanon::DISCARDED_REASON_LOW_SIGNAL;
            }
            $out[] = ['id' => $id, 'reason' => $reason];
        }

        return $out;
    }

    /**
     * @param  array<int,mixed>  $list
     * @return list<string>
     */
    private function normaliseStringList(array $list): array
    {
        $out = [];
        foreach ($list as $v) {
            if (is_string($v) && trim($v) !== '') {
                $out[] = $v;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{id:string,kind:string,digest:?string,payload:mixed}>  $mustKeepItems
     * @param  list<array{id:string,reason:string}>  $forcedDiscards
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:list<array<string,mixed>>}
     */
    private function splitMustKeepItems(array $mustKeepItems, array $forcedDiscards): array
    {
        $forcedById = [];
        foreach ($forcedDiscards as $fd) {
            $forcedById[$fd['id']] = $fd['reason'];
        }

        $retained = [];
        $discarded = [];
        $unresolvedLoss = [];

        foreach ($mustKeepItems as $item) {
            $id = $item['id'];
            if (isset($forcedById[$id])) {
                $reason = $forcedById[$id];
                $entry = [
                    'id' => $id,
                    'kind' => $item['kind'],
                    'digest' => $item['digest'],
                    'reason' => $reason,
                ];
                $discarded[] = $entry;
                $unresolvedLoss[] = $entry;

                continue;
            }
            $retained[] = [
                'id' => $id,
                'kind' => $item['kind'],
                'digest' => $item['digest'],
            ];
        }

        return [$retained, $discarded, $unresolvedLoss];
    }

    /**
     * @param  list<array{id:string,reason:string}>  $forcedDiscards
     */
    private function dominantDiscardReason(array $forcedDiscards): ?string
    {
        if ($forcedDiscards === []) {
            return null;
        }
        $counts = [];
        foreach ($forcedDiscards as $fd) {
            $counts[$fd['reason']] = ($counts[$fd['reason']] ?? 0) + 1;
        }
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * @param  list<array{id:string,reason:string}>  $forcedDiscards
     */
    private function deriveLossRisk(
        float $mustKeepCoverage,
        bool $touchedCriticalKind,
        array $forcedDiscards,
    ): string {
        if ($touchedCriticalKind || $mustKeepCoverage < 0.85) {
            return AtlasLongHorizonCanon::LOSS_RISK_HIGH;
        }
        if ($mustKeepCoverage < 1.0 || $forcedDiscards !== []) {
            return AtlasLongHorizonCanon::LOSS_RISK_MEDIUM;
        }

        return AtlasLongHorizonCanon::LOSS_RISK_LOW;
    }

    /**
     * @param  list<array<string,mixed>>  $retained
     * @param  list<array<string,mixed>>  $unresolvedLoss
     * @param  list<mixed>  $evidenceRefs
     * @param  list<mixed>  $staleRisks
     */
    private function composeScopeSummary(
        string $scopeType,
        ?string $scopeId,
        array $retained,
        array $unresolvedLoss,
        array $evidenceRefs,
        array $staleRisks,
    ): string {
        $parts = [];
        $parts[] = 'Scope: '.$scopeType.($scopeId !== null ? '/'.$scopeId : '');
        $parts[] = 'Retained must_keep: '.count($retained);
        $parts[] = 'Unresolved loss: '.count($unresolvedLoss);

        if ($retained !== []) {
            $digests = array_map(
                static fn (array $i): string => '['.(string) $i['kind'].':'.(string) $i['id'].'] '
                    .Str::limit((string) ($i['digest'] ?? ''), 160, '...'),
                array_slice($retained, 0, 12),
            );
            $parts[] = "Decisoes/blockers preservados:\n - ".implode("\n - ", $digests);
        }

        if ($unresolvedLoss !== []) {
            $losses = array_map(
                static fn (array $i): string => '['.(string) $i['kind'].':'.(string) $i['id'].'] reason='.(string) $i['reason']
                    .' digest='.Str::limit((string) ($i['digest'] ?? ''), 120, '...'),
                array_slice($unresolvedLoss, 0, 12),
            );
            $parts[] = "ATENCAO unresolved_loss (write bloqueado quando contem decision/blocker/dod/risk_critical):\n - "
                .implode("\n - ", $losses);
        }

        if ($staleRisks !== []) {
            $parts[] = 'Stale risks: '.count($staleRisks);
        }
        if ($evidenceRefs !== []) {
            $parts[] = 'Evidence refs: '.count($evidenceRefs);
        }

        return Str::limit(implode("\n", $parts), 12000, '...');
    }

    /**
     * @param  list<string>  $callerQueries
     * @param  list<array<string,mixed>>  $unresolvedLoss
     * @return list<string>
     */
    private function mergeRecoveryQueries(array $callerQueries, array $unresolvedLoss): array
    {
        $derived = [];
        foreach ($unresolvedLoss as $loss) {
            $kind = (string) ($loss['kind'] ?? 'item');
            $id = (string) ($loss['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $derived[] = "rehydrate {$kind}:{$id} from canonical sources";
        }

        $out = array_merge($callerQueries, $derived);
        $out = array_values(array_unique(array_filter($out, static fn ($v): bool => is_string($v) && $v !== '')));

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $unresolvedLoss
     * @param  list<mixed>  $staleRisks
     */
    private function deriveQualityScore(
        float $mustKeepCoverage,
        array $unresolvedLoss,
        array $staleRisks,
    ): float {
        $score = $mustKeepCoverage;
        if ($unresolvedLoss !== []) {
            $score -= min(0.3, 0.05 * count($unresolvedLoss));
        }
        if ($staleRisks !== []) {
            $score -= min(0.2, 0.02 * count($staleRisks));
        }

        return round(max(0.0, min(1.0, $score)), 3);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function requireString(array $input, string $key): string
    {
        if (! isset($input[$key]) || ! is_string($input[$key])) {
            throw new InvalidArgumentException("AiCompactionService::compactForScope input '{$key}' must be a string.");
        }
        $value = trim((string) $input[$key]);
        if ($value === '') {
            throw new InvalidArgumentException("AiCompactionService::compactForScope input '{$key}' must not be empty.");
        }

        return $value;
    }
}
