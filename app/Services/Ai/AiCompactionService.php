<?php

namespace App\Services\Ai;

use App\Models\AiCompaction;
use App\Models\AiMessage;
use App\Models\AiQualityAction;
use App\Models\AiQualityEvaluation;
use App\Models\AiSession;
use App\Models\AiSessionState;
use App\Models\AiThread;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
}
