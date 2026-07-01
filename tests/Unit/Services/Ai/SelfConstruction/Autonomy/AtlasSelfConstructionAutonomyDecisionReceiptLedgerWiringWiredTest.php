<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Autonomy;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves AtlasSelfConstructionAutonomyDecisionReceiptLedger is no longer an orphan: it is invoked
 * from atlas:self-construction:autonomy-level's promote/degrade verbs, sealing a pure decision
 * receipt alongside the mutable runtime-ledger event for every promotion/degradation decision.
 */
final class AtlasSelfConstructionAutonomyDecisionReceiptLedgerWiringWiredTest extends TestCase
{
    private string $factsPath = '';

    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->factsPath = sys_get_temp_dir().'/atlas_autonomy_receipt_facts_'.bin2hex(random_bytes(6)).'.json';
        $this->ledgerPath = sys_get_temp_dir().'/atlas_autonomy_receipt_ledger_'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->factsPath);
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function writeJson(array $data): void
    {
        file_put_contents($this->factsPath, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    public function test_promote_action_seals_a_decision_receipt_hash(): void
    {
        $this->writeJson([
            'from_level' => 'bootstrap',
            'to_level' => 'assisted',
            'facts' => ['queue_health_green' => true],
            'ledger_path' => $this->ledgerPath,
            'lane' => 'lane-receipt',
            'created_at_unix' => 1700000400,
        ]);

        Artisan::call('atlas:self-construction:autonomy-level', [
            'action' => 'promote',
            '--facts' => $this->factsPath,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $payload['status']);
        $this->assertArrayHasKey('decision_receipt_hash', $payload);
        $this->assertNotEmpty($payload['decision_receipt_hash']);
        $this->assertSame(64, strlen((string) $payload['decision_receipt_hash']));
    }

    public function test_degrade_action_seals_a_decision_receipt_hash(): void
    {
        $this->writeJson([
            'level' => 'assisted',
            'facts' => ['false_green_detected' => true],
            'ledger_path' => $this->ledgerPath,
            'lane' => 'lane-receipt',
            'created_at_unix' => 1700000500,
        ]);

        Artisan::call('atlas:self-construction:autonomy-level', [
            'action' => 'degrade',
            '--facts' => $this->factsPath,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $payload['status']);
        $this->assertArrayHasKey('decision_receipt_hash', $payload);
        $this->assertNotEmpty($payload['decision_receipt_hash']);
        $this->assertSame(64, strlen((string) $payload['decision_receipt_hash']));
    }

    public function test_receipt_hash_is_deterministic_for_identical_promote_facts(): void
    {
        $this->writeJson([
            'from_level' => 'bootstrap',
            'to_level' => 'assisted',
            'facts' => ['queue_health_green' => true],
            'ledger_path' => $this->ledgerPath,
            'lane' => 'lane-receipt',
            'created_at_unix' => 1700000400,
        ]);

        Artisan::call('atlas:self-construction:autonomy-level', [
            'action' => 'promote',
            '--facts' => $this->factsPath,
            '--json' => true,
        ]);
        $first = json_decode(trim(Artisan::output()), true)['decision_receipt_hash'];

        Artisan::call('atlas:self-construction:autonomy-level', [
            'action' => 'promote',
            '--facts' => $this->factsPath,
            '--json' => true,
        ]);
        $second = json_decode(trim(Artisan::output()), true)['decision_receipt_hash'];

        $this->assertSame($first, $second);
    }
}
