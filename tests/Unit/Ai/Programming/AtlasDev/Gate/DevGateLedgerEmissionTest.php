<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Gate;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplier;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGate;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use Tests\Concerns\CreatesAtlasToolRuntimeTables;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;

/**
 * Guardian: Dev gates emit their outcomes into the kernel evidence ledger
 * (same audit trail Forge/AiWorker use), and emission stays best-effort —
 * a missing ledger table must never break a gate.
 */
final class DevGateLedgerEmissionTest extends TestCase
{
    use AtlasDevProviderFixtures;
    use CreatesAtlasToolRuntimeTables;

    public function test_verification_gate_outcome_reaches_kernel_ledger(): void
    {
        $this->createAtlasToolRuntimeTables();

        try {
            $taskContract = $this->taskContractFixture();
            (new VerificationGate(new FakeCommandRunner))->run(
                taskContract: $taskContract,
                callResult: $this->emissionCallResult(),
                scopeReceipt: $this->emissionScopeReceipt(),
                workspace: base_path(),
            );

            $event = AtlasLedgerEvent::query()
                ->where('emitter_stage', 'atlas.dev.verification_gate')
                ->latest('occurred_at')
                ->first();

            $this->assertNotNull($event, 'verification gate must emit a kernel ledger event');
            $this->assertSame('verification_gate', data_get($event->payload, 'gate'));
            $this->assertSame($taskContract->runId, data_get($event->payload, 'run_id'));
            $this->assertSame($taskContract->runId, $event->correlation_id);
        } finally {
            $this->dropAtlasToolRuntimeTables();
        }
    }

    public function test_patch_applier_outcome_reaches_kernel_ledger_with_scope(): void
    {
        $this->createAtlasToolRuntimeTables();

        try {
            $result = (new PatchApplier)->apply(
                diffResult: DiffParseResult::noPatchNeeded('no patch in output'),
                workspace: base_path(),
                scope: ['run_id' => 'run-emission-1', 'task_id' => 'task-emission-1'],
            );

            $this->assertSame('skipped', $result->status);

            $event = AtlasLedgerEvent::query()
                ->where('emitter_stage', 'atlas.dev.patch_apply')
                ->latest('occurred_at')
                ->first();

            $this->assertNotNull($event, 'patch applier must emit a kernel ledger event');
            $this->assertSame('run-emission-1', data_get($event->payload, 'run_id'));
            $this->assertSame('skipped', data_get($event->payload, 'status'));
        } finally {
            $this->dropAtlasToolRuntimeTables();
        }
    }

    public function test_emission_is_best_effort_without_ledger_table(): void
    {
        $result = (new PatchApplier)->apply(
            diffResult: DiffParseResult::noPatchNeeded('no patch in output'),
            workspace: base_path(),
            scope: ['run_id' => 'run-emission-2'],
        );

        $this->assertSame('skipped', $result->status);
    }

    private function emissionCallResult(): \App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult
    {
        return \App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult::fromStdout(
            runId: 'run-x', actualProvider: 'claude_cli', actualModelFamily: 'sonnet',
            exitStatus: 0, stdout: 'ok', stderr: '', durationMs: 100,
        );
    }

    private function emissionScopeReceipt(): \App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt
    {
        return (new \App\Services\Ai\Programming\AtlasDev\Gate\ScopeGuard)->check(
            envelope: $this->envelopeFixture(),
            taskContract: $this->taskContractFixture(),
            diffResult: DiffParseResult::noPatchNeeded('no patch in output'),
        );
    }
}
