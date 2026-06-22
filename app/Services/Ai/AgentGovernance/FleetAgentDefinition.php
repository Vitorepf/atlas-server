<?php

declare(strict_types=1);

namespace App\Services\Ai\AgentGovernance;

/**
 * One entry in the fleet catalog — a STATIC description of an autonomous provider-consumer the operator
 * can turn on/off. Data only (no runtime). Liveness/start/stop live behind {@see FleetDriver}; the
 * operator-declared on/off lives in {@see AtlasAgentDesiredStateStore}.
 */
final readonly class FleetAgentDefinition
{
    public const KIND_LOOP = 'loop';
    public const KIND_AI_WORKER = 'ai-worker';
    public const KIND_FINANCE = 'finance';
    public const KIND_HOST_AGENT = 'host-agent';

    public function __construct(
        /** Stable key — used in desired-state, events, commands, API (e.g. 'loop', 'ai-worker.codex'). */
        public string $key,
        /** Human label for the apps. */
        public string $label,
        /** Which provider account this agent draws down when it runs (for the "🔴 gastando [conta]" badge). */
        public string $account,
        /** One of the KIND_* constants. */
        public string $kind,
        /** True when running it spends a paid/limited provider account (the thing the operator must see). */
        public bool $providerSpending,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'account' => $this->account,
            'kind' => $this->kind,
            'provider_spending' => $this->providerSpending,
        ];
    }
}
