<?php

namespace App\Services\Ai\Kernel\Surface;

final class SurfaceCapability
{
    public const TEXT = 'text';

    public const FILES = 'files';

    public const ATTACHMENTS = 'attachments';

    public const IMAGE_PASTE = 'image_paste';

    public const IMAGE_UPLOADS = 'image_uploads';

    public const VOICE_AUDIO = 'voice_audio';

    public const WORKSPACE_CONTEXT = 'workspace_context';

    public const CONVERSATION_CONTEXT = 'conversation_context';

    public const THREAD_CONTEXT = 'thread_context';

    public const DOMAIN_FLOW_SELECTION = 'domain_flow_selection';

    public const ARTIFACT_RENDERING = 'artifact_rendering';

    public const MEMORY_RECALL = 'memory_recall';

    public const CONTEXT_COMPOSE = 'context_compose';

    public const TOOLS_RUNTIME = 'tools_runtime';

    public const HUMAN_KNOWLEDGE_WORKSPACE = 'human_knowledge_workspace';

    public const MANAGED_NOTE_PROJECTION = 'managed_note_projection';

    /**
     * @return array<int,string>
     */
    public static function all(): array
    {
        return [
            self::TEXT,
            self::FILES,
            self::ATTACHMENTS,
            self::IMAGE_PASTE,
            self::IMAGE_UPLOADS,
            self::VOICE_AUDIO,
            self::WORKSPACE_CONTEXT,
            self::CONVERSATION_CONTEXT,
            self::THREAD_CONTEXT,
            self::DOMAIN_FLOW_SELECTION,
            self::ARTIFACT_RENDERING,
            self::MEMORY_RECALL,
            self::CONTEXT_COMPOSE,
            self::TOOLS_RUNTIME,
            self::HUMAN_KNOWLEDGE_WORKSPACE,
            self::MANAGED_NOTE_PROJECTION,
        ];
    }

    public static function isKnown(string $capability): bool
    {
        return in_array($capability, self::all(), true);
    }
}
