<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\PauseResume;

/**
 * FACT-only Loop cycle resume: reads a pause sentinel + checkpoint facts, verifies integrity,
 * and emits either {resumed_phase, cycle_id, checkpoint_hash} or {refused, reason}. Never
 * mutates unrelated state — only the pause sentinel (lowered on successful resume).
 */
final class AtlasLoopCycleResumeFromCheckpoint
{
    public const SCHEMA = 'atlas.loop.cycle_resume.v1';

    public function __construct(private readonly AtlasLoopCyclePauseFlag $pauseFlag) {}

    /**
     * @param  array<string,mixed>  $checkpoint
     * @return array<string,mixed>
     */
    public function resume(array $checkpoint): array
    {
        $sentinel = $this->pauseFlag->inspect();
        if ($sentinel === null) {
            return $this->refused('missing_pause_sentinel', $checkpoint);
        }

        if (! isset($checkpoint['cycle_id'], $checkpoint['phase'], $checkpoint['checkpoint_hash'], $checkpoint['facts'])
            || ! is_string($checkpoint['cycle_id'])
            || ! is_string($checkpoint['phase'])
            || ! is_string($checkpoint['checkpoint_hash'])
            || ! is_array($checkpoint['facts'])) {
            return $this->refused('missing_checkpoint_fields', $checkpoint);
        }

        if ((string) $sentinel['cycle_id'] !== (string) $checkpoint['cycle_id']) {
            return $this->refused('cycle_id_mismatch', $checkpoint, [
                'sentinel_cycle_id' => (string) $sentinel['cycle_id'],
                'checkpoint_cycle_id' => (string) $checkpoint['cycle_id'],
            ]);
        }

        $expected = $this->hashFacts((array) $checkpoint['facts']);
        if (! hash_equals($expected, (string) $checkpoint['checkpoint_hash'])) {
            return $this->refused('integrity_drift', $checkpoint, [
                'expected_hash' => $expected,
                'received_hash' => (string) $checkpoint['checkpoint_hash'],
            ]);
        }

        $this->pauseFlag->lower();

        return [
            'schema_version' => self::SCHEMA,
            'outcome' => 'resumed',
            'cycle_id' => (string) $checkpoint['cycle_id'],
            'resumed_phase' => (string) $checkpoint['phase'],
            'checkpoint_hash' => (string) $checkpoint['checkpoint_hash'],
            'sentinel_hash_at_resume' => (string) $sentinel['sentinel_hash'],
        ];
    }

    /**
     * @param  array<string,mixed>  $facts
     */
    public function hashFacts(array $facts): string
    {
        return hash('sha256', (string) json_encode($this->sortRecursive($facts), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>  $checkpoint
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function refused(string $reason, array $checkpoint, array $extra = []): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'outcome' => 'refused',
            'reason' => $reason,
            'cycle_id' => is_string($checkpoint['cycle_id'] ?? null) ? (string) $checkpoint['cycle_id'] : '',
            'facts' => $extra,
        ];
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($v): mixed => $this->sortRecursive($v), $value);
        }
        ksort($value, SORT_STRING);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->sortRecursive($v);
        }

        return $out;
    }
}
