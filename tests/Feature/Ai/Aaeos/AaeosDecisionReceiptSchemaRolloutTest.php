<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionReceiptHash;
use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Decision\DecisionReceiptRuntimeGuard;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class AaeosDecisionReceiptSchemaRolloutTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_expand_keeps_legacy_v2_governing_and_blocks_v3_only_authority(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-24T12:00:00Z'));
        $envelope = app(OperationEnvelopeFactory::class)->create(['text' => 'verify expand receipt rollout']);
        $v2 = app(DecisionReceiptIssuer::class)->issue($envelope, [
            'receipt_id' => 'v2-governing-expand',
            'issued_at' => '2026-07-24T11:59:00Z',
            'expires_at' => '2026-07-24T12:01:00Z',
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'provider_selection' => ['primary' => 'codex_cli', 'model' => 'gpt-5.5', 'fallbacks' => []],
        ])->toArray();
        $v3 = $this->validV3Receipt();
        $guard = new DecisionReceiptRuntimeGuard;

        self::assertSame(DecisionReceipt::SCHEMA_VERSION, $v2['schema_version']);
        self::assertArrayNotHasKey(DecisionReceipt::RECEIPT_V3_KEY, $v2);
        self::assertNull($guard->violationForReceipt([
            DecisionReceipt::RECEIPT_V2_KEY => $v2,
            DecisionReceipt::RECEIPT_V3_KEY => $v3,
        ], runtimeProvider: 'codex_cli', runtimeModel: 'gpt-5.5'));
        self::assertSame(
            'decision_receipt_v3_non_authoritative',
            $guard->violationForReceipt([DecisionReceipt::RECEIPT_V3_KEY => $v3])?->errorCode,
        );
    }

    /** @return array<string,mixed> */
    private function validV3Receipt(): array
    {
        $receipt = [
            'receipt_id' => 'v3-observed-expand',
            'envelope_id' => 'envelope-v3-observed',
            'schema_version' => DecisionReceipt::SCHEMA_VERSION_V3,
            'issued_at' => '2026-07-24T11:59:00Z',
            'expires_at' => '2026-07-24T12:01:00Z',
            'dry_run' => false,
            'signed_by' => 'atlas.decide.v3',
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'risk' => 'high',
            'provider_selection' => ['primary' => 'codex_cli', 'model' => 'gpt-5.5', 'fallbacks' => []],
            'budgets' => ['provider_calls' => 1],
            'required_gates' => ['authority'],
            'required_evidence' => ['receipt'],
            'repair_policy' => ['enabled' => false, 'max_attempts' => 0],
            'inputs_hash' => str_repeat('b', 64),
            'parent_receipt_id' => null,
            'chain_hash' => str_repeat('c', 64),
            'authority' => [
                'authority_id' => 'mandate-expand',
                'issuer_key_id' => 'key-expand',
                'lifecycle' => ['status' => 'active', 'revision' => 1],
                'audience' => ['tenant_id' => 'tenant-expand', 'principal_id' => 'principal-expand'],
                'scope' => ['workspace_id' => 'workspace-expand', 'modes' => ['dev'], 'capability' => 'programming.dev'],
                'effect' => ['class' => 'provider_tool_sandbox_mutation', 'allowed' => true],
                'budget' => ['budget_id' => 'budget-expand', 'max_effects' => 1],
                'nonce' => 'nonce-expand',
                'revocation_head' => str_repeat('a', 64),
                'separation_of_duties' => [
                    'issuer_principal_id' => 'issuer-expand',
                    'executor_principal_id' => 'executor-expand',
                ],
            ],
        ];
        $receipt['receipt_hash'] = DecisionReceiptHash::v3FullEnvelopeHash($receipt);

        return $receipt;
    }
}
