<?php

namespace Tests\Unit\Ai\Kernel;

use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class DecisionReceiptIssuerTest extends TestCase
{
    public function test_issuer_creates_receipt_with_model_selection_policy_and_hashes(): void
    {
        $envelope = app(OperationEnvelopeFactory::class)->create(['text' => 'corrija esse bug']);
        $receipt = app(DecisionReceiptIssuer::class)->issue($envelope, [
            'receipt_id' => 'receipt-1',
            'issued_at' => '2026-05-05T10:00:00Z',
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'risk' => 'medium',
            'provider_selection' => [
                'primary' => 'codex',
                'model' => 'selected-by-decide',
                'fallbacks' => ['claude'],
                'selection_mode' => 'auto_best_allowed',
                'selection_reason' => 'best code-editing fit inside current policy',
            ],
            'required_evidence' => ['diff_summary', 'tests_or_reason'],
            'repair_policy' => ['enabled' => true, 'max_attempts' => 2],
        ]);

        $this->assertSame(DecisionReceipt::SCHEMA_VERSION, $receipt->schemaVersion);
        $this->assertSame($envelope->envelopeId, $receipt->envelopeId);
        $this->assertSame('programming', $receipt->domain);
        $this->assertSame('programming.dev', $receipt->flow);
        $this->assertSame('auto_best_allowed', $receipt->providerSelection['selection_mode']);
        $this->assertSame(['claude'], $receipt->providerSelection['fallbacks']);
        $this->assertFalse($receipt->dryRun);
        $this->assertSame('atlas.decide.v2', $receipt->signedBy);
        $this->assertFalse($receipt->isExpired(CarbonImmutable::parse('2026-05-05T10:00:10Z')));
        $this->assertTrue($receipt->isExpired(CarbonImmutable::parse('2026-05-05T10:00:31Z')));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt->inputsHash);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt->receiptHash);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt->chainHash);
    }

    public function test_manual_model_override_is_preserved_as_audited_exception(): void
    {
        $envelope = app(OperationEnvelopeFactory::class)->create(['text' => 'use claude aqui']);
        $receipt = app(DecisionReceiptIssuer::class)->issue($envelope, [
            'provider_selection' => [
                'primary' => 'claude',
                'model' => 'opus',
                'selection_mode' => 'manual_override',
                'manual_override' => [
                    'requested_provider' => 'claude',
                    'requested_model' => 'opus',
                    'accepted' => true,
                    'reason' => 'operator requested a specific engine for this run',
                ],
            ],
        ]);

        $this->assertSame('manual_override', $receipt->providerSelection['selection_mode']);
        $this->assertSame('claude', $receipt->providerSelection['manual_override']['requested_provider']);
        $this->assertSame('opus', $receipt->providerSelection['manual_override']['requested_model']);
    }

    public function test_same_inputs_and_fixed_receipt_metadata_produce_same_hashes(): void
    {
        $envelope = app(OperationEnvelopeFactory::class)->create(['text' => 'mesma decisao']);
        $issuer = app(DecisionReceiptIssuer::class);
        $decision = [
            'receipt_id' => 'receipt-fixed',
            'issued_at' => '2026-05-05T10:00:00Z',
            'expires_at' => '2026-05-05T10:00:30Z',
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'provider_selection' => ['selection_mode' => 'auto_best_allowed'],
        ];

        $first = $issuer->issue($envelope, $decision);
        $second = $issuer->issue($envelope, $decision);

        $this->assertSame($first->inputsHash, $second->inputsHash);
        $this->assertSame($first->receiptHash, $second->receiptHash);
        $this->assertSame($first->chainHash, $second->chainHash);
    }

    public function test_nested_decision_payload_order_does_not_change_hashes(): void
    {
        $envelope = app(OperationEnvelopeFactory::class)->create(['text' => 'mesma decisao']);
        $issuer = app(DecisionReceiptIssuer::class);
        $base = [
            'receipt_id' => 'receipt-fixed',
            'issued_at' => '2026-05-05T10:00:00Z',
            'expires_at' => '2026-05-05T10:00:30Z',
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'budgets' => [
                'outer' => ['z' => 1, 'a' => 2],
            ],
        ];

        $first = $issuer->issue($envelope, $base);
        $second = $issuer->issue($envelope, array_merge($base, [
            'budgets' => [
                'outer' => ['a' => 2, 'z' => 1],
            ],
        ]));

        $this->assertSame($first->inputsHash, $second->inputsHash);
        $this->assertSame($first->receiptHash, $second->receiptHash);
    }
}
