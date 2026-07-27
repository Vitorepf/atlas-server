<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\Debugger;

final class DebuggerReceiptEntry
{
    public const EVENTS = [
        'pause_armed',
        'pause_hit',
        'inspect_pre',
        'inspect_post',
        'step_advanced',
        'resumed',
        'aborted',
        'timed_out',
    ];

    public const FORBIDDEN_KEYS = ['prompt', 'llm_output', 'provider', 'score', 'grade', 'judgement', 'judge'];

    /** @param array<string,mixed> $extra */
    public function __construct(
        public readonly string $tsIso8601,
        public readonly string $runId,
        public readonly int $stepIndex,
        public readonly string $event,
        public readonly string $prevEntrySha256,
        public readonly array $extra = [],
    ) {
        if (! in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException('debugger_receipt_unknown_event:'.$event);
        }
        foreach (array_keys($extra) as $k) {
            if (in_array($k, self::FORBIDDEN_KEYS, true)) {
                throw new \InvalidArgumentException('debugger_receipt_forbidden_key:'.$k);
            }
        }
    }

    /** @return array<string,mixed> */
    public function toCanonicalArray(): array
    {
        $base = [
            'event' => $this->event,
            'prev_entry_sha256' => $this->prevEntrySha256,
            'run_id' => $this->runId,
            'step_index' => $this->stepIndex,
            'ts_iso8601' => $this->tsIso8601,
        ];
        $merged = array_merge($base, $this->extra);
        ksort($merged);

        return $merged;
    }

    public function canonicalBytes(): string
    {
        return (string) json_encode($this->toCanonicalArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
