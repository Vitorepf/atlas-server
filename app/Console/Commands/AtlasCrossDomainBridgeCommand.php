<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;

class AtlasCrossDomainBridgeCommand extends AtlasMutativeCommand
{
    protected $signature = 'atlas:cross-domain:bridge
        {--from= : Source domain}
        {--to= : Target domain}
        {--privacy-class=normal : public|normal|sensitive|secret|cyber}
        {--memory-ref=* : memory uuids (repeatable)}
        {--snapshot-hash= : Optional 3D AURG snapshot hash}
        {--rationale= : Free-text rationale}
        {--actor=operator : Actor label}
        {--mode=plan : plan|dry-run|apply}
        {--check= : Required check code}
        {--confirm : Required flag in apply mode}
        {--json : JSON envelope}';

    protected $description = 'Atlas Cross-Domain · request a bridge through ARPTL (Doctor 3-Tier).';

    private AtlasCrossDomainMeshService $svc;

    public function handle(AtlasCrossDomainMeshService $svc): int
    {
        $this->svc = $svc;

        return $this->handleMutative();
    }

    protected function mutativeName(): string
    {
        return 'atlas:cross-domain:bridge';
    }

    protected function availableCheckCodes(): array
    {
        return ['cross-domain-bridge'];
    }

    protected function buildContext(): array
    {
        return [
            'from_domain' => (string) ($this->option('from') ?? ''),
            'to_domain' => (string) ($this->option('to') ?? ''),
            'privacy_class' => (string) ($this->option('privacy-class') ?? 'normal'),
            'memory_refs' => (array) ($this->option('memory-ref') ?? []),
            'snapshot_hash' => $this->option('snapshot-hash'),
            'rationale' => (string) ($this->option('rationale') ?? ''),
            'actor' => (string) ($this->option('actor') ?? 'operator'),
        ];
    }

    protected function planActions(array $context): array
    {
        $dry = $this->svc->evaluate($context['from_domain'], $context['to_domain'], $context['privacy_class']);

        return [
            'would_request' => [
                'from' => $context['from_domain'],
                'to' => $context['to_domain'],
                'privacy_class' => $context['privacy_class'],
            ],
            'arptl_dry_decision' => $dry,
        ];
    }

    protected function dryRunActions(array $context): array
    {
        return $this->planActions($context);
    }

    protected function applyActions(array $context): array
    {
        $decision = $this->svc->bridge($context);

        return ['decision' => $decision];
    }
}
