<?php

namespace App\Services\Ai\ValueObjects;

use App\Models\AiThread;

class AiThreadResolution
{
    public function __construct(
        public readonly AiThread $thread,
        public readonly string $strategy,
        public readonly bool $created,
    ) {}

    public function toArray(): array
    {
        return [
            'thread_id' => $this->thread->id,
            'strategy' => $this->strategy,
            'created' => $this->created,
            'title' => $this->thread->title,
            'status' => $this->thread->status,
            'summary_present' => filled($this->thread->summary),
            'message_count' => $this->thread->message_count,
            'last_message_at' => $this->thread->last_message_at?->toJSON(),
        ];
    }
}
