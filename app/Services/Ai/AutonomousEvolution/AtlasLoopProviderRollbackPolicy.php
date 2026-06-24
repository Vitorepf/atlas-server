<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Provider rollback policy for the loop routing layer.
 *
 * The policy is deliberately provider-free: it only converts deterministic runtime signals into a routing
 * intent. Provider execution remains owned by the router/next cycle.
 */
final class AtlasLoopProviderRollbackPolicy
{
    public const POLICY_VERSION = 'atlas.loop.provider_rollback_policy.v1';

    public const KEEP_CURRENT_PROVIDER = 'keep_current_provider';

    public const ROLLBACK_TO_PREVIOUS_STABLE = 'rollback_to_previous_stable';

    public const ESCALATE_TO_HARD_PROVIDER = 'escalate_to_hard_provider';

    public const DEFAULT_IMPLEMENTATION_PROVIDER = 'minimax_m3';

    public const DEFAULT_IMPLEMENTATION_MODEL = 'MiniMax-M3';

    public const HARD_PROVIDER = 'codex';

    public const HARD_MODEL = 'gpt-5.5';

    /** @var null|callable():string */
    private $clock;

    /**
     * @param  null|callable():string  $clock
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock;
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    public function decisionFor(array $signals): string
    {
        $normalized = $this->normalizeSignals($signals);
        $rollbackSignal = $this->circuitOpen($normalized) || $this->triangulatorDissent($normalized);

        if (! $rollbackSignal) {
            return self::KEEP_CURRENT_PROVIDER;
        }

        if ($this->isArchitectPhase($normalized)) {
            return self::ESCALATE_TO_HARD_PROVIDER;
        }

        return self::ROLLBACK_TO_PREVIOUS_STABLE;
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array{
     *     policy_version:string,
     *     input_signals_hash:string,
     *     decision:string,
     *     timestamp:string,
     *     routing_intent:array{provider:string,model:?string,tier:string,last_stable_provider:string},
     *     receipt_hash:string
     * }
     */
    public function decide(array $signals, ?AtlasLoopAttemptLedger $ledger = null): array
    {
        $normalized = $this->normalizeSignals($signals);
        $decision = $this->decisionFor($normalized);
        $receipt = [
            'policy_version' => self::POLICY_VERSION,
            'input_signals_hash' => $this->stableHash($normalized),
            'decision' => $decision,
            'timestamp' => $this->timestamp(),
            'routing_intent' => $this->routingIntent($decision, $normalized),
        ];
        $receipt['receipt_hash'] = $this->stableHash($receipt);

        if ($ledger !== null) {
            $ledger->record(
                'provider_rollback_policy',
                (string) ($receipt['routing_intent']['provider'] ?? ''),
                true,
                $decision,
                json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            );
        }

        return $receipt;
    }

    /**
     * @return list<string>
     */
    public function decisions(): array
    {
        return [
            self::KEEP_CURRENT_PROVIDER,
            self::ROLLBACK_TO_PREVIOUS_STABLE,
            self::ESCALATE_TO_HARD_PROVIDER,
        ];
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array<string,mixed>
     */
    private function normalizeSignals(array $signals): array
    {
        $normalized = [];
        foreach ($signals as $key => $value) {
            $key = strtolower(trim((string) $key));
            if ($key === '') {
                continue;
            }
            $normalized[$key] = $this->normalizeValue($value);
        }

        ksort($normalized);

        return $normalized;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeValue($item);
            }
            if (! array_is_list($normalized)) {
                ksort($normalized);
            }

            return $normalized;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return trim((string) $value);
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function circuitOpen(array $signals): bool
    {
        $state = strtolower(trim((string) ($signals['circuit_state'] ?? $signals['provider_circuit_state'] ?? '')));

        return in_array($state, ['open', 'tripped', 'provider_down'], true)
            || ($signals['circuit_open'] ?? false) === true
            || ($signals['circuit_tripped'] ?? false) === true;
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function triangulatorDissent(array $signals): bool
    {
        $verdict = strtolower(trim((string) ($signals['triangulator_verdict'] ?? $signals['verdict'] ?? '')));
        if (in_array($verdict, ['dissent', 'split', 'provider_capability_gap'], true)) {
            return true;
        }

        $receipt = is_array($signals['triangulator_receipt'] ?? null) ? $signals['triangulator_receipt'] : [];
        $receiptVerdict = strtolower(trim((string) ($receipt['verdict'] ?? '')));

        return in_array($receiptVerdict, ['dissent', 'split', 'provider_capability_gap'], true);
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function isArchitectPhase(array $signals): bool
    {
        if (($signals['architect_phase_gate'] ?? false) === true) {
            return true;
        }

        $anchor = strtolower(AtlasLoopArchitectPhaseGate::class);
        foreach (['phase', 'work_type', 'objective_kind', 'phase_anchor'] as $key) {
            $value = strtolower(trim((string) ($signals[$key] ?? '')));
            if (in_array($value, ['architect', 'architect_phase', 'architecture', 'design'], true) || $value === $anchor) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array{provider:string,model:?string,tier:string,last_stable_provider:string}
     */
    private function routingIntent(string $decision, array $signals): array
    {
        $lastStableProvider = $this->provider($signals['last_stable_provider'] ?? null, self::DEFAULT_IMPLEMENTATION_PROVIDER);

        if ($decision === self::ESCALATE_TO_HARD_PROVIDER) {
            return [
                'provider' => self::HARD_PROVIDER,
                'model' => self::HARD_MODEL,
                'tier' => 'hard',
                'last_stable_provider' => $lastStableProvider,
            ];
        }

        if ($decision === self::ROLLBACK_TO_PREVIOUS_STABLE) {
            return [
                'provider' => $lastStableProvider,
                'model' => $this->model($signals['last_stable_model'] ?? null),
                'tier' => 'stable',
                'last_stable_provider' => $lastStableProvider,
            ];
        }

        return [
            'provider' => $this->provider($signals['current_provider'] ?? null, self::DEFAULT_IMPLEMENTATION_PROVIDER),
            'model' => $this->model($signals['current_model'] ?? null),
            'tier' => 'current',
            'last_stable_provider' => $lastStableProvider,
        ];
    }

    private function provider(mixed $provider, string $fallback): string
    {
        $provider = strtolower(trim((string) $provider));

        return $provider !== '' ? $provider : $fallback;
    }

    private function model(mixed $model): ?string
    {
        $model = trim((string) $model);

        return $model !== '' ? $model : null;
    }

    private function timestamp(): string
    {
        if (is_callable($this->clock)) {
            return (string) ($this->clock)();
        }

        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        $payload = $this->sortKeys($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sortKeys(array $payload): array
    {
        ksort($payload);
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sortKeys($value);
            }
        }

        return $payload;
    }
}
