<?php

namespace App\Services\Ai;

use App\Jobs\GenerateVerifiedL2HierarchicalSummaryJob;
use App\Models\AiCompaction;
use App\Models\AiMessage;
use App\Models\AiQualityAction;
use App\Models\AiQualityEvaluation;
use App\Models\AiSession;
use App\Models\AiSessionState;
use App\Models\AiThread;
use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Services\Ai\AgenticEngineeringOs\Scoring\SegmentImportanceRanker;
use App\Services\Ai\AgenticEngineeringOs\Scoring\SummaryFidelityCoverageScorer;
use App\Services\Ai\Compaction\CompactionMustKeepExtractor;
use App\Services\Ai\Compaction\CompactionLossPolicy;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AiCompactionService
{
    private const TRANSACTION_ATTEMPTS = 5;

    public function __construct(
        private readonly CompactionMustKeepExtractor $mustKeepExtractor,
        private readonly ?SegmentImportanceRanker $segmentRanker = null,
        private readonly ?SummaryFidelityCoverageScorer $retentionScorer = null,
    ) {}

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
        $summaryResult = $this->summary($thread, $compactableMessages, $state, $quality, $metadata);
        $summary = $summaryResult['summary'];
        $structured = $this->structuredState($thread, $compactableMessages, $state, $quality) + [
            'protected_skill_context' => [
                'message_count' => $protectedMessages->count(),
                'skill_names' => $protectedSkillNames,
                'policy' => 'skill_content_messages_are_not_summarized_as_conversation_turns',
            ],
        ];
        $tokenBefore = (int) $compactableMessages->sum('token_estimate');
        $tokenAfter = max(1, (int) ceil(mb_strlen($summary) / 4));
        $qualityGateStatus = $this->qualityGateStatus($structured);
        $netNegative = $tokenBefore > 0 && $tokenAfter >= $tokenBefore;
        $summaryOverwriteAllowed = $qualityGateStatus !== 'needs_review' && ! $netNegative;

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
            'quality_gate_status' => $qualityGateStatus,
            'provider' => data_get($metadata, 'provider'),
            'model' => data_get($metadata, 'model'),
            'metadata' => array_merge($metadata, [
                'created_by' => 'ai_compaction_service',
                'compression_ratio_estimate' => $tokenBefore > 0 ? round($tokenAfter / $tokenBefore, 4) : null,
                'net_negative' => $netNegative,
                'net_negative_policy' => $netNegative ? 'record_candidate_without_thread_summary_overwrite' : null,
                'lexical_duplicate_group_count' => $summaryResult['lexical_duplicate_group_count'] ?? 0,
                'lexical_duplicate_segment_count' => $summaryResult['lexical_duplicate_segment_count'] ?? 0,
                'lexical_duplicate_token_estimate' => $summaryResult['lexical_duplicate_token_estimate'] ?? 0,
                'protected_skill_message_count' => $protectedMessages->count(),
                'protected_skill_names' => $protectedSkillNames,
                'candidate_summary_hash' => hash('sha256', $summary),
                'thread_summary_overwrite_skipped' => ! $summaryOverwriteAllowed,
            ]),
        ]);

        $conversationReceipt = $this->persistConversationCompactionReceipt(
            thread: $thread,
            session: $session,
            compaction: $compaction,
            summary: $summary,
            state: $state,
            reason: $reason,
            metadata: $metadata,
            rankedDrops: $summaryResult['dropped_segments'],
        );
        if (($conversationReceipt['status'] ?? null) === 'persisted') {
            $compaction->update([
                'metadata' => array_merge($compaction->metadata ?? [], [
                    'long_horizon_compaction_receipt_id' => $conversationReceipt['compaction_receipt_id'] ?? null,
                    'long_horizon_compaction_receipt_hash' => $conversationReceipt['receipt_hash'] ?? null,
                    'long_horizon_compaction_receipt_scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_CONVERSATION,
                    'long_horizon_compaction_receipt_coverage' => $conversationReceipt['must_keep_coverage'] ?? null,
                    'long_horizon_compaction_receipt_loss_risk' => $conversationReceipt['loss_risk'] ?? null,
                    'long_horizon_compaction_receipt_write_allowed' => $conversationReceipt['write_allowed'] ?? null,
                    'context_retention_score' => $conversationReceipt['context_retention_score'] ?? null,
                ]),
            ]);
        }

        $threadUpdate = [
            'metadata' => array_merge($thread->metadata ?? [], [
                'last_compaction_id' => $compaction->id,
                'last_compaction_reason' => $compaction->reason,
                'last_compaction_at' => $compaction->created_at?->toJSON(),
                'post_compaction_hook' => $this->runPostCompactionHooks(
                    AtlasLongHorizonCanon::SCOPE_TYPE_CONVERSATION,
                    (string) $thread->id,
                    [
                        'compaction_id' => $compaction->id,
                        'reason' => $compaction->reason,
                        'session_id' => $session?->id,
                        'compaction_receipt_id' => $conversationReceipt['compaction_receipt_id'] ?? null,
                        'receipt_hash' => $conversationReceipt['receipt_hash'] ?? null,
                        'receipt_status' => $conversationReceipt['status'] ?? 'unavailable',
                    ],
                ),
                'last_compaction_receipt_id' => $conversationReceipt['compaction_receipt_id'] ?? null,
                'last_compaction_receipt_hash' => $conversationReceipt['receipt_hash'] ?? null,
            ]),
        ];
        if ($summaryOverwriteAllowed) {
            $threadUpdate['summary'] = $summary;
        }
        $thread->update($threadUpdate);
        $this->dispatchVerifiedL2HierarchicalSummary($compaction);

        return $compaction->refresh();
    }

    private function dispatchVerifiedL2HierarchicalSummary(AiCompaction $compaction): void
    {
        if (! (bool) config('atlas.compaction.l2_hierarchical_summary_generation_enabled', false)) {
            return;
        }

        GenerateVerifiedL2HierarchicalSummaryJob::dispatch((string) $compaction->id)->afterCommit();
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function persistConversationCompactionReceipt(
        AiThread $thread,
        ?AiSession $session,
        AiCompaction $compaction,
        string $summary,
        ?AiSessionState $state,
        string $reason,
        array $metadata,
        array $rankedDrops = [],
    ): array {
        if (! DatabaseTableAvailability::has('atlas_long_horizon_compaction_receipts')) {
            return [
                'status' => 'unavailable',
                'reason' => 'atlas_long_horizon_compaction_receipts_table_missing',
            ];
        }

        try {
            $mustKeepItems = $this->mustKeepExtractor->extract($state);
            [$retainedItems, $discardedItems, $unresolvedLoss] = $this->splitMustKeepItemsByVisibleSummary(
                $mustKeepItems,
                $summary,
            );
            $unresolvedLoss = $this->mergeUnresolvedLoss($unresolvedLoss, $rankedDrops);
            $discardedItems = $this->mergeUnresolvedLoss($discardedItems, $rankedDrops);

            $mustKeepCoverageStatus = $mustKeepItems === []
                ? CompactionMustKeepExtractor::COVERAGE_STATUS_VACUOUS
                : CompactionMustKeepExtractor::COVERAGE_STATUS_VERIFIED;
            $mustKeepCoverage = $mustKeepItems === []
                ? 0.0
                : round(count($retainedItems) / max(1, count($mustKeepItems)), 3);
            $retention = $this->retentionScore($mustKeepItems, $summary);

            $touchedCriticalKind = false;
            foreach ($unresolvedLoss as $loss) {
                if (in_array((string) ($loss['kind'] ?? ''), AtlasLongHorizonCanon::CRITICAL_KEEP_KINDS, true)) {
                    $touchedCriticalKind = true;
                    break;
                }
            }

            $lossPolicy = CompactionLossPolicy::classify(
                $mustKeepCoverage,
                $touchedCriticalKind,
                count($discardedItems),
            );
            $lossRisk = $mustKeepCoverageStatus === CompactionMustKeepExtractor::COVERAGE_STATUS_VACUOUS
                ? AtlasLongHorizonCanon::LOSS_RISK_MEDIUM
                : $lossPolicy['loss_risk'];
            $writeAllowed = $mustKeepCoverageStatus !== CompactionMustKeepExtractor::COVERAGE_STATUS_VACUOUS
                && $lossPolicy['write_allowed'];

            $sourceContextRefs = array_values(array_filter([
                'ai_thread:'.$thread->id,
                $session?->id !== null ? 'ai_session:'.$session->id : null,
                'ai_compaction:'.$compaction->id,
            ]));
            $evidenceRefs = array_values(array_filter([
                'ai_compaction:'.$compaction->id,
                isset($metadata['provider']) && is_scalar($metadata['provider']) ? 'provider:'.(string) $metadata['provider'] : null,
                isset($metadata['model']) && is_scalar($metadata['model']) ? 'model:'.(string) $metadata['model'] : null,
            ]));
            $summaryHash = hash('sha256', $summary);
            $payload = [
                'schema_version' => AtlasLongHorizonCanon::COMPACTION_RECEIPT_SCHEMA_VERSION,
                'uuid' => (string) Str::uuid(),
                'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_CONVERSATION,
                'scope_id' => (string) $thread->id,
                'source_context_refs' => $sourceContextRefs,
                'retained_items' => $retainedItems,
                'discarded_items' => $discardedItems,
                'discarded_reason' => $discardedItems === [] ? null : AtlasLongHorizonCanon::DISCARDED_REASON_BUDGET_PRESSURE,
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
                'recovery_queries' => $this->mergeRecoveryQueries([], $unresolvedLoss),
                'evidence_refs' => $evidenceRefs,
                'summary_hash' => $summaryHash,
                'context_retention_score' => $retention['context_retention_score'],
                'quality_score' => $this->deriveQualityScore($mustKeepCoverage, $unresolvedLoss, []),
                'detected_contradictions' => [],
                'stale_risks' => [],
            ];
            $payload['receipt_hash'] = AtlasLongHorizonCompactionReceipt::canonicalReceiptHash($payload);
            $row = AtlasLongHorizonCompactionReceipt::query()->create($payload);

            return [
                'status' => 'persisted',
                'compaction_receipt_id' => $row->id,
                'receipt_uuid' => $row->uuid,
                'receipt_hash' => $payload['receipt_hash'],
                'must_keep_coverage' => $mustKeepCoverage,
                'must_keep_coverage_status' => $mustKeepCoverageStatus,
                'loss_risk' => $lossRisk,
                'write_allowed' => $writeAllowed,
                'loss_policy_reasons' => $lossPolicy['reasons'],
                'context_retention_score' => $retention['context_retention_score'],
                'retention' => $retention,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'failed_open',
                'reason' => 'conversation_compaction_receipt_failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param  list<array{id:string,kind:string,digest:?string,payload:mixed}>  $mustKeepItems
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:list<array<string,mixed>>}
     */
    private function splitMustKeepItemsByVisibleSummary(array $mustKeepItems, string $summary): array
    {
        $retained = [];
        $discarded = [];
        $unresolvedLoss = [];

        foreach ($mustKeepItems as $item) {
            $digest = (string) ($item['digest'] ?? '');
            $visible = $digest !== '' && str_contains($summary, $digest);
            if ($visible) {
                $retained[] = [
                    'id' => $item['id'],
                    'kind' => $item['kind'],
                    'digest' => $item['digest'],
                ];

                continue;
            }

            $loss = [
                'id' => $item['id'],
                'kind' => $item['kind'],
                'digest' => $item['digest'],
                'reason' => AtlasLongHorizonCanon::DISCARDED_REASON_BUDGET_PRESSURE,
            ];
            $discarded[] = $loss;
            $unresolvedLoss[] = $loss;
        }

        return [$retained, $discarded, $unresolvedLoss];
    }

    /**
     * @param  list<array<string,mixed>>  $left
     * @param  list<array<string,mixed>>  $right
     * @return list<array<string,mixed>>
     */
    private function mergeUnresolvedLoss(array $left, array $right): array
    {
        $merged = [];
        foreach (array_merge($left, $right) as $loss) {
            if (! is_array($loss)) {
                continue;
            }
            $id = (string) ($loss['id'] ?? '');
            $kind = (string) ($loss['kind'] ?? 'fact');
            if ($id === '') {
                continue;
            }
            $merged[$kind.':'.$id] = [
                'id' => $id,
                'kind' => $kind,
                'digest' => $loss['digest'] ?? null,
                'reason' => (string) ($loss['reason'] ?? AtlasLongHorizonCanon::DISCARDED_REASON_BUDGET_PRESSURE),
            ];
        }

        return array_values($merged);
    }

    /**
     * @param  list<array{id:string,kind:string,digest:?string,payload:mixed}>  $requiredItems
     * @return array<string,mixed>
     */
    private function retentionScore(array $requiredItems, string $summary): array
    {
        return ($this->retentionScorer ?? new SummaryFidelityCoverageScorer)->score(
            array_map(
                static fn (array $item): array => [
                    'id' => $item['id'],
                    'kind' => $item['kind'],
                    'digest' => $item['digest'] ?? null,
                ],
                $requiredItems,
            ),
            $this->summaryTextForRetentionScore($summary),
        );
    }

    private function summaryTextForRetentionScore(string $summary): string
    {
        $marker = "\nATENCAO unresolved_loss";
        $pos = mb_strpos($summary, $marker);

        return $pos === false ? $summary : mb_substr($summary, 0, $pos);
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

    /**
     * @param  array<string,mixed>  $metadata
     * @return array{summary:string,dropped_segments:list<array<string,mixed>>}
     */
    private function summary(AiThread $thread, $messages, ?AiSessionState $state, array $quality, array $metadata = []): array
    {
        $parts = [];
        $parts[] = 'Thread: '.$thread->title;

        if ($state?->objective) {
            $parts[] = 'Objetivo atual: '.$state->objective;
        }

        if ($state?->current_phase) {
            $parts[] = 'Fase atual: '.$state->current_phase;
        }

        $qualityNotes = collect($quality['recent_evaluations'] ?? [])
            ->filter(fn (array $evaluation): bool => ($evaluation['status'] ?? null) !== 'passed')
            ->map(fn (array $evaluation): string => "score {$evaluation['score']} {$evaluation['status']} flags=".implode(',', $evaluation['flags'] ?? []))
            ->take(4)
            ->implode(' | ');

        if ($qualityNotes !== '') {
            $parts[] = 'Notas de qualidade a preservar: '.$qualityNotes;
        }

        $fixedTokenEstimate = max(1, (int) ceil(mb_strlen(implode("\n", $parts)) / 4));
        $requestedBudget = isset($metadata['summary_token_budget']) && is_numeric($metadata['summary_token_budget'])
            ? (int) $metadata['summary_token_budget']
            : 3000;
        $segmentBudget = max(0, $requestedBudget - $fixedTokenEstimate);

        $segments = $this->conversationSummarySegments($messages, $state);
        $duplicateStats = $this->lexicalDuplicateStats($segments);
        $this->recordSemanticDedupShadow($segments);
        $selection = ($this->segmentRanker ?? new SegmentImportanceRanker)->select($segments, $segmentBudget);
        $keptIds = array_flip((array) ($selection['kept_ids'] ?? []));
        $kept = [];
        $dropped = [];
        foreach ($segments as $segment) {
            if (isset($keptIds[$segment['id']])) {
                $kept[] = $segment;
            } else {
                $dropped[] = $this->segmentLoss($segment);
            }
        }

        $byKind = collect($kept)->groupBy('kind');
        $decisions = $byKind->get('decision', collect())->pluck('text')->filter()->values();
        if ($decisions->isNotEmpty()) {
            $parts[] = 'Decisoes preservadas: '.$decisions->implode(' | ');
        }

        $openLoops = $byKind->get('blocker', collect())->pluck('text')->filter()->values();
        if ($openLoops->isNotEmpty()) {
            $parts[] = 'Pendencias/open loops: '.$openLoops->implode(' | ');
        }

        $nextSteps = $byKind->get('dod', collect())->pluck('text')->filter()->values();
        if ($nextSteps->isNotEmpty()) {
            $parts[] = 'Proximos passos: '.$nextSteps->implode(' | ');
        }

        $recent = $byKind->get('conversation_turn', collect())
            ->map(static fn (array $segment): string => (string) $segment['text'])
            ->implode(' || ');
        if ($recent !== '') {
            $parts[] = 'Ultimos turnos relevantes: '.$recent;
        }

        return [
            'summary' => Str::limit(implode("\n", $parts), 12000, '...'),
            'dropped_segments' => $dropped,
            'lexical_duplicate_group_count' => $duplicateStats['group_count'],
            'lexical_duplicate_segment_count' => $duplicateStats['duplicate_segment_count'],
            'lexical_duplicate_token_estimate' => $duplicateStats['token_estimate'],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function conversationSummarySegments($messages, ?AiSessionState $state): array
    {
        $segments = [];
        $appendState = function (array $entries, string $kind, string $prefix) use (&$segments): void {
            $total = count($entries);
            foreach (array_values($entries) as $index => $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $text = trim((string) ($entry['text'] ?? $entry['value'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $id = isset($entry['id']) && is_string($entry['id']) && trim($entry['id']) !== ''
                    ? trim($entry['id'])
                    : "summary:{$prefix}:{$index}";
                $segments[] = [
                    'id' => $id,
                    'kind' => $kind,
                    'text' => $text,
                    'digest' => Str::limit($text, 280, '...'),
                    'recency_rank' => max(0, $total - $index - 1),
                    'token_estimate' => max(20, (int) ceil(mb_strlen($text) / 4) + 8),
                    'has_evidence_ref' => isset($entry['evidence_ref']) || isset($entry['evidence_refs']),
                    'links_decision_or_blocker' => in_array($kind, ['decision', 'blocker'], true),
                    'importance' => AiValueNormalizer::finiteFloatOrNull($entry['importance'] ?? null) ?? 0.0,
                ];
            }
        };

        $appendState(array_values($state?->decisions ?? []), 'decision', 'decision');
        $appendState(array_values($state?->open_loops ?? []), 'blocker', 'open_loop');
        $appendState(array_values($state?->next_steps ?? []), 'dod', 'next_step');

        $messageCount = $messages->count();
        $messageDupGroups = [];
        foreach ($messages->values() as $message) {
            if ($message instanceof AiMessage) {
                $group = $this->lexicalDupGroup((string) $message->content);
                if ($group !== null) {
                    $messageDupGroups[$group] = ($messageDupGroups[$group] ?? 0) + 1;
                }
            }
        }

        foreach ($messages->values() as $index => $message) {
            if (! $message instanceof AiMessage) {
                continue;
            }
            $text = "{$message->role}: ".Str::limit(trim($message->content), 320, '...');
            if (trim($text) === '') {
                continue;
            }
            $dupGroup = $this->lexicalDupGroup((string) $message->content);
            $segments[] = [
                'id' => 'turn:'.($message->id ?? $index),
                'kind' => 'conversation_turn',
                'text' => $text,
                'digest' => $text,
                'dup_group' => $dupGroup !== null && ($messageDupGroups[$dupGroup] ?? 0) > 1 ? $dupGroup : null,
                'recency_rank' => max(0, $messageCount - $index - 1),
                'token_estimate' => max(20, (int) ceil(mb_strlen($text) / 4) + 8),
                'has_evidence_ref' => false,
                'links_decision_or_blocker' => false,
                'importance' => 0.0,
            ];
        }

        return $segments;
    }

    /**
     * @param  list<array<string,mixed>>  $segments
     * @return array{group_count:int,duplicate_segment_count:int,token_estimate:int}
     */
    private function lexicalDuplicateStats(array $segments): array
    {
        $groups = [];
        foreach ($segments as $segment) {
            $group = is_string($segment['dup_group'] ?? null) ? (string) $segment['dup_group'] : null;
            if ($group === null) {
                continue;
            }
            $groups[$group] ??= ['count' => 0, 'tokens' => 0];
            $groups[$group]['count']++;
            if ($groups[$group]['count'] > 1) {
                $groups[$group]['tokens'] += (int) ($segment['token_estimate'] ?? 0);
            }
        }

        $duplicateGroups = array_filter($groups, static fn (array $row): bool => (int) $row['count'] > 1);

        return [
            'group_count' => count($duplicateGroups),
            'duplicate_segment_count' => array_sum(array_map(static fn (array $row): int => max(0, (int) $row['count'] - 1), $duplicateGroups)),
            'token_estimate' => array_sum(array_map(static fn (array $row): int => (int) $row['tokens'], $duplicateGroups)),
        ];
    }

    private function lexicalDupGroup(string $text): ?string
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/[^\pL\pN]+/u', ' ', $text)));
        $normalized = trim((string) preg_replace('/\s+/', ' ', $normalized));
        if (mb_strlen($normalized) < 12) {
            return null;
        }

        $tokens = preg_split('/\s+/', $normalized) ?: [];
        if (count($tokens) >= 5) {
            $shingles = [];
            for ($i = 0; $i <= count($tokens) - 5; $i++) {
                $shingles[] = implode(' ', array_slice($tokens, $i, 5));
            }
            $shingles = array_values(array_unique($shingles));
            sort($shingles, SORT_STRING);
            $normalized = implode('|', $shingles);
        }

        return 'lex:'.hash('sha256', $normalized);
    }

    /**
     * @param  list<array<string,mixed>>  $segments
     */
    private function recordSemanticDedupShadow(array $segments): void
    {
        if (! (bool) config('atlas.compaction.semantic_dedup_shadow_enabled', false)) {
            return;
        }

        $turns = array_values(array_filter(
            $segments,
            static fn (array $segment): bool => (string) ($segment['kind'] ?? '') === 'conversation_turn',
        ));
        if (count($turns) < 2) {
            return;
        }

        $threshold = max(0.0, min(1.0, (float) config('atlas.compaction.semantic_dedup_shadow_threshold', 0.5)));
        $path = (string) config('atlas.compaction.semantic_dedup_shadow_path', storage_path('app/atlas/compaction/semantic-dedup-shadow.jsonl'));
        foreach ($turns as $i => $left) {
            for ($j = $i + 1; $j < count($turns); $j++) {
                $right = $turns[$j];
                if (($left['dup_group'] ?? null) !== null && ($left['dup_group'] ?? null) === ($right['dup_group'] ?? null)) {
                    continue;
                }

                $similarity = $this->bigramSimilarity((string) ($left['text'] ?? ''), (string) ($right['text'] ?? ''));
                if ($similarity < $threshold) {
                    continue;
                }

                AppendOnlyJsonlStore::append($path, [
                    'schema_version' => 'atlas.compaction.semantic_dedup_shadow.v1',
                    'mode' => 'shadow_only',
                    'selection_changed' => false,
                    'left_id' => (string) ($left['id'] ?? ''),
                    'right_id' => (string) ($right['id'] ?? ''),
                    'similarity' => round($similarity, 4),
                    'threshold' => $threshold,
                    'recorded_at' => now()->toIso8601String(),
                ]);
            }
        }
    }

    private function bigramSimilarity(string $a, string $b): float
    {
        $left = $this->bigrams($a);
        $right = $this->bigrams($b);
        if ($left === [] || $right === []) {
            return 0.0;
        }

        $intersection = count(array_intersect_key($left, $right));
        $union = count($left + $right);

        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * @return array<string,true>
     */
    private function bigrams(string $text): array
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/[^\pL\pN]+/u', ' ', $text)));
        $normalized = trim((string) preg_replace('/\s+/', ' ', $normalized));
        $chars = preg_split('//u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        for ($i = 0; $i < count($chars) - 1; $i++) {
            $pair = $chars[$i].$chars[$i + 1];
            if (trim($pair) !== '') {
                $out[$pair] = true;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $segment
     * @return array<string,mixed>
     */
    private function segmentLoss(array $segment): array
    {
        return [
            'id' => (string) ($segment['id'] ?? ''),
            'kind' => (string) ($segment['kind'] ?? 'fact'),
            'digest' => $segment['digest'] ?? null,
            'reason' => AtlasLongHorizonCanon::DISCARDED_REASON_BUDGET_PRESSURE,
        ];
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

        if (DatabaseTableAvailability::has('ai_quality_evaluations')) {
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

        if (DatabaseTableAvailability::has('ai_quality_actions')) {
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

        $callerMustKeepItems = $this->normaliseMustKeepItems((array) ($input['must_keep_items'] ?? []));
        $sessionState = $this->resolveSessionStateForScope($scopeType, $scopeId);
        $extractedMustKeepItems = $this->mustKeepExtractor->extract($sessionState);
        $mustKeepItems = $this->mustKeepExtractor->mergeMustKeepItems(
            $callerMustKeepItems,
            $extractedMustKeepItems,
        );
        $mustKeepSource = $this->mustKeepExtractor->resolveMustKeepSource(
            $scopeType,
            $callerMustKeepItems,
            $extractedMustKeepItems,
        );
        $forcedDiscards = $this->normaliseForcedDiscards((array) ($input['forced_discards'] ?? []));
        $sourceContextRefs = array_values((array) ($input['source_context_refs'] ?? []));
        $evidenceRefs = array_values((array) ($input['evidence_refs'] ?? []));
        $staleRisks = array_values((array) ($input['stale_risks'] ?? []));
        $detectedContradictions = array_values((array) ($input['detected_contradictions'] ?? []));
        $callerRecoveryQueries = AiStringListNormalizer::nonBlankStrings((array) ($input['recovery_queries'] ?? []));
        $actorAlias = isset($input['actor_alias']) && is_string($input['actor_alias']) && trim($input['actor_alias']) !== ''
            ? trim($input['actor_alias'])
            : 'ai_compaction_service';

        [$retainedItems, $discardedItems, $unresolvedLoss] = $this->splitMustKeepItems(
            $mustKeepItems,
            $forcedDiscards,
        );

        $mustKeepCoverageStatus = CompactionMustKeepExtractor::COVERAGE_STATUS_VERIFIED;
        if ($mustKeepSource === CompactionMustKeepExtractor::MUST_KEEP_SOURCE_NONE) {
            $mustKeepCoverage = 0.0;
            $mustKeepCoverageStatus = CompactionMustKeepExtractor::COVERAGE_STATUS_VACUOUS;
        } else {
            $mustKeepCoverage = round(count($retainedItems) / max(1, count($mustKeepItems)), 3);
        }

        $touchedCriticalKind = false;
        foreach ($unresolvedLoss as $loss) {
            $kind = (string) ($loss['kind'] ?? '');
            if (in_array($kind, AtlasLongHorizonCanon::CRITICAL_KEEP_KINDS, true)) {
                $touchedCriticalKind = true;
                break;
            }
        }

        $lossPolicy = CompactionLossPolicy::classify(
            $mustKeepCoverage,
            $touchedCriticalKind,
            count($forcedDiscards),
        );
        $writeAllowed = $mustKeepCoverageStatus === CompactionMustKeepExtractor::COVERAGE_STATUS_VACUOUS
            ? false
            : $lossPolicy['write_allowed'];
        $lossRisk = $mustKeepCoverageStatus === CompactionMustKeepExtractor::COVERAGE_STATUS_VACUOUS
            ? AtlasLongHorizonCanon::LOSS_RISK_MEDIUM
            : $lossPolicy['loss_risk'];

        $summary = $this->composeScopeSummary(
            scopeType: $scopeType,
            scopeId: $scopeId,
            retained: $retainedItems,
            unresolvedLoss: $unresolvedLoss,
            evidenceRefs: $evidenceRefs,
            staleRisks: $staleRisks,
        );
        $summaryHash = hash('sha256', $summary);
        $retention = $this->retentionScore($mustKeepItems, $summary);

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
            'context_retention_score' => $retention['context_retention_score'],
            'quality_score' => $qualityScore,
            'detected_contradictions' => $detectedContradictions,
            'stale_risks' => $staleRisks,
        ];

        $receiptHash = AtlasLongHorizonCompactionReceipt::canonicalReceiptHash($payload);
        $payload['receipt_hash'] = $receiptHash;

        $hasReceiptTable = DatabaseTableAvailability::has('atlas_long_horizon_compaction_receipts');

        // Fail-open like persistConversationCompactionReceipt(): a missing
        // receipts table degrades to an unpersisted receipt instead of a
        // QueryException inside the gateway enqueue/handoff path.
        $row = $hasReceiptTable
            ? AtlasLongHorizonCompactionReceipt::query()->create($payload)
            : null;

        $postCompactionHook = $this->runPostCompactionHooks($scopeType, $scopeId, [
            'compaction_receipt_id' => $row?->id,
            'receipt_uuid' => $payload['uuid'],
            'write_allowed' => $writeAllowed,
        ]);

        return [
            'schema_version' => AtlasLongHorizonCanon::COMPACTION_RECEIPT_SCHEMA_VERSION,
            'status' => $hasReceiptTable ? 'persisted' : 'failed_open',
            'reason' => $hasReceiptTable ? null : 'atlas_long_horizon_compaction_receipts_table_missing',
            'persisted' => $row !== null,
            'compaction_receipt_id' => $row?->id,
            'receipt_uuid' => $payload['uuid'],
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
            'must_keep_source' => $mustKeepSource,
            'must_keep_coverage_status' => $mustKeepCoverageStatus,
            'unresolved_loss' => $unresolvedLoss,
            'loss_risk' => $lossRisk,
            'recovery_queries' => $recoveryQueries,
            'evidence_refs' => $evidenceRefs,
            'detected_contradictions' => $detectedContradictions,
            'stale_risks' => $staleRisks,
            'source_context_refs' => $sourceContextRefs,
            'context_retention_score' => $retention['context_retention_score'],
            'retention' => $retention,
            'quality_score' => $qualityScore,
            'write_allowed' => $writeAllowed,
            'actor_alias' => $actorAlias,
            'post_compaction_hook' => $postCompactionHook,
            'created_at' => Carbon::now()->toIso8601String(),
        ];
    }

    private function resolveSessionStateForScope(string $scopeType, ?string $scopeId): ?AiSessionState
    {
        if ($scopeId === null || $scopeId === '' || ! CompactionMustKeepExtractor::scopeHasLiveSessionState($scopeType)) {
            return null;
        }

        if (! DatabaseTableAvailability::has('ai_session_states')) {
            return null;
        }

        $query = AiSessionState::query()->where('active', true);

        return match ($scopeType) {
            AtlasLongHorizonCanon::SCOPE_TYPE_THREAD => $query
                ->where('thread_id', $scopeId)
                ->latest('updated_at')
                ->first(),
            AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION => $query
                ->where(function ($builder) use ($scopeId): void {
                    $builder->where('session_id', $scopeId)
                        ->orWhere('thread_id', $scopeId);
                })
                ->latest('updated_at')
                ->first(),
            AtlasLongHorizonCanon::SCOPE_TYPE_DEV_RUN,
            AtlasLongHorizonCanon::SCOPE_TYPE_DEV_WORKSTREAM => $query
                ->where(function ($builder) use ($scopeId): void {
                    $builder->where('thread_id', $scopeId)
                        ->orWhere('session_id', $scopeId);
                })
                ->latest('updated_at')
                ->first(),
            default => null,
        };
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
                    .' recovery_query=rehydrate '.(string) $i['kind'].':'.(string) $i['id'].' from canonical sources',
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

    /**
     * Obra 7 / CMP-01: emit a post-compaction marker and optionally append a
     * continuity-inject hint for Open Brain / TEOS consumers. Fail-open.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function runPostCompactionHooks(string $scopeType, ?string $scopeId, array $context = []): array
    {
        $cfg = (array) config('atlas.long_horizon.post_compaction_hooks', []);
        if (! (bool) ($cfg['enabled'] ?? false)) {
            return [
                'schema_version' => 'atlas.long_horizon.post_compaction_marker.v1',
                'status' => 'disabled',
            ];
        }

        $marker = [
            'schema_version' => 'atlas.long_horizon.post_compaction_marker.v1',
            'status' => 'emitted',
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'compacted_at' => Carbon::now()->toIso8601String(),
            'continuity_inject' => (bool) ($cfg['continuity_inject'] ?? false),
            'context' => $context,
        ];

        if (! ($marker['continuity_inject'] ?? false)) {
            return $marker;
        }

        try {
            AppendOnlyJsonlStore::append(
                (string) ($cfg['marker_receipt_path'] ?? storage_path('app/atlas/evidence/post-compaction-markers.jsonl')),
                $marker,
            );
            $marker['continuity_inject_status'] = 'appended';
        } catch (\Throwable $e) {
            $marker['continuity_inject_status'] = 'failed_open';
            $marker['continuity_inject_error'] = $e->getMessage();
        }

        return $marker;
    }
}
