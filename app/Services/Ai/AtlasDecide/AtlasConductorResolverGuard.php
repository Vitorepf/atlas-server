<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use Closure;

/**
 * Opt-in decorator around the conductor's per-arm resolver closure that adds two
 * Atlas-sovereign ports of the Dynamic Workflows primitives — a turn-wide token
 * ceiling and a provider-boundary structured-output gate — WITHOUT rebuilding the
 * conductor and WITHOUT changing the default path.
 *
 * Each gate runs in one of three MODES (the staged-promotion ratchet):
 *   - off:     the gate is skipped entirely.
 *   - observe: the gate is evaluated and a would-block is appended to an audit
 *              JSONL, but the arm PASSES THROUGH unchanged (shadow — measure the
 *              false-positive rate against real traffic before enforcing).
 *   - enforce: a violation fails the arm (turn_budget_exhausted / schema_unsatisfied)
 *              with a held outcome the tie-break routes around — never a throw.
 *
 * Modes come from $options (`budget_mode` / `schema_mode`) with a config fallback
 * (`atlas.ai.conductor_gates.*`), defaulting to `enforce` so an explicitly-supplied
 * schema/budget keeps its prior behaviour. With neither gate active, decorate()
 * returns the inner resolver unchanged (byte-for-byte default path).
 *
 * Returns a closure scoped to ONE dispatch: the turn budget it captures pools
 * across that dispatch's arms, so callers must re-invoke decorate() per dispatch
 * (the conductor does — one setResolver per run()) and never carry it across
 * dispatches, which would leak spend forward.
 */
final class AtlasConductorResolverGuard
{
    private const MODE_OFF = 'off';

    private const MODE_OBSERVE = 'observe';

    private const MODE_ENFORCE = 'enforce';

    private ?string $observeLogPath = null;

    public function setObserveLogPath(?string $path): void
    {
        $this->observeLogPath = $path;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function decorate(Closure $inner, array $options): Closure
    {
        $budgetUnits = (float) ($options['turn_budget_units'] ?? 0.0);
        $schema = is_array($options['output_schema'] ?? null) ? $options['output_schema'] : null;

        $budgetMode = $this->mode($options['budget_mode'] ?? $this->configMode('budget_mode'));
        $schemaMode = $this->mode($options['schema_mode'] ?? $this->configMode('schema_mode'));

        $budgetActive = $budgetUnits > 0.0 && $budgetMode !== self::MODE_OFF;
        $schemaActive = $schema !== null && $schemaMode !== self::MODE_OFF;

        if (! $budgetActive && ! $schemaActive) {
            return $inner;
        }

        $budget = $budgetActive ? new AtlasConductorTurnBudget($budgetUnits) : null;
        $validator = $schemaActive ? new AtlasStructuredOutputValidator() : null;

        return function (array $arm, array $context) use ($inner, $budget, $budgetMode, $validator, $schema, $schemaMode): array {
            if ($budget !== null) {
                $units = (float) max(1, (int) ceil(mb_strlen((string) ($context['input'] ?? '')) / 4));
                if (! $budget->tryConsume($units)) {
                    $this->observe('turn_budget', 'turn_budget_exhausted', ['units' => $units, 'spent' => $budget->spent()]);
                    if ($budgetMode === self::MODE_ENFORCE) {
                        return ['result' => 'failure', 'latency_ms' => 0, 'quality_score' => null, 'output' => 'turn_budget_exhausted'];
                    }
                    // observe: fall through and let the arm run.
                }
            }

            $outcome = $inner($arm, $context);

            if ($validator !== null && (string) ($outcome['result'] ?? '') === 'success') {
                $check = $validator->validate((string) ($outcome['output'] ?? ''), (array) $schema);
                if (! $check['valid']) {
                    $this->observe('output_schema', 'schema_unsatisfied', ['errors' => array_slice($check['errors'], 0, 5)]);
                    if ($schemaMode === self::MODE_ENFORCE) {
                        return [
                            'result' => 'failure',
                            'latency_ms' => $outcome['latency_ms'] ?? null,
                            'quality_score' => null,
                            'output' => 'schema_unsatisfied: '.implode('; ', array_slice($check['errors'], 0, 5)),
                        ];
                    }
                    // observe: return the original (would-block-but-passed) outcome.
                }
            }

            return $outcome;
        };
    }

    private function mode(mixed $value): string
    {
        $v = strtolower(trim((string) $value));

        return in_array($v, [self::MODE_OFF, self::MODE_OBSERVE, self::MODE_ENFORCE], true) ? $v : self::MODE_ENFORCE;
    }

    /**
     * Operator-set default mode for a gate, read defensively so the guard also works
     * in a pure-unit context with no booted config container (falls back to enforce).
     */
    private function configMode(string $gate): string
    {
        try {
            if (function_exists('config')) {
                $v = config('atlas.ai.conductor_gates.'.$gate);
                if (is_string($v) && $v !== '') {
                    return $v;
                }
            }
        } catch (\Throwable) {
            // no booted config (pure-unit context) -> safe default below.
        }

        return self::MODE_ENFORCE;
    }

    /**
     * @param  array<string,mixed>  $detail
     */
    private function observe(string $gate, string $reason, array $detail): void
    {
        if ($this->observeLogPath === null) {
            return;
        }

        $line = json_encode([
            'schema_version' => 'atlas.ai.conductor_gate_observation.v1',
            'gate' => $gate,
            'would_block_reason' => $reason,
            'detail' => $detail,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        @file_put_contents($this->observeLogPath, ($line === false ? '{}' : $line).PHP_EOL, FILE_APPEND);
    }
}
