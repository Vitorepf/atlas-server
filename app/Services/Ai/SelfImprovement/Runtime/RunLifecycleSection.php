<?php

namespace App\Services\Ai\SelfImprovement\Runtime;

use App\Models\AtlasInitiativeRun;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Initiative-run lifecycle + ledger window family extracted VERBATIM from AtlasSelfImprovementRuntime
 * (GOD-DEBULK partial split). Scanner-pinned families remain on the facade;
 * the facade keeps same-signature delegators for every method here.
 */
class RunLifecycleSection
{
    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @return Collection<int,AtlasLedgerEvent>
     */
    public function ledgerEvents(int $hours): Collection
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return new Collection;
        }

        return AtlasLedgerEvent::query()
            ->where('occurred_at', '>=', now()->subHours($hours))
            ->orderBy('occurred_at')
            ->get();
    }

    public function startRun(string $flow, bool $emit, int $hours, int $limit): ?AtlasInitiativeRun
    {
        if (! Schema::hasTable('atlas_initiative_runs')) {
            return null;
        }

        return AtlasInitiativeRun::query()->create([
            'kind' => str_replace('.', '_', $flow),
            'status' => 'running',
            'started_at' => now(),
            'scope' => [
                'hours' => $hours,
                'emit' => $emit,
                'limit' => $limit,
                'flow' => $flow,
                'source' => 'atlas_ledger_events',
            ],
            'findings' => [],
            'emitted_inbox_item_ids' => [],
            'metadata' => ['runtime' => 'atlas_self_improvement_runtime_v1'],
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $findings
     * @param  array<int,string>  $emitted
     */
    public function finishRun(?AtlasInitiativeRun $run, string $status, array $findings, array $emitted, ?string $error = null): void
    {
        if (! $run) {
            return;
        }

        $run->update([
            'status' => $status,
            'finished_at' => now(),
            'findings' => $findings,
            'emitted_inbox_item_ids' => $emitted,
            'error_message' => $error,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function recordCycleEvent(LedgerEventType $type, string $envelopeId, ?AtlasInitiativeRun $run, array $payload = []): void
    {
        $this->ledger->record($type, array_merge([
            'envelope_id' => $envelopeId,
            'self_improvement_run_id' => $run?->id,
            'flow' => (string) ($payload['flow'] ?? 'self_improvement.nightly_review'),
        ], $payload), [
            'tenant_id' => 'default',
            'operator_id' => 'atlas_self_improvement',
            'envelope_id' => $envelopeId,
            'correlation_id' => $envelopeId,
            'emitter_stage' => 'atlas.self_improvement',
            'emitter_version' => 'self-improvement-runtime-v1',
        ]);
    }
}
