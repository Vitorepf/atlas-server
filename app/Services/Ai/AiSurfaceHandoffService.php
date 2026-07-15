<?php

namespace App\Services\Ai;

use App\Models\AiSession;
use App\Models\AiSurfaceHandoff;
use App\Models\AiThread;

final class AiSurfaceHandoffService
{
    public const SCHEMA_VERSION = 'atlas.ai.surface_handoff.v1';

    /**
     * A surface handoff is intentionally not a provider handoff. It records
     * only the stable identities needed to open the same canonical work; no
     * prompt, summary, provider brief, or free-form metadata crosses surfaces.
     */
    public function record(AiThread $thread, AiSession $session, string $toSurface): AiSurfaceHandoff
    {
        return AiSurfaceHandoff::query()->create([
            'thread_id' => $thread->id,
            'session_id' => $session->id,
            'from_surface' => $thread->surface,
            'to_surface' => $toSurface,
            'status' => 'ready',
        ]);
    }

    /**
     * @return array{schema_version:string,handoff_id:string,thread_id:string,session_id:string,from_surface:string,to_surface:string,status:string,created_at:?string}
     */
    public function publicReceipt(AiSurfaceHandoff $handoff): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'handoff_id' => $handoff->id,
            'thread_id' => $handoff->thread_id,
            'session_id' => $handoff->session_id,
            'from_surface' => $handoff->from_surface,
            'to_surface' => $handoff->to_surface,
            'status' => $handoff->status,
            'created_at' => $handoff->created_at?->toJSON(),
        ];
    }
}
