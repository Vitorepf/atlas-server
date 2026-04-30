<?php

namespace App\Services\Ai;

use App\Models\AiCompaction;
use App\Models\AiContextSnapshot;
use App\Models\AiProviderHandoff;
use App\Models\AiSession;
use App\Models\AiTrace;

class AiContextSnapshotRecorder
{
    public function record(AiTrace $trace, AiSession $session, AiPrompt $prompt, ?AiCompaction $compaction = null, ?AiProviderHandoff $handoff = null): AiContextSnapshot
    {
        $contextPack = $prompt->contextPack;
        $messages = data_get($contextPack, 'conversation.recent_turns', []);

        return AiContextSnapshot::query()->create([
            'trace_id' => $trace->id,
            'thread_id' => $trace->thread_id,
            'session_id' => $session->id,
            'provider' => $trace->provider,
            'model' => $trace->model,
            'prompt_hash' => $trace->prompt_hash,
            'context_pack' => $contextPack,
            'messages_included' => is_array($messages) ? $messages : [],
            'compaction_id' => $compaction?->id ?: data_get($contextPack, 'continuity.latest_compaction.id'),
            'provider_handoff_id' => $handoff?->id ?: data_get($contextPack, 'continuity.latest_provider_handoff.id'),
            'token_estimate' => max(1, (int) ceil(mb_strlen($prompt->prompt) / 4)),
            'metadata' => [
                'agent_slug' => $trace->agent_slug,
                'intent' => $trace->intent,
                'context_window' => data_get($contextPack, 'conversation.context_window'),
                'created_by' => 'ai_context_snapshot_recorder',
            ],
        ]);
    }
}
