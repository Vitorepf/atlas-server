<?php

namespace App\Services\Ai\Policy;

use App\Models\AiBudgetEnvelope;
use Illuminate\Support\Str;
use InvalidArgumentException;

class BudgetEnvelopeService
{
    public const STATUS_OPEN = 'open';

    public const STATUS_EXHAUSTED = 'exhausted';

    public const STATUS_CLOSED = 'closed';

    /**
     * @param  array<string,mixed>  $limits
     * @param  array<string,mixed>  $context
     */
    public function open(string $scopeType, ?string $scopeRef, array $limits, array $context = []): AiBudgetEnvelope
    {
        if (! in_array($scopeType, PolicyCanon::SCOPES, true)) {
            throw new InvalidArgumentException("Unsupported scope_type [{$scopeType}]");
        }

        return AiBudgetEnvelope::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $context['mission_id'] ?? null,
            'work_order_id' => $context['work_order_id'] ?? null,
            'scope_type' => $scopeType,
            'scope_ref' => $scopeRef,
            'max_cost' => $limits['max_cost'] ?? null,
            'max_tokens' => $limits['max_tokens'] ?? null,
            'max_runtime_seconds' => $limits['max_runtime_seconds'] ?? null,
            'max_tool_calls' => $limits['max_tool_calls'] ?? null,
            'max_external_calls' => $limits['max_external_calls'] ?? null,
            'current_cost' => 0,
            'current_tokens' => 0,
            'current_runtime_seconds' => 0,
            'current_tool_calls' => 0,
            'status' => self::STATUS_OPEN,
        ]);
    }

    /**
     * Check whether applying the requested delta would exceed any configured limit.
     * Returns an array of reasons; empty array means the request fits.
     *
     * @param  array<string,mixed>  $delta
     * @return array<int,string>
     */
    public function check(AiBudgetEnvelope $envelope, array $delta): array
    {
        $reasons = [];
        $envelope->refresh();
        if ($envelope->status !== self::STATUS_OPEN) {
            $reasons[] = "budget envelope status [{$envelope->status}] not open";
        }
        foreach ([
            'cost' => 'max_cost',
            'tokens' => 'max_tokens',
            'runtime_seconds' => 'max_runtime_seconds',
            'tool_calls' => 'max_tool_calls',
        ] as $key => $maxField) {
            $max = $envelope->{$maxField};
            if ($max === null) {
                continue;
            }
            $current = (float) ($envelope->{'current_'.$key} ?? 0);
            $request = (float) ($delta[$key] ?? 0);
            if ($current + $request > (float) $max) {
                $reasons[] = "budget_exceeded:{$key} request {$request} + current {$current} > max {$max}";
            }
        }

        return $reasons;
    }

    /**
     * Consume budget; throws if it would exceed limits. Caller should run check()
     * first if it needs a non-throwing branch.
     *
     * @param  array<string,mixed>  $delta
     */
    public function consume(AiBudgetEnvelope $envelope, array $delta): AiBudgetEnvelope
    {
        $reasons = $this->check($envelope, $delta);
        if ($reasons !== []) {
            throw new InvalidArgumentException('budget_exceeded: '.implode(' | ', $reasons));
        }
        $envelope->current_cost = (float) $envelope->current_cost + (float) ($delta['cost'] ?? 0);
        $envelope->current_tokens = (int) $envelope->current_tokens + (int) ($delta['tokens'] ?? 0);
        $envelope->current_runtime_seconds = (int) $envelope->current_runtime_seconds + (int) ($delta['runtime_seconds'] ?? 0);
        $envelope->current_tool_calls = (int) $envelope->current_tool_calls + (int) ($delta['tool_calls'] ?? 0);
        if ($this->isExhausted($envelope)) {
            $envelope->status = self::STATUS_EXHAUSTED;
        }
        $envelope->save();

        return $envelope;
    }

    public function close(AiBudgetEnvelope $envelope): AiBudgetEnvelope
    {
        $envelope->status = self::STATUS_CLOSED;
        $envelope->save();

        return $envelope;
    }

    private function isExhausted(AiBudgetEnvelope $envelope): bool
    {
        foreach ([
            'cost' => 'max_cost',
            'tokens' => 'max_tokens',
            'runtime_seconds' => 'max_runtime_seconds',
            'tool_calls' => 'max_tool_calls',
        ] as $key => $maxField) {
            $max = $envelope->{$maxField};
            if ($max === null) {
                continue;
            }
            if ((float) ($envelope->{'current_'.$key} ?? 0) >= (float) $max) {
                return true;
            }
        }

        return false;
    }
}
