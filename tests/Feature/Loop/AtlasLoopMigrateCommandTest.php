<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Migration\AtlasLoopSchemaMigrationReceipt;
use App\Services\Ai\AutonomousEvolution\Migration\AtlasLoopSchemaMigrationReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Migration\AtlasLoopSchemaMigrationRegistry;
use App\Services\Ai\AutonomousEvolution\Migration\AtlasLoopSchemaMigrationRunner;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopMigrateCommandTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envPath = sys_get_temp_dir().'/atlas-loop-master-'.bin2hex(random_bytes(6)).'.env';
        AtlasLoopMasterSwitch::$envPathOverride = $this->envPath;
        file_put_contents($this->envPath, "ATLAS_LOOP_MASTER_ENABLED=true\n");
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->envPath);
        parent::tearDown();
    }

    public function test_inspect_outputs_json_shape_and_never_calls_runner(): void
    {
        $registry = new AtlasLoopSchemaMigrationRegistry;
        $registry->register('atlas_loop_evidence_ledger', 1, 2, static fn (string $bytes): string => $bytes, static fn (): bool => true);
        $runner = new FakeAtlasLoopMigrationRunner;

        app()->instance(AtlasLoopSchemaMigrationRegistry::class, $registry);
        app()->instance(AtlasLoopSchemaMigrationRunner::class, $runner);

        $exit = Artisan::call('atlas:loop:migrate', [
            'action' => 'inspect',
            '--artifact' => 'atlas_loop_evidence_ledger',
            '--json' => true,
        ]);

        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $payload['payload']['current_version']);
        $this->assertSame(2, $payload['payload']['target_version']);
        $this->assertSame([['artifact_kind' => 'atlas_loop_evidence_ledger', 'from_version' => 1, 'to_version' => 2, 'reversible' => false]], $payload['payload']['chain']);
        $this->assertIsString($payload['payload']['approval_handshake']);
        $this->assertSame(0, $runner->runCalls);
        $this->assertSame(0, $runner->approvalCalls);
    }

    public function test_run_without_token_fails_and_with_valid_token_calls_runner_once(): void
    {
        $runner = new FakeAtlasLoopMigrationRunner;
        app()->instance(AtlasLoopSchemaMigrationRunner::class, $runner);

        $withoutToken = Artisan::call('atlas:loop:migrate', [
            'action' => 'run',
            '--artifact' => 'atlas_loop_workspace_blueprints',
            '--from' => 'v1',
            '--to' => 'v2',
            '--json' => true,
        ]);
        $withoutPayload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $withoutToken);
        $this->assertStringContainsString('--approval-token=', $withoutPayload['payload']['instructions']);
        $this->assertSame(0, $runner->runCalls);

        $token = $this->handshake('atlas_loop_workspace_blueprints', 1, 2);
        $withToken = Artisan::call('atlas:loop:migrate', [
            'action' => 'run',
            '--artifact' => 'atlas_loop_workspace_blueprints',
            '--from' => 'v1',
            '--to' => 'v2',
            '--approval-token' => $token,
            '--json' => true,
        ]);
        $withPayload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $withToken);
        $this->assertSame(1, $runner->runCalls);
        $this->assertSame('runner-ok', $withPayload['payload']['result']);
    }

    public function test_history_reports_hash_chain_status_per_receipt_including_tampered_row(): void
    {
        $path = sys_get_temp_dir().'/atlas-loop-migrate-history-'.bin2hex(random_bytes(4)).'.jsonl';
        $ledger = new AtlasLoopSchemaMigrationReceiptLedger($path);
        $ledger->append($this->receipt('atlas_loop_decision_receipts', 1, 2, 'pre-a', 'fp-a', 'pass', '2026-06-24T05:00:00+00:00', '2026-06-24T05:00:01+00:00', 'rcpt-a', 'success'));
        $ledger->append($this->receipt('atlas_loop_decision_receipts', 2, 3, 'pre-b', 'fp-b', 'pass', '2026-06-24T05:01:00+00:00', '2026-06-24T05:01:01+00:00', 'rcpt-b', 'success'));

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $row = json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR);
        $row['outcome'] = 'tampered';
        $lines[1] = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);

        app()->instance(AtlasLoopSchemaMigrationReceiptLedger::class, $ledger);

        $exit = Artisan::call('atlas:loop:migrate', [
            'action' => 'history',
            '--artifact' => 'atlas_loop_decision_receipts',
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertCount(2, $payload['payload']['receipts']);
        $this->assertTrue($payload['payload']['receipts'][0]['hash_chain_ok']);
        $this->assertFalse($payload['payload']['receipts'][1]['hash_chain_ok']);

        @unlink($path);
    }

    private function handshake(string $artifact, int $fromVersion, int $toVersion): string
    {
        $seed = strtolower(trim($artifact)).'|'.$fromVersion.'|'.$toVersion;
        $hex = substr(hash('sha256', $seed), 0, 32);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private function receipt(
        string $artifactKind,
        int $fromVersion,
        int $toVersion,
        string $preHash,
        string $tokenFingerprint,
        string $verifierResult,
        string $startedAt,
        string $finishedAt,
        string $evidenceReceiptId,
        string $outcome,
    ): AtlasLoopSchemaMigrationReceipt {
        return new AtlasLoopSchemaMigrationReceipt(
            artifactKind: $artifactKind,
            fromVersion: $fromVersion,
            toVersion: $toVersion,
            preHash: $preHash,
            postHash: null,
            operatorTokenFingerprint: $tokenFingerprint,
            verifierResult: $verifierResult,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            evidenceReceiptId: $evidenceReceiptId,
            outcome: $outcome,
        );
    }
}

final class FakeAtlasLoopMigrationRunner
{
    public int $approvalCalls = 0;

    public int $runCalls = 0;

    public function approvalTokenFor(string $artifactKind, int $fromVersion, int $toVersion): string
    {
        $this->approvalCalls++;

        return 'unused';
    }

    public function run(string $artifactKind, int $fromVersion, int $toVersion, string $snapshotPath, ?string $approvalToken): string
    {
        $this->runCalls++;

        return 'runner-ok';
    }
}
