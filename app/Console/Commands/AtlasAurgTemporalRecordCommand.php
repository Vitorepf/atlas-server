<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;

class AtlasAurgTemporalRecordCommand extends AtlasMutativeCommand
{
    protected $signature = 'atlas:aurg:temporal:record
        {--kind=rationale_event : tick kind}
        {--actor=operator : actor label}
        {--snapshot-hash= : sha256 of an AURG 3D snapshot (optional)}
        {--rationale= : free-text rationale}
        {--mode=plan : plan|dry-run|apply}
        {--check= : Required check code}
        {--confirm : Required flag in apply mode}
        {--json : JSON envelope}';

    protected $description = 'AURG · record a temporal tick (append-only, Doctor 3-Tier).';

    private AtlasUnifiedRealityGraphTemporalService $svc;

    public function handle(AtlasUnifiedRealityGraphTemporalService $svc): int
    {
        $this->svc = $svc;

        return $this->handleMutative();
    }

    protected function mutativeName(): string
    {
        return 'atlas:aurg:temporal:record';
    }

    protected function availableCheckCodes(): array
    {
        return ['aurg-temporal'];
    }

    protected function buildContext(): array
    {
        return [
            'kind' => (string) ($this->option('kind') ?? AtlasUnifiedRealityGraphTemporalService::KIND_RATIONALE_EVENT),
            'actor' => (string) ($this->option('actor') ?? 'operator'),
            'snapshot_hash' => $this->option('snapshot-hash'),
            'rationale' => (string) ($this->option('rationale') ?? ''),
        ];
    }

    protected function planActions(array $context): array
    {
        return [
            'would_record' => [
                'kind' => $context['kind'],
                'actor' => $context['actor'],
                'snapshot_hash' => $context['snapshot_hash'],
            ],
        ];
    }

    protected function dryRunActions(array $context): array
    {
        return $this->planActions($context);
    }

    protected function applyActions(array $context): array
    {
        $tick = $this->svc->recordTick([
            'kind' => $context['kind'],
            'actor' => $context['actor'],
            'snapshot_hash' => $context['snapshot_hash'],
            'rationale' => $context['rationale'],
        ]);

        return ['tick' => $tick];
    }
}
