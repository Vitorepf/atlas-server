<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Kernel;

use App\Services\Ai\Kernel\Architecture\KernelArchitectureStaticScanner;
use App\Services\Ai\Kernel\Decision\DecisionReceiptHash;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class KernelTriadF0CharacterizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_scanner_public_compliance_executes_and_freezes_166_architecture_check_keys(): void
    {
        $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
        $keys = array_values(array_filter(array_keys($report), static fn (string $key): bool => $key !== 'ok'));

        self::assertCount(166, $keys);
        self::assertSame('c82ec2c090095aa812e2c808066276d794c672a50427b4b84ab04337dc9aa085', hash('sha256', json_encode($keys, JSON_THROW_ON_ERROR)));
        self::assertSame(['ap1_surface_provider_bypass', 'ap2_surface_context_bypass', 'ap6_decision_receipt_propagation'], array_slice($keys, 0, 3));
        self::assertSame(['ap169_personal_worked_example_privacy_contract', 'ap170_predictive_failure_governance_contract', 'ap201_runtime_language_boundary_contract'], array_slice($keys, -3));
    }

    public function test_evidence_ledger_public_api_snapshot_also_executes_its_real_write_and_replay_path(): void
    {
        $reflection = new \ReflectionClass(AtlasEvidenceLedger::class);
        $methods = array_map(static fn (\ReflectionMethod $method): string => $method->getName(), $reflection->getMethods(\ReflectionMethod::IS_PUBLIC));
        sort($methods);
        $constructor = $reflection->getConstructor();

        self::assertSame([
            '__construct', 'computeEventHash', 'engineeringOutcomeEvent', 'eventById', 'eventIntegrityValid', 'eventsForCorrelation', 'eventsForEnvelope', 'eventsForScope', 'latestForCorrelation', 'latestForScope', 'record', 'recordAgentBehaviorGateEvaluation', 'recordDecisionIssued', 'recordEnvelopeCreated', 'recordFailure', 'recordKernelPipelineAccepted', 'recordKernelPipelineRejected', 'recordLocalRagEvent', 'recordPolicyContractBlocked', 'recordProviderMemoryBlocked', 'recordRepairDecision', 'recordRepairResult', 'recordSloObservation', 'recordVoiceEvent',
        ], $methods);
        self::assertNotNull($constructor);
        self::assertSame(['failureClassifier', 'failureHandlers'], array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters()));
        self::assertSame([
            'App\\Services\\Ai\\Kernel\\Failure\\FailureClassifier',
            'App\\Services\\Ai\\Kernel\\Failure\\FailureHandlerRegistry',
        ], array_map(static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(), $constructor->getParameters()));

        $ledger = app(AtlasEvidenceLedger::class);
        $firstReceipt = $this->decisionReceipt('receipt-kernel-f0-1', null, null);
        $first = $ledger->recordDecisionIssued($firstReceipt, ['tenant_id' => 'tenant-kernel-f0', 'operator_id' => 'operator-kernel-f0']);
        $secondReceipt = $this->decisionReceipt('receipt-kernel-f0-2', 'receipt-kernel-f0-1', $firstReceipt['chain_hash']);
        $second = $ledger->recordDecisionIssued($secondReceipt, ['tenant_id' => 'tenant-kernel-f0', 'operator_id' => 'operator-kernel-f0']);
        $replay = app(AtlasLedgerReplayService::class)->decisionReceiptReportForEnvelope('env-kernel-triad-f0');

        self::assertNotNull($first['decision_issued']);
        self::assertNotNull($second['decision_issued']);
        self::assertTrue($ledger->eventIntegrityValid($first['decision_issued']));
        self::assertTrue($ledger->eventIntegrityValid($second['decision_issued']));
        self::assertSame(2, $replay['decision_event_count']);
        self::assertSame(2, $replay['valid_receipt_hash_count']);
        self::assertSame(2, $replay['valid_chain_hash_count']);
        self::assertSame(0, $replay['invalid_count']);
        self::assertSame('ok', data_get($replay, 'review_signal.status'));
        self::assertSame($secondReceipt['chain_hash'], $replay['latest_chain_hash']);
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionReceipt(string $receiptId, ?string $parentReceiptId, ?string $parentChainHash): array
    {
        $receipt = [
            'receipt_id' => $receiptId,
            'envelope_id' => 'env-kernel-triad-f0',
            'schema_version' => 'atlas.decide.v2',
            'issued_at' => '2026-07-22T19:00:00.000000Z',
            'expires_at' => '2026-07-22T19:01:00.000000Z',
            'dry_run' => false,
            'signed_by' => 'atlas.decide.v2',
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'risk' => 'medium',
            'provider_selection' => ['primary' => 'codex_cli', 'model' => 'gpt-5.5', 'fallbacks' => []],
            'budgets' => [],
            'required_gates' => ['tests'],
            'required_evidence' => ['summary'],
            'repair_policy' => ['enabled' => false, 'max_attempts' => 0],
            'inputs_hash' => hash('sha256', 'inputs-'.$receiptId),
            'parent_receipt_id' => $parentReceiptId,
            'parent_chain_hash' => $parentChainHash,
        ];
        $receipt['receipt_hash'] = DecisionReceiptHash::hash([
            'receipt_id' => $receipt['receipt_id'],
            'envelope_id' => $receipt['envelope_id'],
            'schema_version' => $receipt['schema_version'],
            'issued_at' => $receipt['issued_at'],
            'expires_at' => $receipt['expires_at'],
            'dry_run' => $receipt['dry_run'],
            'signed_by' => $receipt['signed_by'],
            'inputs_hash' => $receipt['inputs_hash'],
            'parent_receipt_id' => $receipt['parent_receipt_id'],
        ]);
        $receipt['chain_hash'] = DecisionReceiptHash::hash([
            'parent_chain_hash' => $receipt['parent_chain_hash'],
            'receipt_hash' => $receipt['receipt_hash'],
        ]);

        return $receipt;
    }
}
