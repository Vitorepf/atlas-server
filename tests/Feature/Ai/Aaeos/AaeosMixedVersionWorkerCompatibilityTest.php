<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Models\AiJob;
use App\Models\AiTrace;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiWorker;
use App\Services\Ai\Governance\AiPermissionDecision;
use App\Services\Ai\Governance\AiPermissionEngine;
use App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionSurfaceRegistry;
use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionReceiptHash;
use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Decision\DecisionReceiptRuntimeGuard;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesAiJobChoiceTables;
use Tests\TestCase;

/**
 * P2b rollout: V2 governs dual transport until independent V3 authority
 * context/revocation/budget enforcement exists; AWIS R102 mode identity.
 */
final class AaeosMixedVersionWorkerCompatibilityTest extends TestCase
{
    use CreatesAiJobChoiceTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAiJobChoiceTables();
    }

    protected function tearDown(): void
    {
        $this->dropAiJobChoiceTables();
        config([
            'atlas.ai.decision_receipt_v3_cutover_enabled' => false,
            'atlas.ai.decision_receipt_v3_canary_percent' => 0,
        ]);
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_writer_selection_keeps_v2_governing_and_blocks_v3_only_without_context(): void
    {
        config(['atlas.ai.decision_receipt_v3_cutover_enabled' => true]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-24T12:00:00Z'));
        $guard = new DecisionReceiptRuntimeGuard;

        $v2 = [
            'receipt_id' => 'cutover-v2',
            'envelope_id' => 'cutover-env',
            'schema_version' => DecisionReceipt::SCHEMA_VERSION,
            'expires_at' => '2026-07-24T12:05:00Z',
            'dry_run' => false,
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'provider_selection' => ['primary' => 'codex_cli', 'model' => 'gpt-5.5', 'fallbacks' => []],
        ];
        $this->assertNull($guard->violationForReceipt([DecisionReceipt::RECEIPT_V2_KEY => $v2]));

        $v3 = $this->alignedV3($v2);
        $this->assertNull($guard->violationForReceipt([
            DecisionReceipt::RECEIPT_V2_KEY => $v2,
            DecisionReceipt::RECEIPT_V3_KEY => $v3,
        ]));

        foreach ([true, false] as $writerCutoverEnabled) {
            config(['atlas.ai.decision_receipt_v3_cutover_enabled' => $writerCutoverEnabled]);
            $this->assertSame(
                'decision_receipt_v3_authority_context_unavailable',
                $guard->violationForReceipt([DecisionReceipt::RECEIPT_V3_KEY => $v3])?->errorCode,
            );
        }
    }

    public function test_r102_autonomos_surfaces_do_not_present_as_dev(): void
    {
        foreach ([
            'atlas_autonomos.task_serving',
            'atlas_autonomos.native_worker',
            'atlas_autonomos.commit_governance',
        ] as $id) {
            $surface = EngineeringExecutionSurfaceRegistry::surface($id);
            $this->assertNotNull($surface);
            $this->assertSame('autonomos', $surface['awis_mode'], $id.' must use autonomos mode');
            $this->assertNotSame('dev', $surface['awis_mode']);
        }

        $this->assertSame('dev', EngineeringExecutionSurfaceRegistry::surface('atlas_dev.pipeline_run_executor')['awis_mode'] ?? null);
        $this->assertSame('forge', EngineeringExecutionSurfaceRegistry::surface('atlas_forge.work_packet_execution_cycle')['awis_mode'] ?? null);
    }

    public function test_r102_unknown_mode_fails_closed_as_mutative_blocker(): void
    {
        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class);
        $result = $gate->gate(workspace: base_path(), mode: 'not-a-real-mode', task: 'cutover-r102');

        $this->assertFalse((bool) ($result['allowed'] ?? true));
        $this->assertContains('unknown_execution_mode', (array) ($result['blockers'] ?? []));
        $this->assertSame('mutative', $result['execution_class'] ?? null);
    }

    public function test_cutover_does_not_promote_a_preexisting_canary_companion_after_toggle(): void
    {
        config(['atlas.ai.decision_receipt_v3_cutover_enabled' => true]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-24T12:00:00Z'));
        $v2 = [
            'receipt_id' => 'canary-before-cutover-v2',
            'envelope_id' => 'canary-before-cutover-env',
            'schema_version' => DecisionReceipt::SCHEMA_VERSION,
            'expires_at' => '2026-07-24T12:05:00Z',
            'dry_run' => false,
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'provider_selection' => ['primary' => 'codex_cli', 'model' => 'gpt-5.5', 'fallbacks' => []],
        ];
        $canary = $this->alignedV3($v2);
        $canary['signed_by'] = 'atlas.decide.v3-canary';
        $canary['authority']['effect']['allowed'] = false;
        unset($canary['authority_signature']);
        $canary['receipt_hash'] = DecisionReceiptHash::v3FullEnvelopeHash($canary);

        $this->assertSame(
            'decision_receipt_v3_non_authoritative',
            (new DecisionReceiptRuntimeGuard)->violationForReceipt([
                DecisionReceipt::RECEIPT_V3_KEY => $canary,
            ])?->errorCode,
        );
    }

    public function test_worker_rechecks_expiry_at_the_provider_effect_boundary(): void
    {
        $issuedAt = CarbonImmutable::parse('2026-07-24T12:00:00Z');
        $expiresAt = $issuedAt->addSecond();
        CarbonImmutable::setTestNow($issuedAt);

        $envelope = app(OperationEnvelopeFactory::class)->create([
            'text' => 'recheck receipt immediately before provider effect',
        ]);
        $receipt = app(DecisionReceiptIssuer::class)->issue($envelope, [
            'receipt_id' => 'worker-effect-boundary-expiry',
            'issued_at' => $issuedAt->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'provider_selection' => ['primary' => 'codex_cli', 'model' => 'gpt-5.5', 'fallbacks' => []],
        ])->toArray();
        $transport = [DecisionReceipt::RECEIPT_V2_KEY => $receipt];
        $trace = AiTrace::query()->create([
            'trace_key' => 'trace-effect-boundary-expiry',
            'source_type' => 'app',
            'status' => 'queued',
            'agent_slug' => 'worker-test',
            'operator_input' => 'do not cross expiry boundary',
            'metadata' => ['decision_receipt' => $transport],
        ]);
        $job = AiJob::query()->create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'worker-test',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'do not cross expiry boundary',
            'prompt' => 'provider must not run after expiry',
            'available_at' => now()->subSecond(),
            'max_attempts' => 1,
            'payload' => ['decision_receipt' => $transport],
            'metadata' => ['decision_receipt' => $transport],
        ]);

        $providers = $this->createMock(AiProviderManager::class);
        $providers->expects($this->never())->method('get');
        $this->app->instance(AiProviderManager::class, $providers);

        $permission = new AiPermissionDecision(
            allowed: true,
            mode: 'read',
            workspace: base_path(),
            codexSandbox: 'read-only',
            capabilities: ['read_files'],
            reasons: ['advance clock within the existing permission seam'],
        );
        $permissions = $this->createMock(AiPermissionEngine::class);
        $permissions->expects($this->once())
            ->method('authorizeJob')
            ->willReturnCallback(function () use ($expiresAt, $permission): AiPermissionDecision {
                CarbonImmutable::setTestNow($expiresAt);

                return $permission;
            });
        $this->app->instance(AiPermissionEngine::class, $permissions);

        app(AiWorker::class)->runNext(workerId: 'worker-effect-boundary');

        $persisted = $job->fresh();
        self::assertSame('failed', $persisted->status);
        self::assertSame('decision_receipt_expired', $persisted->error_code);
        self::assertSame('decision_receipt_expired', $persisted->attemptHistory()->first()?->error_code);
        self::assertSame($transport, data_get($persisted, 'metadata.decision_receipt'));
        self::assertSame($transport, data_get($persisted, 'payload.decision_receipt'));
        self::assertSame($transport, data_get($trace->fresh(), 'metadata.decision_receipt'));
    }

    public function test_worker_rechecks_expiry_after_provider_resolution_before_provider_called(): void
    {
        $issuedAt = CarbonImmutable::parse('2026-07-24T12:00:00Z');
        $expiresAt = $issuedAt->addSecond();
        CarbonImmutable::setTestNow($issuedAt);

        $envelope = app(OperationEnvelopeFactory::class)->create([
            'text' => 'recheck receipt after provider resolution',
        ]);
        $receipt = app(DecisionReceiptIssuer::class)->issue($envelope, [
            'receipt_id' => 'worker-provider-resolution-expiry',
            'issued_at' => $issuedAt->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'provider_selection' => ['primary' => 'codex_cli', 'model' => 'gpt-5.5', 'fallbacks' => []],
        ])->toArray();
        $transport = [DecisionReceipt::RECEIPT_V2_KEY => $receipt];
        $trace = AiTrace::query()->create([
            'trace_key' => 'trace-provider-resolution-expiry',
            'source_type' => 'app',
            'status' => 'queued',
            'agent_slug' => 'worker-test',
            'operator_input' => 'do not cross provider-resolution expiry boundary',
            'metadata' => ['decision_receipt' => $transport],
        ]);
        $job = AiJob::query()->create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'worker-test',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'do not cross provider-resolution expiry boundary',
            'prompt' => 'provider must not run after resolution crosses expiry',
            'available_at' => now()->subSecond(),
            'max_attempts' => 1,
            'payload' => ['decision_receipt' => $transport],
            'metadata' => ['decision_receipt' => $transport],
        ]);

        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->never())->method('runStreaming');
        $providers = $this->createMock(AiProviderManager::class);
        $providers->expects($this->once())
            ->method('get')
            ->with('codex_cli')
            ->willReturnCallback(function () use ($expiresAt, $provider): AiProvider {
                CarbonImmutable::setTestNow($expiresAt);

                return $provider;
            });
        $this->app->instance(AiProviderManager::class, $providers);

        $permission = new AiPermissionDecision(
            allowed: true,
            mode: 'read',
            workspace: base_path(),
            codexSandbox: 'read-only',
            capabilities: ['read_files'],
            reasons: ['provider resolution advances the clock'],
        );
        $permissions = $this->createMock(AiPermissionEngine::class);
        $permissions->expects($this->once())
            ->method('authorizeJob')
            ->willReturn($permission);
        $this->app->instance(AiPermissionEngine::class, $permissions);

        $ledgerEventTypes = [];
        $ledger = $this->createMock(AtlasEvidenceLedger::class);
        $ledger->method('record')->willReturnCallback(
            function (LedgerEventType $type) use (&$ledgerEventTypes): null {
                $ledgerEventTypes[] = $type->value;

                return null;
            },
        );
        $this->app->instance(AtlasEvidenceLedger::class, $ledger);

        app(AiWorker::class)->runNext(workerId: 'worker-provider-resolution-boundary');

        $persisted = $job->fresh();
        self::assertSame('failed', $persisted->status);
        self::assertSame('decision_receipt_expired', $persisted->error_code);
        self::assertSame('decision_receipt_expired', $persisted->attemptHistory()->first()?->error_code);
        self::assertNotContains(LedgerEventType::ProviderCalled->value, $ledgerEventTypes);
        self::assertSame($transport, data_get($persisted, 'metadata.decision_receipt'));
        self::assertSame($transport, data_get($persisted, 'payload.decision_receipt'));
        self::assertSame($transport, data_get($trace->fresh(), 'metadata.decision_receipt'));
    }

    /** @param array<string,mixed> $v2 @return array<string,mixed> */
    private function alignedV3(array $v2): array
    {
        $receipt = [
            'receipt_id' => $v2['receipt_id'],
            'envelope_id' => $v2['envelope_id'],
            'schema_version' => DecisionReceipt::SCHEMA_VERSION_V3,
            'issued_at' => '2026-07-24T11:59:00Z',
            'expires_at' => $v2['expires_at'],
            'dry_run' => false,
            'signed_by' => 'atlas.decide.v3-cutover',
            'domain' => $v2['domain'],
            'flow' => $v2['flow'],
            'risk' => 'high',
            'provider_selection' => $v2['provider_selection'],
            'budgets' => ['provider_calls' => 1],
            'required_gates' => ['authority'],
            'required_evidence' => ['receipt'],
            'repair_policy' => ['enabled' => false, 'max_attempts' => 0],
            'inputs_hash' => str_repeat('b', 64),
            'parent_receipt_id' => null,
            'chain_hash' => str_repeat('c', 64),
            'authority' => [
                'authority_id' => 'mandate-cutover',
                'issuer_key_id' => 'key-cutover',
                'lifecycle' => ['status' => 'active', 'revision' => 1],
                'audience' => ['tenant_id' => 'tenant-cutover', 'principal_id' => 'principal-cutover'],
                'scope' => ['workspace_id' => 'workspace-cutover', 'modes' => ['dev'], 'capability' => 'programming.dev'],
                'effect' => ['class' => 'provider_tool_sandbox_mutation', 'allowed' => true],
                'budget' => ['budget_id' => 'budget-cutover', 'max_effects' => 1],
                'nonce' => 'nonce-cutover',
                'revocation_head' => str_repeat('a', 64),
                'separation_of_duties' => [
                    'issuer_principal_id' => 'issuer-cutover',
                    'executor_principal_id' => 'executor-cutover',
                ],
            ],
        ];
        $receipt['authority_signature'] = DecisionReceiptHash::v3LiveAuthoritySignature($receipt);
        $receipt['receipt_hash'] = DecisionReceiptHash::v3FullEnvelopeHash($receipt);

        return $receipt;
    }
}
