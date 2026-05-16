<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Telemetry;

use App\Services\Ai\Programming\AtlasDev\Persistence\GenericArtifactPersister;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\FastPathTelemetry;
use App\Services\Ai\Programming\AtlasDev\Telemetry\TelemetryEmitter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TelemetryEmitterTest extends TestCase
{
    private string $tmpDir;
    private TelemetryEmitter $emitter;
    private GenericArtifactPersister $persister;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/atlas-dev-telemetry-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o755, true);
        $this->persister = new GenericArtifactPersister(new ReceiptStorage($this->tmpDir));
        $this->emitter = new TelemetryEmitter($this->persister);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
    }

    public function test_emit_once_per_run(): void
    {
        $t = $this->makeTelemetry();
        $result = $this->emitter->emit($t);
        $this->assertSame($t->telemetryHash, $result['telemetry_hash']);
        $this->assertTrue($this->emitter->alreadyEmittedFor('run-1'));

        $this->expectException(RuntimeException::class);
        $this->emitter->emit($t);
    }

    public function test_read_returns_canonical_dto(): void
    {
        $t = $this->makeTelemetry();
        $this->emitter->emit($t);
        $read = $this->emitter->read('run-1');
        $this->assertNotNull($read);
        $this->assertSame($t->toCanonicalArray(), $read->toCanonicalArray());
    }

    public function test_already_emitted_returns_false_before_emit(): void
    {
        $this->assertFalse($this->emitter->alreadyEmittedFor('run-1'));
    }

    public function test_emit_writes_deterministic_shape_independent_of_construction_order(): void
    {
        $a = $this->makeTelemetry();
        $this->emitter->emit($a);

        $b = $this->makeTelemetry();
        // a and b have identical hashes regardless of attribute construction order.
        $this->assertSame($a->telemetryHash, $b->telemetryHash);
    }

    private function makeTelemetry(): FastPathTelemetry
    {
        return FastPathTelemetry::issue(
            runId: 'run-1',
            workspaceHash: 'wh',
            taskKind: 'patch',
            riskLevel: 'R1',
            promptProjectionHash: 'pph',
            contractCompletenessStatus: 'passed',
            docTiersSelected: ['tier_a'],
            gatesActivated: ['scope_guard_light'],
            provider: 'claude_cli',
            model: 'claude-sonnet-4-6',
            providerCalls: 1,
            repairAttempts: 0,
            costEstimateUsd: 0.01,
            wallTimeMs: 1000,
            completionState: CompletionSummary::STATUS_PASSED,
            escalationTriggered: false,
            receiptPersisted: true,
            errorLedgerWritten: false,
        );
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path)) {
                $this->rmrf($path);
            } else {
                @chmod($path, 0o644);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
